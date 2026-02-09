<?php

declare(strict_types=1);

namespace DrupalIssueHelper\Command\Traits;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

trait ProjectAwareTrait
{
    protected function getProjects(): array
    {
        $projectsJson = $_ENV['DRUPAL_PROJECT_IDS'] ?? getenv('DRUPAL_PROJECT_IDS') ?: '';

        if (empty($projectsJson)) {
            return [];
        }

        $projects = json_decode($projectsJson, true);

        if (!is_array($projects)) {
            return [];
        }

        return $projects;
    }

    protected function askForProject(SymfonyStyle $io, array $projects): string
    {
        $choices = [];
        foreach ($projects as $name => $id) {
            $choices[$name] = "$name (ID: $id)";
        }

        return $io->choice('Select a project:', $choices, array_key_first($projects));
    }

    protected function resolveProject(
        InputInterface $input,
        SymfonyStyle $io,
        ?string $optionValue = null
    ): ?array {
        $projects = $this->getProjects();

        if (empty($projects)) {
            $io->error('No projects configured. Please set DRUPAL_PROJECT_IDS in your .env file.');
            $io->text('Example: DRUPAL_PROJECT_IDS=\'{"AI": 3346420, "Core": 1234567}\'');
            return null;
        }

        $projectName = $optionValue;

        if ($projectName === null) {
            $projectName = $this->askForProject($io, $projects);
        }

        if (!isset($projects[$projectName])) {
            $io->error("Project '$projectName' not found in configuration.");
            $io->text('Available projects: ' . implode(', ', array_keys($projects)));
            return null;
        }

        return [
            'name' => $projectName,
            'id' => (string) $projects[$projectName],
        ];
    }
}
