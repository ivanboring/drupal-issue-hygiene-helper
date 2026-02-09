<?php

declare(strict_types=1);

namespace DrupalIssueHelper\State;

class StateHandler
{
    private string $baseDir;

    public function __construct(string $baseDir)
    {
        $this->baseDir = rtrim($baseDir, '/');
    }

    public function getProjectDir(string $projectId): string
    {
        return $this->baseDir . '/state/' . $projectId;
    }

    public function ensureDirectories(string $projectId): void
    {
        $dirs = [
            $this->getProjectDir($projectId) . '/latest_state',
            $this->getProjectDir($projectId) . '/issues_to_verify',
            $this->getProjectDir($projectId) . '/issues_checked',
        ];

        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    public function getGlobalState(string $projectId): array
    {
        $file = $this->getProjectDir($projectId) . '/global_state.json';

        if (!file_exists($file)) {
            return [
                'last_checked' => null,
                'issues_count' => 0,
            ];
        }

        $content = file_get_contents($file);
        return json_decode($content, true) ?? [];
    }

    public function saveGlobalState(string $projectId, array $state): void
    {
        $this->ensureDirectories($projectId);
        $file = $this->getProjectDir($projectId) . '/global_state.json';
        file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
    }

    public function saveIssueState(string $projectId, string $issueId, array $data, ?int $changedTimestamp = null): void
    {
        $this->ensureDirectories($projectId);
        $dir = $this->getProjectDir($projectId) . '/latest_state';

        // Remove any existing state file for this issue
        $this->removeIssueStateFiles($projectId, $issueId);

        // Use the changed timestamp from the issue, or current time as fallback
        $timestamp = $changedTimestamp ?? time();
        $file = $dir . '/' . $timestamp . '_' . $issueId . '.json';
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function getIssueState(string $projectId, string $issueId): ?array
    {
        $file = $this->findIssueStateFile($projectId, $issueId);

        if ($file === null) {
            return null;
        }

        $content = file_get_contents($file);
        return json_decode($content, true);
    }

    private function findIssueStateFile(string $projectId, string $issueId): ?string
    {
        $dir = $this->getProjectDir($projectId) . '/latest_state';
        $pattern = $dir . '/*_' . $issueId . '.json';
        $files = glob($pattern);

        return !empty($files) ? $files[0] : null;
    }

    private function removeIssueStateFiles(string $projectId, string $issueId): void
    {
        $dir = $this->getProjectDir($projectId) . '/latest_state';
        $pattern = $dir . '/*_' . $issueId . '.json';

        foreach (glob($pattern) as $file) {
            unlink($file);
        }
    }

    public function getIssueStatesUpdatedAfter(string $projectId, int $timestamp): array
    {
        $dir = $this->getProjectDir($projectId) . '/latest_state';

        if (!is_dir($dir)) {
            return [];
        }

        $issues = [];
        foreach (glob($dir . '/*.json') as $file) {
            $filename = basename($file, '.json');

            // Parse timestamp from filename: {timestamp}_{issueId}
            if (preg_match('/^(\d+)_(\d+)$/', $filename, $matches)) {
                $fileTimestamp = (int) $matches[1];
                $issueId = $matches[2];

                if ($fileTimestamp > $timestamp) {
                    $content = json_decode(file_get_contents($file), true);
                    if ($content) {
                        $issues[$issueId] = $content;
                    }
                }
            }
        }

        return $issues;
    }

    public function getAllIssueStates(string $projectId): array
    {
        $dir = $this->getProjectDir($projectId) . '/latest_state';

        if (!is_dir($dir)) {
            return [];
        }

        $issues = [];
        foreach (glob($dir . '/*.json') as $file) {
            $filename = basename($file, '.json');

            // Parse timestamp from filename: {timestamp}_{issueId}
            if (preg_match('/^(\d+)_(\d+)$/', $filename, $matches)) {
                $issueId = $matches[2];
                $content = json_decode(file_get_contents($file), true);
                if ($content) {
                    $issues[$issueId] = $content;
                }
            }
        }

        return $issues;
    }

    public function saveIssueSuggestion(string $projectId, string $issueId, array $suggestion): void
    {
        $this->ensureDirectories($projectId);
        $file = $this->getProjectDir($projectId) . '/issues_to_verify/' . $issueId . '.json';
        file_put_contents($file, json_encode($suggestion, JSON_PRETTY_PRINT));
    }

    public function getIssueSuggestion(string $projectId, string $issueId): ?array
    {
        $file = $this->getProjectDir($projectId) . '/issues_to_verify/' . $issueId . '.json';

        if (!file_exists($file)) {
            return null;
        }

        $content = file_get_contents($file);
        return json_decode($content, true);
    }

    public function markIssueAsChecked(string $projectId, string $issueId, array $data = []): void
    {
        $this->ensureDirectories($projectId);

        // Move from issues_to_verify to issues_checked
        $verifyFile = $this->getProjectDir($projectId) . '/issues_to_verify/' . $issueId . '.json';
        $checkedFile = $this->getProjectDir($projectId) . '/issues_checked/' . $issueId . '.json';

        $checkedData = array_merge($data, [
            'checked_at' => date('c'),
        ]);

        if (file_exists($verifyFile)) {
            $suggestion = json_decode(file_get_contents($verifyFile), true);
            $checkedData['original_suggestion'] = $suggestion;
            unlink($verifyFile);
        }

        file_put_contents($checkedFile, json_encode($checkedData, JSON_PRETTY_PRINT));
    }

    public function isIssueChecked(string $projectId, string $issueId): bool
    {
        $file = $this->getProjectDir($projectId) . '/issues_checked/' . $issueId . '.json';
        return file_exists($file);
    }

    public function getPendingSuggestions(string $projectId): array
    {
        $dir = $this->getProjectDir($projectId) . '/issues_to_verify';

        if (!is_dir($dir)) {
            return [];
        }

        $suggestions = [];
        foreach (glob($dir . '/*.json') as $file) {
            $issueId = basename($file, '.json');
            $content = json_decode(file_get_contents($file), true);
            $suggestions[$issueId] = $content;
        }

        return $suggestions;
    }
}
