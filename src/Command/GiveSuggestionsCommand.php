<?php

declare(strict_types=1);

namespace DrupalIssueHelper\Command;

use DrupalIssueHelper\Command\Traits\ProjectAwareTrait;
use DrupalIssueHelper\Service\AIService;
use DrupalIssueHelper\Service\IssueChecker;
use DrupalIssueHelper\State\StateHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'give-suggestions',
    description: 'Analyze issues and generate suggestions for improvements'
)]
class GiveSuggestionsCommand extends Command
{
    use ProjectAwareTrait;

    private StateHandler $stateHandler;
    private IssueChecker $issueChecker;

    protected function configure(): void
    {
        $this
            ->addOption('project', 'p', InputOption::VALUE_OPTIONAL, 'Project name (from .env DRUPAL_PROJECT_IDS)')
            ->addOption('issue', 'i', InputOption::VALUE_OPTIONAL, 'Specific issue ID to check')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Check all issues (ignore last suggestions run)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of issues to check');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $aiService = new AIService();
        $this->stateHandler = new StateHandler(dirname(__DIR__, 2));
        $this->issueChecker = new IssueChecker($aiService);

        // Check AI availability
        if (!$aiService->isAvailable()) {
            $io->warning('OpenAI API key not configured. AI-based checks will be skipped.');
        }

        // Resolve project
        $project = $this->resolveProject($input, $io, $input->getOption('project'));
        if ($project === null) {
            return Command::FAILURE;
        }

        $projectName = $project['name'];
        $projectId = $project['id'];

        $io->title("Generating suggestions for project: $projectName");

        // Get global state for last suggestions run
        $globalState = $this->stateHandler->getGlobalState($projectId);
        $lastSuggestionsRun = $globalState['last_suggestions_run'] ?? null;
        $checkAll = $input->getOption('all');
        $specificIssue = $input->getOption('issue');
        $limit = $input->getOption('limit') ? (int) $input->getOption('limit') : null;

        // Get issues to check
        $issues = $this->getIssuesToCheck($projectId, $lastSuggestionsRun, $checkAll, $specificIssue);

        if (empty($issues)) {
            $io->success('No issues to check.');
            return Command::SUCCESS;
        }

        if ($limit !== null) {
            $issues = array_slice($issues, 0, $limit, true); // preserve_keys
        }

        $issueCount = count($issues);

        if (!$checkAll && $lastSuggestionsRun) {
            $io->text("Last suggestions run: " . date('Y-m-d H:i:s', $lastSuggestionsRun));
        }

        $io->text("Found $issueCount issues to analyze");
        $io->newLine();

        $io->section('Analyzing issues');

        $suggestionsCreated = 0;
        $issuesChecked = 0;

        foreach ($issues as $issueId => $issue) {
            $issueId = (string) $issueId;
            $issueTitle = $issue['title'] ?? 'Unknown';
            $shortTitle = strlen($issueTitle) > 50 ? substr($issueTitle, 0, 47) . '...' : $issueTitle;

            $io->write("  Checking #$issueId: $shortTitle... ");

            // Run checks
            $suggestion = $this->issueChecker->checkIssue($issue);

            if ($suggestion !== null) {
                // Save suggestion
                $this->stateHandler->saveIssueSuggestion($projectId, $issueId, $suggestion);
                $io->writeln('<fg=yellow>suggestion created</>');
                $io->writeln("    -> " . $suggestion['type'] . ": " . $suggestion['reason']);
                $suggestionsCreated++;
            } else {
                $io->writeln('<fg=green>OK</>');
            }

            $issuesChecked++;
        }

        // Update global state with last suggestions run time
        $globalState['last_suggestions_run'] = time();
        $this->stateHandler->saveGlobalState($projectId, $globalState);

        $io->newLine();
        $io->section('Summary');
        $io->definitionList(
            ['Issues checked' => $issuesChecked],
            ['Suggestions created' => $suggestionsCreated]
        );

        if ($suggestionsCreated > 0) {
            $io->success("Created $suggestionsCreated suggestion(s). Run 'check-issues' to review them.");
        } else {
            $io->success('No issues found that need attention.');
        }

        return Command::SUCCESS;
    }

    private function getIssuesToCheck(string $projectId, ?int $lastRun, bool $checkAll, ?string $specificIssue): array
    {
        $issues = [];

        if ($specificIssue !== null) {
            // Load specific issue
            $content = $this->stateHandler->getIssueState($projectId, $specificIssue);
            if ($content) {
                $issues[$specificIssue] = $content;
            }
            return $issues;
        }

        // Get issues based on whether we're checking all or only updated ones
        if ($checkAll || $lastRun === null) {
            $allIssues = $this->stateHandler->getAllIssueStates($projectId);
        } else {
            // Only get issues updated after last suggestions run (filtered by filename timestamp)
            $allIssues = $this->stateHandler->getIssueStatesUpdatedAfter($projectId, $lastRun);
        }

        // Filter out issues with pending suggestions or already checked
        foreach ($allIssues as $issueId => $content) {
            // Check if already has a pending suggestion
            $existingSuggestion = $this->stateHandler->getIssueSuggestion($projectId, $issueId);
            if ($existingSuggestion !== null) {
                continue; // Skip - already has pending suggestion
            }

            // Check if already marked as checked
            if ($this->stateHandler->isIssueChecked($projectId, $issueId)) {
                // Only re-check if state was updated after being checked
                $checkedFile = $this->stateHandler->getProjectDir($projectId) . '/issues_checked/' . $issueId . '.json';
                if (file_exists($checkedFile)) {
                    $checkedData = json_decode(file_get_contents($checkedFile), true);
                    $checkedAt = isset($checkedData['checked_at']) ? strtotime($checkedData['checked_at']) : 0;
                    $stateUpdatedAt = $content['state_updated_at'] ?? 0;

                    if ($stateUpdatedAt <= $checkedAt && !$checkAll) {
                        continue; // Skip - checked and state not updated since
                    }
                }
            }

            $issues[$issueId] = $content;
        }

        // Sort by state_updated_at (most recent first)
        uasort($issues, function ($a, $b) {
            $aTime = $a['state_updated_at'] ?? 0;
            $bTime = $b['state_updated_at'] ?? 0;
            return $bTime - $aTime;
        });

        return $issues;
    }
}
