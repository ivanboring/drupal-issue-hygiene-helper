<?php

declare(strict_types=1);

namespace DrupalIssueHelper\Command;

use DrupalIssueHelper\Command\Traits\ProjectAwareTrait;
use DrupalIssueHelper\State\StateHandler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'check-issues',
    description: 'Review and manage pending suggestions'
)]
class CheckIssuesCommand extends Command
{
    use ProjectAwareTrait;

    private StateHandler $stateHandler;
    private SymfonyStyle $io;
    private string $projectId;

    protected function configure(): void
    {
        $this
            ->addOption('project', 'p', InputOption::VALUE_OPTIONAL, 'Project name (from .env DRUPAL_PROJECT_IDS)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->stateHandler = new StateHandler(dirname(__DIR__, 2));

        // Resolve project
        $project = $this->resolveProject($input, $this->io, $input->getOption('project'));
        if ($project === null) {
            return Command::FAILURE;
        }

        $this->projectId = $project['id'];

        $this->io->title("Checking suggestions for project: {$project['name']}");

        // Main loop for reviewing suggestions
        while (true) {
            $suggestions = $this->stateHandler->getPendingSuggestions($this->projectId);

            if (empty($suggestions)) {
                $this->io->success('No pending suggestions to review.');
                return Command::SUCCESS;
            }

            // Build choice list
            $choices = [];
            foreach ($suggestions as $issueId => $suggestion) {
                $title = $suggestion['issue_title'] ?? 'Unknown';
                $shortTitle = strlen($title) > 40 ? substr($title, 0, 37) . '...' : $title;
                $type = $suggestion['type'] ?? 'unknown';
                $statusChange = '';
                if (!empty($suggestion['suggested_status_name'])) {
                    $statusChange = " -> {$suggestion['suggested_status_name']}";
                }
                $choices[$issueId] = "#$issueId: $shortTitle [$type]$statusChange";
            }
            $choices['back'] = '<- Back to menu';

            $this->io->section('Pending Suggestions (' . count($suggestions) . ')');

            $selected = $this->io->choice(
                'Select an issue to review (use arrow keys)',
                $choices,
                'back'
            );

            if ($selected === 'back') {
                return Command::SUCCESS;
            }

            // Show the selected suggestion
            $this->showSuggestion($selected, $suggestions[$selected]);
        }
    }

    private function showSuggestion(string $issueId, array $suggestion): void
    {
        $this->io->newLine();
        $this->io->section("Issue #$issueId");

        // Display suggestion details
        $this->io->text([
            "<info>Title:</info> " . ($suggestion['issue_title'] ?? 'Unknown'),
            "<info>URL:</info> " . ($suggestion['issue_url'] ?? ''),
            "<info>Type:</info> " . ($suggestion['type'] ?? 'unknown'),
            "",
            "<info>Current Status:</info> " . ($suggestion['current_status_name'] ?? 'Unknown'),
        ]);

        if (!empty($suggestion['suggested_status_name'])) {
            $this->io->text("<info>Suggested Status:</info> <fg=yellow>" . $suggestion['suggested_status_name'] . "</>");
        }

        $this->io->newLine();
        $this->io->text("<info>Reason:</info>");
        $this->io->text("  " . ($suggestion['reason'] ?? ''));

        $this->io->newLine();
        $this->io->text("<info>Suggestion:</info>");
        $this->io->text("  " . ($suggestion['suggestion'] ?? ''));

        if (!empty($suggestion['suggested_comment'])) {
            $this->io->newLine();
            $this->io->text("<info>Suggested Comment:</info>");
            $this->io->block($suggestion['suggested_comment'], null, 'fg=cyan', '  | ');
        }

        $this->io->newLine();

        // Action choice
        $action = $this->io->choice(
            'What would you like to do?',
            [
                'checked' => 'Mark as checked (move to issues_checked)',
                'open' => 'Open in browser',
                'back' => '<- Back to list',
            ],
            'back'
        );

        switch ($action) {
            case 'checked':
                $this->stateHandler->markIssueAsChecked($this->projectId, $issueId, [
                    'action_taken' => 'marked_checked',
                    'suggestion' => $suggestion,
                ]);
                $this->io->success("Issue #$issueId marked as checked.");
                break;

            case 'open':
                $url = $suggestion['issue_url'] ?? "https://www.drupal.org/node/$issueId";
                $this->openInBrowser($url);
                // After opening, show the suggestion again
                $this->showSuggestion($issueId, $suggestion);
                break;

            case 'back':
                // Just return to the list
                break;
        }
    }

    private function openInBrowser(string $url): void
    {
        $command = match (PHP_OS_FAMILY) {
            'Darwin' => 'open',
            'Windows' => 'start',
            default => 'xdg-open',
        };

        exec("$command " . escapeshellarg($url) . " > /dev/null 2>&1 &");
        $this->io->text("Opening $url in browser...");
        sleep(1);
    }
}
