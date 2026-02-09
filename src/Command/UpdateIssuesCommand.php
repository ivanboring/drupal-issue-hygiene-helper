<?php

declare(strict_types=1);

namespace DrupalIssueHelper\Command;

use DrupalIssueHelper\Command\Traits\ProjectAwareTrait;
use DrupalIssueHelper\Service\DrupalApiService;
use DrupalIssueHelper\State\StateHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'update-issues',
    description: 'Fetch and update issues from the Drupal issue queue'
)]
class UpdateIssuesCommand extends Command
{
    use ProjectAwareTrait;

    private DrupalApiService $apiService;
    private StateHandler $stateHandler;

    protected function configure(): void
    {
        $this
            ->addOption('project', 'p', InputOption::VALUE_OPTIONAL, 'Project name (from .env DRUPAL_PROJECT_IDS)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Limit number of issues to fetch')
            ->addOption('all', 'a', InputOption::VALUE_NONE, 'Fetch all issues (ignore last checked timestamp)')
            ->addOption('no-scrape', null, InputOption::VALUE_NONE, 'Skip scraping issue pages for MR/CI status')
            ->addOption('give-suggestions', 's', InputOption::VALUE_NONE, 'Also run suggestions after updating');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $this->apiService = new DrupalApiService();
        $this->stateHandler = new StateHandler(dirname(__DIR__, 2));

        // Resolve project (interactive if not provided)
        $project = $this->resolveProject($input, $io, $input->getOption('project'));
        if ($project === null) {
            return Command::FAILURE;
        }

        $projectName = $project['name'];
        $projectId = $project['id'];

        // Resolve limit (interactive if not provided)
        $limit = $this->resolveLimit($input, $io);

        // Scraping is enabled by default, can be disabled with --no-scrape
        $shouldScrape = !$input->getOption('no-scrape');

        $io->title("Updating issues for project: $projectName (ID: $projectId)");

        $globalState = $this->stateHandler->getGlobalState($projectId);
        $lastChecked = $globalState['last_checked'] ?? null;

        // If --all flag is set, ignore last checked timestamp
        $fetchAll = $input->getOption('all');
        if ($fetchAll) {
            $lastChecked = null;
        }

        $io->section('Fetching issues from Drupal.org API');

        if ($fetchAll) {
            $io->text("Fetching all issues (--all flag set)");
        } elseif ($lastChecked) {
            $io->text("Last checked: " . date('Y-m-d H:i:s', $lastChecked));
        } else {
            $io->text("First run - fetching all recent issues");
        }

        if ($limit) {
            $io->text("Limit: $limit issues");
        }

        if (!$shouldScrape) {
            $io->text("Scraping: disabled (--no-scrape flag set)");
        }

        try {
            $issues = $this->apiService->fetchProjectIssues($projectId, $lastChecked, $limit);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $issueCount = count($issues);
        $io->text("Found $issueCount issues to process");

        if ($issueCount === 0) {
            $io->success('No new or updated issues found.');
            return Command::SUCCESS;
        }

        $io->section('Processing issues');
        $io->progressStart($issueCount);

        $processed = 0;
        $skipped = 0;
        $stored = 0;

        foreach ($issues as $issue) {
            $issueId = (string) $issue['nid'];
            $status = (int) ($issue['field_issue_status'] ?? 0);

            // Skip issues that are not in active statuses
            if (!$this->apiService->isActiveStatus($status)) {
                $skipped++;
                $io->progressAdvance();
                continue;
            }

            // Get existing state to compare
            $existingState = $this->stateHandler->getIssueState($projectId, $issueId);

            // Get the changed timestamp from the issue
            $changedTimestamp = isset($issue['changed']) ? (int) $issue['changed'] : time();

            // Build issue data - state_updated_at matches the changed timestamp
            $issueData = [
                'state_updated_at' => $changedTimestamp,
                'nid' => $issueId,
                'title' => $issue['title'] ?? '',
                'status' => $status,
                'status_name' => $this->apiService->getStatusName($status),
                'type' => $issue['type'] ?? '',
                'created' => $issue['created'] ?? null,
                'changed' => $issue['changed'] ?? null,
                'url' => $issue['url'] ?? "https://www.drupal.org/node/$issueId",
                'field_issue_category' => $issue['field_issue_category'] ?? null,
                'field_issue_priority' => $issue['field_issue_priority'] ?? null,
                'field_issue_component' => $issue['field_issue_component'] ?? null,
                'field_issue_version' => $issue['field_issue_version'] ?? null,
                'body' => $issue['body']['value'] ?? '',
            ];

            // Track if status changed
            if ($existingState !== null && isset($existingState['status'])) {
                $issueData['previous_status'] = $existingState['status'];
                $issueData['previous_status_name'] = $this->apiService->getStatusName($existingState['status']);
                $issueData['status_changed'] = ($existingState['status'] !== $status);
            } else {
                $issueData['status_changed'] = false;
            }

            // Always scrape the issue page for comments and additional details
            if ($shouldScrape) {
                $io->progressAdvance(0);
                $io->write(" <comment>[Scraping #$issueId]</comment>");

                try {
                    $scrapedData = $this->apiService->scrapeIssuePage($issueId);
                    if ($scrapedData) {
                        $issueData['has_merge_request'] = $scrapedData['has_merge_request'] ?? false;
                        $issueData['has_patch'] = $scrapedData['has_patch'] ?? false;
                        $issueData['mr_status'] = $scrapedData['mr_status'] ?? null;
                        $issueData['ci_status'] = $scrapedData['ci_status'] ?? null;
                    }
                } catch (\Exception $e) {
                    $issueData['scrape_error'] = $e->getMessage();
                }

                // Scrape all comments from the page
                try {
                    $scrapedComments = $this->apiService->scrapeAllCommentsFromPage($issueId);
                    $issueData['comments'] = $scrapedComments;
                } catch (\Exception $e) {
                    $issueData['comments'] = [];
                    $issueData['comment_scrape_error'] = $e->getMessage();
                }
            } else {
                // Fallback to API if scraping is disabled
                try {
                    $issueData['comments'] = $this->apiService->fetchIssueComments($issueId);
                } catch (\Exception $e) {
                    $issueData['comments'] = [];
                    $issueData['comment_fetch_error'] = $e->getMessage();
                }
            }

            // Save issue state with the changed timestamp for filename
            $this->stateHandler->saveIssueState($projectId, $issueId, $issueData, $changedTimestamp);
            $stored++;
            $processed++;

            $io->progressAdvance();
        }

        $io->progressFinish();

        // Update global state
        $this->stateHandler->saveGlobalState($projectId, [
            'last_checked' => time(),
            'issues_count' => $stored,
            'last_run' => date('c'),
        ]);

        $io->section('Summary');
        $io->definitionList(
            ['Processed' => $processed],
            ['Stored' => $stored],
            ['Skipped (closed status)' => $skipped]
        );

        $io->success("Issues updated successfully. State saved to state/$projectId/");

        if ($input->getOption('give-suggestions')) {
            $io->note('Suggestion generation is not yet implemented.');
        }

        return Command::SUCCESS;
    }

    private function resolveLimit(InputInterface $input, SymfonyStyle $io): ?int
    {
        $limitOption = $input->getOption('limit');

        if ($limitOption !== null) {
            return (int) $limitOption;
        }

        // In non-interactive mode, return null (no limit)
        if (!$input->isInteractive()) {
            return null;
        }

        $choice = $io->choice(
            'How many issues would you like to fetch?',
            [
                'all' => 'All new/updated issues',
                '10' => 'Last 10 issues',
                '25' => 'Last 25 issues',
                '50' => 'Last 50 issues',
                '100' => 'Last 100 issues',
            ],
            'all'
        );

        return $choice === 'all' ? null : (int) $choice;
    }
}
