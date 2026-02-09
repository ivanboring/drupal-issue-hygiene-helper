<?php

declare(strict_types=1);

namespace DrupalIssueHelper\Command;

use DrupalIssueHelper\Command\Traits\ProjectAwareTrait;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'interactive',
    description: 'Interactive mode - choose what to do'
)]
class InteractiveCommand extends Command
{
    use ProjectAwareTrait;

    private ?array $selectedProject = null;

    protected function configure(): void
    {
        $this->setHelp(<<<'HELP'
Drupal Issue Hygiene Helper - Manage Drupal.org issue queue hygiene

<info>Available Commands:</info>
  <comment>update-issues</comment>      Fetch latest issues from Drupal.org API
  <comment>give-suggestions</comment>   Analyze issues and generate improvement suggestions
  <comment>check-issues</comment>       Review and manage pending suggestions
  <comment>list</comment>               Show all available commands

<info>Usage:</info>
  Run interactively (recommended):
    <comment>php issue.php</comment>

  Run specific commands directly:
    <comment>php issue.php update-issues --project=AI</comment>
    <comment>php issue.php give-suggestions --project=AI --limit=10</comment>
    <comment>php issue.php check-issues --project=AI</comment>

  Show all commands:
    <comment>php issue.php list</comment>

<info>Interactive Mode Flow:</info>
  1. Select a project from your .env configuration
  2. Choose an action: Update, Check suggestions, Give suggestions
  3. Review results and repeat or exit
HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Drupal Issue Hygiene Helper');

        // First, pick a project
        $this->selectedProject = $this->resolveProject($input, $io, null);
        if ($this->selectedProject === null) {
            return Command::FAILURE;
        }

        $io->success("Selected project: {$this->selectedProject['name']}");

        while (true) {
            $choice = $io->choice(
                'What would you like to do?',
                [
                    'update' => 'Update issues - Fetch latest issues from Drupal.org',
                    'check' => 'Check issues - Review pending suggestions',
                    'suggestions' => 'Give suggestions - Analyze issues for problems',
                    'change-project' => 'Change project',
                    'quit' => 'Exit',
                ],
                'update'
            );

            switch ($choice) {
                case 'update':
                    $this->runCommand('update-issues', $output);
                    break;

                case 'check':
                    $this->runCommand('check-issues', $output);
                    break;

                case 'suggestions':
                    $this->runCommand('give-suggestions', $output);
                    break;

                case 'change-project':
                    $this->selectedProject = $this->resolveProject($input, $io, null);
                    if ($this->selectedProject === null) {
                        return Command::FAILURE;
                    }
                    $io->success("Selected project: {$this->selectedProject['name']}");
                    break;

                case 'quit':
                    $io->success('Goodbye!');
                    return Command::SUCCESS;
            }

            $io->newLine(2);
        }
    }

    private function runCommand(string $commandName, OutputInterface $output): int
    {
        $command = $this->getApplication()?->find($commandName);

        if ($command === null) {
            return Command::FAILURE;
        }

        // Pass the selected project to the command
        $arguments = [
            '--project' => $this->selectedProject['name'],
        ];

        return $command->run(new ArrayInput($arguments), $output);
    }
}
