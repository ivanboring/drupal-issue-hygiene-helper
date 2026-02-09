<?php

declare(strict_types=1);

namespace DrupalIssueHelper\Service;

class IssueChecker
{
    private AIService $aiService;

    // Issue categories
    public const CATEGORY_BUG = '1';
    public const CATEGORY_TASK = '2';
    public const CATEGORY_FEATURE = '3';
    public const CATEGORY_SUPPORT = '4';
    public const CATEGORY_PLAN = '5';

    // Statuses
    public const STATUS_ACTIVE = 1;
    public const STATUS_NEEDS_REVIEW = 8;
    public const STATUS_NEEDS_WORK = 13;
    public const STATUS_RTBC = 14;
    public const STATUS_POSTPONED_INFO = 16;

    public const STATUS_NAMES = [
        1 => 'Active',
        8 => 'Needs review',
        13 => 'Needs work',
        14 => 'Reviewed & tested by the community',
        16 => 'Postponed (maintainer needs more info)',
    ];

    public function __construct(AIService $aiService)
    {
        $this->aiService = $aiService;
    }

    public function checkIssue(array $issue): ?array
    {
        // Run non-AI checks first (fast, deterministic)
        $suggestion = $this->runNonAIChecks($issue);

        if ($suggestion !== null) {
            return $suggestion;
        }

        // Run AI-based checks (one request per issue)
        return $this->runAIChecks($issue);
    }

    private function runNonAIChecks(array $issue): ?array
    {
        $status = (int) ($issue['status'] ?? 0);
        $hasPatch = $issue['has_patch'] ?? false;
        $hasMR = $issue['has_merge_request'] ?? false;
        $mrStatus = $issue['mr_status'] ?? null;
        $ciStatus = $issue['ci_status'] ?? null;
        $comments = $issue['comments'] ?? [];

        // Check 1: Patch/MR uploaded but still "Active" - should be "Needs Review"
        if ($status === self::STATUS_ACTIVE) {
            if ($hasMR && $mrStatus !== 'draft') {
                return $this->createSuggestion(
                    $issue,
                    'active_with_ready_mr',
                    'Issue has a merge request that is not in draft, but status is still "Active"',
                    'Set the issue to "Needs Review" since there is a merge request ready for review.',
                    'Hi! It looks like there\'s a merge request ready for review on this issue. Could you please set the status to "Needs Review" so reviewers know it\'s ready? Thanks!',
                    self::STATUS_NEEDS_REVIEW
                );
            }

            if ($hasPatch) {
                return $this->createSuggestion(
                    $issue,
                    'active_with_patch',
                    'Issue has a patch uploaded but status is still "Active"',
                    'Set the issue to "Needs Review" since there is a patch uploaded.',
                    'Hi! It looks like a patch has been uploaded to this issue. Could you please set the status to "Needs Review" so reviewers know it\'s ready? Thanks!',
                    self::STATUS_NEEDS_REVIEW
                );
            }
        }

        // Check 2: Failing CI and in "Needs Review"
        if ($status === self::STATUS_NEEDS_REVIEW && $ciStatus === 'failed') {
            return $this->createSuggestion(
                $issue,
                'failing_ci_in_review',
                'CI is failing but issue is in "Needs Review"',
                'Set the issue back to "Needs Work" until the CI passes.',
                'The CI pipeline is currently failing. Please check the test results and fix any issues before setting back to "Needs Review". Thanks!',
                self::STATUS_NEEDS_WORK
            );
        }

        // Check 3: Issue assigned but no activity for 5+ days
        $assignedCheck = $this->checkAssignedWithoutActivity($issue);
        if ($assignedCheck !== null) {
            return $assignedCheck;
        }

        // Check 4: Question asked without answer for 2+ weeks
        $unansweredCheck = $this->checkUnansweredQuestion($issue);
        if ($unansweredCheck !== null) {
            return $unansweredCheck;
        }

        return null;
    }

    private function checkAssignedWithoutActivity(array $issue): ?array
    {
        // Check if issue has an assignee (would need to be fetched from API)
        // For now, we check if there's been no activity in 5 days on an active issue
        $changed = $issue['changed'] ?? null;
        $status = (int) ($issue['status'] ?? 0);

        if ($changed === null) {
            return null;
        }

        $lastActivity = is_numeric($changed) ? (int) $changed : strtotime($changed);
        $daysSinceActivity = (time() - $lastActivity) / 86400;

        // Only flag if issue is in an active working state and no activity for 5+ days
        if (in_array($status, [self::STATUS_ACTIVE, self::STATUS_NEEDS_WORK]) && $daysSinceActivity > 5) {
            // Check if there are recent comments indicating work
            $comments = $issue['comments'] ?? [];
            if (!empty($comments)) {
                $lastComment = end($comments);
                $commentDate = $lastComment['date'] ?? null;
                if ($commentDate) {
                    $commentTime = strtotime($commentDate);
                    $daysSinceComment = (time() - $commentTime) / 86400;
                    if ($daysSinceComment <= 5) {
                        return null; // Recent comment, not stale
                    }
                }
            }

            return $this->createSuggestion(
                $issue,
                'stale_issue',
                'Issue has had no activity for ' . round($daysSinceActivity) . ' days',
                'Consider checking if this issue is still being worked on or if it needs attention.',
                null // No suggested comment - just a notification
            );
        }

        return null;
    }

