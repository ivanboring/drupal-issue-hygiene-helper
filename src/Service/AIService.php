<?php

declare(strict_types=1);

namespace DrupalIssueHelper\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ClientException;

class AIService
{
    private ?Client $httpClient = null;
    private string $apiKey = '';
    private int $maxRetries = 3;
    private int $retryDelay = 5;

    public function __construct()
    {
        $this->apiKey = $_ENV['OPENAI_API_KEY'] ?? getenv('OPENAI_API_KEY') ?: '';

        if (!empty($this->apiKey)) {
            $this->httpClient = new Client([
                'base_uri' => 'https://api.openai.com/',
                'timeout' => 60,
            ]);
        }
    }

    public function isAvailable(): bool
    {
        return $this->httpClient !== null && !empty($this->apiKey);
    }

    public function analyzeIssue(array $issue, array $checksToPerform): ?array
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $prompt = $this->buildAnalysisPrompt($issue, $checksToPerform);

        try {
            $content = $this->retryRequest(function () use ($prompt) {
                $response = $this->httpClient->post('v1/chat/completions', [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $this->apiKey,
                        'Content-Type' => 'application/json',
                    ],
                    'json' => [
                        'model' => 'gpt-5.1',
                        'temperature' => 0.3,
                        'messages' => [
                            ['role' => 'system', 'content' => $this->getSystemPrompt()],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                return $data['choices'][0]['message']['content'] ?? '';
            });

            return $this->parseAIResponse($issue, $content);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    private function retryRequest(callable $request): mixed
    {
        $lastException = null;

        for ($attempt = 1; $attempt <= $this->maxRetries; $attempt++) {
            try {
                return $request();
            } catch (ClientException $e) {
                $statusCode = $e->getResponse()->getStatusCode();

                // Retry on 429 (Too Many Requests) or 503 (Service Unavailable)
                if (in_array($statusCode, [429, 503])) {
                    $lastException = $e;

                    if ($attempt < $this->maxRetries) {
                        sleep($this->retryDelay);
                        continue;
                    }
                }

                throw $e;
            } catch (GuzzleException $e) {
                $lastException = $e;

                // Retry on connection errors
                if ($attempt < $this->maxRetries) {
                    sleep($this->retryDelay);
                    continue;
                }
            }
        }

        throw new \RuntimeException('AI request failed: ' . ($lastException ? $lastException->getMessage() : 'Unknown error'));
    }

    private const STATUS_NAMES = [
        1 => 'Active',
        8 => 'Needs review',
        13 => 'Needs work',
        14 => 'Reviewed & tested by the community',
        16 => 'Postponed (maintainer needs more info)',
    ];

    private function getSystemPrompt(): string
    {
        return <<<PROMPT
You are a Drupal issue queue hygiene assistant. Your job is to analyze issues and identify problems that need attention.

You will be given an issue with its title, description, status, and comments. You need to check for specific problems.

IMPORTANT: Only identify ONE problem (the most important one) if any exist. If everything looks fine, say so.

The comment on the issue should be polite and constructive. When thinking to ask for test steps, this should only happen on features and bugs, and should
only happen if you can't figure it out by context.

For issues where there are still unresolved discussions, please address exactly what is unresolved and from whom. Try to check later comments to see if this
is truly unresolved. Either the person who opened the review or the person doing the RTBC should have the last word. If they say that they have addressed all comments,
then consider the issue resolved. If the person asking a question, says that its answered or if that persons sets it all to resolved, then consider the RTBC resolved.

Be leniant on this - only change if its obvious that someone is asking a question that hasn't been answered, or if the person doing RTBC is asking for something that hasn't been addressed. If there is a long discussion but the last comment is from the issue opener saying that they have addressed all concerns, then consider it resolved.

If a user is asking for a second look, before RTBC, but the put it in RTBC themselves, that is a sign that they want it to be considered RTBC, so consider it RTBC even if there are some unresolved questions and do nothing.

When suggesting a status change, use these mappings:

If a issue is RTBC, you do NOT need to change it back due to missing test instruction or missing details, since it has been reviewed and accepted by a maintainer. In this case do nothing.

For the issues of giving reasoning, be very leniant since sometimes the reasoning is obvious via context or via images that you might not be able to see.
Only suggest a change if there is absolutely no reasoning provided.

When you are giving comments, could you make sure that you target people that speficially asked for something, with an @ sign.

For issues that have discuss or meta in their title, always set has_problem to false, since these are meant for discussion and often don't have a clear problem or solution.

When suggesting a status change, use these mappings:

Respond in this exact JSON format:
{
    "has_problem": true/false,
    "problem_type": "type_identifier",
    "reason": "Brief explanation of the problem",
    "suggestion": "What should be done to fix it",
    "suggested_comment": "A polite comment to post on the issue (or null if just a notification)",
    "suggested_status": null or one of: 1 (Active), 8 (Needs review), 13 (Needs work), 16 (Postponed - maintainer needs more info)
}

Problem types and their typical suggested statuses:
- "bug_not_detailed": Bug report lacks clear explanation -> suggest status 16 (Postponed)
- "no_test_steps": Feature/bug touching GUI lacks testing instructions -> suggest status 13 (Needs work)
- "status_change_no_explanation": Status changed without explaining why -> suggest reverting to previous status
- "rtbc_unresolved_discussion": RTBC but questions weren't addressed -> suggest status 13 (Needs work)
- "feature_no_use_case": Feature request doesn't explain reasoning -> suggest status 16 (Postponed)

Be conservative - only flag issues that clearly have problems. When in doubt, say has_problem: false.
PROMPT;
    }

    private function buildAnalysisPrompt(array $issue, array $checks): string
    {
        $title = $issue['title'] ?? '';
        $body = $this->truncateText($issue['body'] ?? '', 2000);
        $status = $issue['status_name'] ?? '';
        $category = $this->getCategoryName($issue['field_issue_category'] ?? '');
        $previousStatus = isset($issue['previous_status_name']) ? $issue['previous_status_name'] : null;

        // Get last 5 comments
        $comments = $issue['comments'] ?? [];
        $recentComments = array_slice($comments, -5);
        $commentsText = '';
        foreach ($recentComments as $comment) {
            $author = $comment['author'] ?? 'Unknown';
            $content = $this->truncateText($comment['content'] ?? '', 500);
            $date = $comment['date'] ?? '';
            $commentsText .= "\n---\nComment by $author ($date):\n$content\n";
        }

        $checksDescription = $this->describeChecks($checks);

        $prompt = <<<PROMPT
Analyze this Drupal issue:

**Title:** $title
**Category:** $category
**Current Status:** $status
PROMPT;

        if ($previousStatus) {
            $prompt .= "\n**Previous Status:** $previousStatus (status just changed)";
        }

        $prompt .= <<<PROMPT

**Description:**
$body

**Recent Comments:**
$commentsText

**Checks to perform:**
$checksDescription

Based on these checks, identify if there's a problem. Remember: only identify ONE problem (the most important), and be conservative.
PROMPT;

        return $prompt;
    }

    private function describeChecks(array $checks): string
    {
        $descriptions = [
            'bug_detail' => '- Is this bug report detailed enough? Does it explain what is wrong and why?',
            'test_steps' => '- If this touches the GUI, are there testing instructions provided?',
            'status_change_explanation' => '- The status was just changed. Is there a comment explaining why?',
            'rtbc_unresolved' => '- This is marked RTBC. Were all previous questions and suggestions addressed?',
            'feature_use_case' => '- Does this feature request explain the use case or reasoning?',
        ];

        $result = [];
        foreach ($checks as $check) {
            if (isset($descriptions[$check])) {
                $result[] = $descriptions[$check];
            }
        }

        return implode("\n", $result);
    }

    private function parseAIResponse(array $issue, string $response): ?array
    {
        // Try to extract JSON from response
        if (preg_match('/\{[\s\S]*\}/', $response, $match)) {
            $json = json_decode($match[0], true);

            if ($json && isset($json['has_problem']) && $json['has_problem'] === true) {
                $suggestedStatus = isset($json['suggested_status']) ? (int) $json['suggested_status'] : null;

                return [
                    'issue_id' => $issue['nid'] ?? '',
                    'issue_title' => $issue['title'] ?? '',
                    'issue_url' => $issue['url'] ?? '',
                    'current_status' => $issue['status'] ?? null,
                    'current_status_name' => $issue['status_name'] ?? null,
                    'type' => $json['problem_type'] ?? 'unknown',
                    'reason' => $json['reason'] ?? '',
                    'suggestion' => $json['suggestion'] ?? '',
                    'suggested_comment' => $json['suggested_comment'] ?? null,
                    'suggested_status' => $suggestedStatus,
                    'suggested_status_name' => $suggestedStatus ? (self::STATUS_NAMES[$suggestedStatus] ?? null) : null,
                    'created_at' => date('c'),
                    'ai_generated' => true,
                ];
            }
        }

        return null;
    }

    private function getCategoryName(string $category): string
    {
        $categories = [
            '1' => 'Bug report',
            '2' => 'Task',
            '3' => 'Feature request',
            '4' => 'Support request',
            '5' => 'Plan',
        ];

        return $categories[$category] ?? 'Unknown';
    }

    private function truncateText(string $text, int $maxLength): string
    {
        // Strip HTML tags for cleaner text
        $text = strip_tags($text);
        $text = html_entity_decode($text);
        $text = preg_replace('/\s+/', ' ', $text);
        $text = trim($text);

        if (strlen($text) > $maxLength) {
            $text = substr($text, 0, $maxLength) . '...';
        }

        return $text;
    }
}