    private function checkUnansweredQuestion(array $issue): ?array
    {
        $comments = $issue['comments'] ?? [];
        if (empty($comments)) {
            return null;
        }

        // Look for questions in comments that haven't been answered
        $twoWeeksAgo = time() - (14 * 86400);

        foreach ($comments as $i => $comment) {
            $content = $comment['content'] ?? '';
            $date = $comment['date'] ?? null;

            if (empty($content) || empty($date)) {
                continue;
            }

            $commentTime = strtotime($date);

            // Only check comments older than 2 weeks
            if ($commentTime > $twoWeeksAgo) {
                continue;
            }

            // Check if comment contains a question
            if (preg_match('/\?(?:\s|$)/', $content)) {
                // Check if there's a response after this comment from a different author
                $questionAuthor = $comment['author'] ?? '';
                $hasResponse = false;

                for ($j = $i + 1; $j < count($comments); $j++) {
                    $laterComment = $comments[$j];
                    $laterAuthor = $laterComment['author'] ?? '';
                    $laterContent = $laterComment['content'] ?? '';

                    if ($laterAuthor !== $questionAuthor && !empty($laterContent) && !str_starts_with($laterContent, '[Issue changes:')) {
                        $hasResponse = true;
                        break;
                    }
                }

                if (!$hasResponse) {
                    return $this->createSuggestion(
                        $issue,
                        'unanswered_question',
                        'Question from ' . $questionAuthor . ' has been unanswered for over 2 weeks',
                        'A question was asked but hasn\'t received a response. Consider following up.',
                        null // Notification only
                    );
                }
            }
        }

        return null;
    }

    private function runAIChecks(array $issue): ?array
    {
        $status = (int) ($issue['status'] ?? 0);
        $category = $issue['field_issue_category'] ?? '';
        $body = $issue['body'] ?? '';
        $title = $issue['title'] ?? '';
        $comments = $issue['comments'] ?? [];
        $statusChanged = $issue['status_changed'] ?? false;
        $previousStatus = $issue['previous_status'] ?? null;

        // Build context for AI
        $checksToPerform = [];

        // Check: Issue not detailed enough (bugs)
        if ($category === self::CATEGORY_BUG && $status === self::STATUS_ACTIVE) {
            $checksToPerform[] = 'bug_detail';
        }

        // Check: No test steps for GUI-related issues
        if (in_array($category, [self::CATEGORY_BUG, self::CATEGORY_FEATURE]) && $status !== self::STATUS_RTBC) {
            $checksToPerform[] = 'test_steps';
        }

        // Check: Status mischange (needs work/won't fix without explanation)
        if ($statusChanged && $previousStatus !== null) {
            if ($status === self::STATUS_NEEDS_WORK || $status === self::STATUS_POSTPONED_INFO) {
                $checksToPerform[] = 'status_change_explanation';
            }
        }

        // Check: RTBC with unresolved discussion
        if ($status === self::STATUS_RTBC) {
            $checksToPerform[] = 'rtbc_unresolved';
        }

        // Check: Feature request without use case
        if ($category === self::CATEGORY_FEATURE && $status === self::STATUS_ACTIVE) {
            $checksToPerform[] = 'feature_use_case';
        }

        if (empty($checksToPerform)) {
            return null;
        }

        // Make ONE AI request for all checks
        return $this->aiService->analyzeIssue($issue, $checksToPerform);
    }

    private function createSuggestion(
        array $issue,
        string $type,
        string $reason,
        string $suggestion,
        ?string $suggestedComment,
        ?int $suggestedStatus = null
    ): array {
        $result = [
            'issue_id' => $issue['nid'] ?? '',
            'issue_title' => $issue['title'] ?? '',
            'issue_url' => $issue['url'] ?? '',
            'current_status' => $issue['status'] ?? null,
            'current_status_name' => $issue['status_name'] ?? null,
            'type' => $type,
            'reason' => $reason,
            'suggestion' => $suggestion,
            'suggested_comment' => $suggestedComment,
            'suggested_status' => $suggestedStatus,
            'suggested_status_name' => $suggestedStatus ? (self::STATUS_NAMES[$suggestedStatus] ?? null) : null,
            'created_at' => date('c'),
            'ai_generated' => false,
        ];

        return $result;
    }
}
