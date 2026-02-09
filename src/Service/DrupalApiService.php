<?php

declare(strict_types=1);

namespace DrupalIssueHelper\Service;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\ClientException;
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;

class DrupalApiService
{
    private Client $httpClient;
    private int $maxRetries = 3;
    private int $retryDelay = 5;

    public const ACTIVE_STATUSES = [
        1 => 'active',
        8 => 'needs review',
        13 => 'needs work',
        14 => 'reviewed & tested by the community',
        15 => 'patch (to be ported)',
        16 => 'postponed (maintainer needs more info)',
    ];

    public const ALL_STATUSES = [
        1 => 'active',
        2 => 'fixed',
        3 => 'closed (duplicate)',
        4 => 'postponed',
        5 => 'closed (won\'t fix)',
        6 => 'closed (works as designed)',
        7 => 'closed (fixed)',
        8 => 'needs review',
        13 => 'needs work',
        14 => 'reviewed & tested by the community',
        15 => 'patch (to be ported)',
        16 => 'postponed (maintainer needs more info)',
        17 => 'closed (outdated)',
        18 => 'closed (cannot reproduce)',
    ];

    public function __construct()
    {
        $this->httpClient = new Client([
            'base_uri' => 'https://www.drupal.org/',
            'timeout' => 30,
        ]);
    }

    private function retryRequest(callable $request, string $errorMessage): mixed
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

        throw new \RuntimeException($errorMessage . ': ' . ($lastException ? $lastException->getMessage() : 'Unknown error'));
    }

    public function fetchProjectIssues(string $projectId, ?int $changedAfter = null, ?int $limit = null): array
    {
        $allIssues = [];
        $page = 0;
        $perPage = 50; // Drupal.org API default

        while (true) {
            $params = [
                'type' => 'project_issue',
                'field_project' => $projectId,
                'sort' => 'changed',
                'direction' => 'DESC',
                'page' => $page,
                'field_issue_status' => [
                    'value' => [
                        1, 8, 13, 14,
                    ]
                ]
            ];

            if ($changedAfter !== null) {
                $params['changed'] = $changedAfter;
            }

            $issues = $this->retryRequest(function () use ($params, $page) {
                $response = $this->httpClient->get('api-d7/node.json', [
                    'query' => $params,
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                // Write out that we fetched page $page with count of issues
                echo "Fetched page $page with " . count($data['list'] ?? []) . " issues\n";
                return $data['list'] ?? [];
            }, 'Failed to fetch issues');

            if (empty($issues)) {
                break; // No more results
            }

            $allIssues = array_merge($allIssues, $issues);

            // Check if we've reached the requested limit
            if ($limit !== null && count($allIssues) >= $limit) {
                $allIssues = array_slice($allIssues, 0, $limit);
                break;
            }

            // If we got fewer than perPage results, we've reached the end
            if (count($issues) < $perPage) {
                break;
            }

            $page++;
        }

        return $allIssues;
    }

    public function fetchIssue(string $issueId): ?array
    {
        try {
            return $this->retryRequest(function () use ($issueId) {
                $response = $this->httpClient->get('api-d7/node/' . $issueId . '.json');
                return json_decode($response->getBody()->getContents(), true);
            }, 'Failed to fetch issue ' . $issueId);
        } catch (\RuntimeException $e) {
            return null;
        }
    }

    public function scrapeIssuePage(string $issueId, ?string $debugHtmlPath = null): ?array
    {
        $url = 'https://www.drupal.org/node/' . $issueId;

        $browserFactory = new BrowserFactory();
        $browser = $browserFactory->createBrowser([
            'startupTimeout' => 60,
            'headless' => false,
            'windowSize' => [1920, 1080],
            'userAgent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'customFlags' => [
                '--disable-blink-features=AutomationControlled',
                '--disable-dev-shm-usage',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-infobars',
                '--headless=new', // Use new headless mode for better performance and compatibility
            ],
        ]);

        try {
            $page = $browser->createPage();

            // Override webdriver detection
            $page->evaluate('
                Object.defineProperty(navigator, "webdriver", {
                    get: () => undefined
                });
            ');

            $page->navigate($url)->waitForNavigation(Page::NETWORK_IDLE, 60000);

            // Wait for page to fully render
            sleep(3);

            $html = $page->getHtml();

            // Optionally save raw HTML for debugging
            if ($debugHtmlPath) {
                file_put_contents($debugHtmlPath, $html);
            }

            return $this->parseIssueHtml($html, $issueId);
        } finally {
            $browser->close();
        }
    }

    public function hasEmptyComments(array $comments): bool
    {
        foreach ($comments as $comment) {
            if (empty($comment['content']) || trim($comment['content']) === '') {
                return true;
            }
        }
        return false;
    }

    public function scrapeCommentsFromPage(string $issueId, array $emptyCommentIds, ?string $debugHtmlPath = null): array
    {
        $url = 'https://www.drupal.org/node/' . $issueId;

        $browserFactory = new BrowserFactory();
        $browser = $browserFactory->createBrowser([
            'startupTimeout' => 60,
            'headless' => false,
            'windowSize' => [1920, 1080],
            'userAgent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'customFlags' => [
                '--disable-blink-features=AutomationControlled',
                '--disable-dev-shm-usage',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-infobars',
                '--headless=new', // Use new headless mode for better performance and compatibility
            ],
        ]);

        try {
            $page = $browser->createPage();

            // Override webdriver detection
            $page->evaluate('
                Object.defineProperty(navigator, "webdriver", {
                    get: () => undefined
                });
            ');

            $page->navigate($url)->waitForNavigation(Page::NETWORK_IDLE, 60000);

            // Wait for page to fully render
            sleep(3);

            $html = $page->getHtml();

            // Save HTML for debugging
            if ($debugHtmlPath) {
                file_put_contents($debugHtmlPath, $html);
            }

            return $this->extractCommentsById($html, $emptyCommentIds);
        } finally {
            $browser->close();
        }
    }

    public function scrapeAllCommentsFromPage(string $issueId, ?string $debugHtmlPath = null): array
    {
        $url = 'https://www.drupal.org/node/' . $issueId;

        $browserFactory = new BrowserFactory();
        $browser = $browserFactory->createBrowser([
            'startupTimeout' => 60,
            'headless' => false,
            'windowSize' => [1920, 1080],
            'userAgent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'customFlags' => [
                '--disable-blink-features=AutomationControlled',
                '--disable-dev-shm-usage',
                '--no-first-run',
                '--no-default-browser-check',
                '--disable-infobars',
                '--headless=new',
            ],
        ]);

        try {
            $page = $browser->createPage();

            // Override webdriver detection
            $page->evaluate('
                Object.defineProperty(navigator, "webdriver", {
                    get: () => undefined
                });
            ');

            $page->navigate($url)->waitForNavigation(Page::NETWORK_IDLE, 60000);

            // Wait for page to fully render
            sleep(3);

            $html = $page->getHtml();

            // Save HTML for debugging
            if ($debugHtmlPath) {
                file_put_contents($debugHtmlPath, $html);
            }

            return $this->extractAllComments($html);
        } finally {
            $browser->close();
        }
    }

    private function extractAllComments(string $html): array
    {
        $comments = [];

        // First, find the comments section
        $commentsSection = $html;
        if (preg_match('/<section[^>]*id="comments"[^>]*>(.*?)<\/section>/si', $html, $sectionMatch)) {
            $commentsSection = $sectionMatch[1];
        }

        // Pattern 1: Find comment divs by id="comment-{id}" - capture until next comment or end of section
        if (preg_match_all('/<div[^>]*id="comment-(\d+)"[^>]*>(.*?)(?=<div[^>]*id="comment-\d+"|<\/section|$)/si', $commentsSection, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $commentId = $match[1];
                $commentHtml = $match[2];
                $comment = $this->parseScrapedComment($commentId, $commentHtml);
                if ($comment) {
                    $comments[] = $comment;
                }
            }
        }

        // Pattern 2: Alternative - class before id
        if (empty($comments)) {
            if (preg_match_all('/<div[^>]*class="[^"]*comment[^"]*"[^>]*id="comment-(\d+)"[^>]*>(.*?)(?=<div[^>]*class="[^"]*comment[^"]*"[^>]*id="comment-|<\/section|$)/si', $commentsSection, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $commentId = $match[1];
                    $commentHtml = $match[2];
                    $comment = $this->parseScrapedComment($commentId, $commentHtml);
                    if ($comment) {
                        $comments[] = $comment;
                    }
                }
            }
        }

        // Pattern 3: article elements (some Drupal themes use article for comments)
        if (empty($comments)) {
            if (preg_match_all('/<article[^>]*id="comment-(\d+)"[^>]*>(.*?)<\/article>/si', $commentsSection, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $match) {
                    $commentId = $match[1];
                    $commentHtml = $match[2];
                    $comment = $this->parseScrapedComment($commentId, $commentHtml);
                    if ($comment) {
                        $comments[] = $comment;
                    }
                }
            }
        }

        return $comments;
    }

    private function parseScrapedComment(string $commentId, string $html): ?array
    {
        $comment = [
            'id' => $commentId,
            'author' => null,
            'date' => null,
            'content' => '',
            'status_change' => null,
            'has_attachment' => false,
            'has_patch' => false,
            'has_mr_reference' => false,
            'content_source' => 'scraped',
        ];

        // Extract author
        if (preg_match('/<a[^>]*href="\/u(?:ser)?\/[^"]*"[^>]*>([^<]+)<\/a>/i', $html, $authorMatch)) {
            $comment['author'] = trim($authorMatch[1]);
        }

        // Extract date
        if (preg_match('/<time[^>]*datetime="([^"]+)"[^>]*>/i', $html, $dateMatch)) {
            $comment['date'] = $dateMatch[1];
        }

        // Extract comment body content - try multiple patterns
        $bodyContent = '';

        // Pattern 1: field-name-comment-body with field-item
        if (preg_match('/<div[^>]*class="[^"]*field-name-comment-body[^"]*"[^>]*>.*?<div[^>]*class="[^"]*field-item[^"]*"[^>]*>(.*?)<\/div>/si', $html, $bodyMatch)) {
            $bodyContent = $this->cleanHtmlContent($bodyMatch[1]);
        }

        // Pattern 2: field--name-comment-body (Drupal 8+ style)
        if (empty($bodyContent) && preg_match('/<div[^>]*class="[^"]*field--name-comment-body[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/si', $html, $bodyMatch)) {
            $bodyContent = $this->cleanHtmlContent($bodyMatch[1]);
        }

        // Pattern 3: comment-body class directly
        if (empty($bodyContent) && preg_match('/<div[^>]*class="[^"]*comment-body[^"]*"[^>]*>(.*?)<\/div>/si', $html, $bodyMatch)) {
            $bodyContent = $this->cleanHtmlContent($bodyMatch[1]);
        }

        // Pattern 4: Look for text-formatted content
        if (empty($bodyContent) && preg_match('/<div[^>]*class="[^"]*text-formatted[^"]*"[^>]*>(.*?)<\/div>/si', $html, $bodyMatch)) {
            $bodyContent = $this->cleanHtmlContent($bodyMatch[1]);
        }

        // Pattern 5: Look for any p tags within content area
        if (empty($bodyContent) && preg_match('/<div[^>]*class="[^"]*content[^"]*"[^>]*>.*?(<p[^>]*>.*?<\/p>)/si', $html, $bodyMatch)) {
            $bodyContent = $this->cleanHtmlContent($bodyMatch[1]);
        }

        // Pattern 6: clearfix field-item with text content
        if (empty($bodyContent) && preg_match('/<div[^>]*class="[^"]*clearfix[^"]*text-formatted[^"]*"[^>]*>(.*?)<\/div>/si', $html, $bodyMatch)) {
            $bodyContent = $this->cleanHtmlContent($bodyMatch[1]);
        }

        $comment['content'] = $bodyContent;

        // Extract issue changes (metadata changes like status, tags, etc.)
        $changes = [];

        // Pattern 1: field-name-field-issue-changes
        if (preg_match('/<div[^>]*class="[^"]*field-name-field-issue-changes[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/si', $html, $changesMatch)) {
            $changes = $this->extractIssueChanges($changesMatch[1]);
        }

        // Pattern 2: nodechanges table directly
        if (empty($changes) && preg_match('/<table[^>]*class="[^"]*nodechanges[^"]*"[^>]*>(.*?)<\/table>/si', $html, $changesMatch)) {
            $changes = $this->extractIssueChanges($changesMatch[1]);
        }

        if (!empty($changes)) {
            $changesText = '[Issue changes: ' . implode(', ', $changes) . ']';
            $comment['content'] = trim($comment['content'] . "\n" . $changesText);
        }

        // Check for status change
        if (preg_match('/Status.*?changed.*?from.*?"?([^"<]+)"?.*?to.*?"?([^"<]+)"?/si', $html, $statusMatch)) {
            $comment['status_change'] = [
                'from' => trim(strip_tags($statusMatch[1])),
                'to' => trim(strip_tags($statusMatch[2])),
            ];
        }

        // Check for attachments
        if (str_contains($html, '.patch') || str_contains($html, 'interdiff')) {
            $comment['has_patch'] = true;
            $comment['has_attachment'] = true;
        }
        if (preg_match('/\.(txt|zip|tar|gz|pdf|png|jpg|jpeg)["\'>\s]/i', $html)) {
            $comment['has_attachment'] = true;
        }

        // Check for MR references
        if (str_contains($html, 'merge_request') || str_contains($html, 'gitlab.com')) {
            $comment['has_mr_reference'] = true;
        }

        return $comment;
    }

    private function extractCommentsById(string $html, array $commentIds): array
    {
        $results = [];

        foreach ($commentIds as $commentId) {
            // Find the comment div by ID
            $pattern = '/<div[^>]*id="comment-' . preg_quote($commentId, '/') . '"[^>]*>(.*?)<ul[^>]*class="links/si';

            if (preg_match($pattern, $html, $match)) {
                $commentHtml = $match[1];
                $content = '';

                // Try to get comment body first
                if (preg_match('/<div[^>]*class="[^"]*field-name-comment-body[^"]*"[^>]*>.*?<div[^>]*class="field-item[^"]*"[^>]*>(.*?)<\/div>/si', $commentHtml, $bodyMatch)) {
                    $content = $this->cleanHtmlContent($bodyMatch[1]);
                }

                // If no body, check for issue changes (metadata changes)
                if (empty($content)) {
                    if (preg_match('/<div[^>]*class="[^"]*field-name-field-issue-changes[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>\s*<\/div>/si', $commentHtml, $changesMatch)) {
                        $changes = $this->extractIssueChanges($changesMatch[1]);
                        if (!empty($changes)) {
                            $content = '[Issue changes: ' . implode(', ', $changes) . ']';
                        }
                    }
                }

                if (!empty($content)) {
                    $results[$commentId] = $content;
                }
            }
        }

        return $results;
    }

    private function extractIssueChanges(string $html): array
    {
        $changes = [];

        // Extract changes from the nodechanges table
        if (preg_match_all('/<tr[^>]*>.*?<td[^>]*class="nodechanges-label"[^>]*>(.*?)<\/td>.*?<td[^>]*class="nodechanges-new"[^>]*>(.*?)<\/td>/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $label = trim(strip_tags($match[1]));
                $value = trim(strip_tags($match[2]));
                if (!empty($label) && !empty($value)) {
                    $changes[] = "$label $value";
                }
            }
        }

        // Also check for file changes
        if (preg_match_all('/<tr[^>]*>.*?<td[^>]*>new<\/td>.*?<a[^>]*>([^<]+)<\/a>/si', $html, $fileMatches, PREG_SET_ORDER)) {
            foreach ($fileMatches as $match) {
                $changes[] = "Added file: " . trim($match[1]);
            }
        }

        return $changes;
    }

    public function fetchIssueComments(string $issueId): array
    {
        try {
            return $this->retryRequest(function () use ($issueId) {
                $response = $this->httpClient->get('api-d7/comment.json', [
                    'query' => [
                        'node' => $issueId,
                        'sort' => 'created',
                        'direction' => 'ASC',
                    ],
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $comments = [];

                foreach ($data['list'] ?? [] as $comment) {
                    $comments[] = [
                        'id' => $comment['cid'] ?? null,
                        'author' => $comment['name'] ?? null,
                        'author_uid' => $comment['uid'] ?? null,
                        'date' => isset($comment['created']) ? date('c', (int)$comment['created']) : null,
                        'content' => $comment['comment_body']['value'] ?? '',
                        'status_change' => $this->extractStatusChangeFromComment($comment),
                        'has_attachment' => false,
                        'has_patch' => false,
                        'has_mr_reference' => false,
                    ];
                }

                return $comments;
            }, 'Failed to fetch comments for issue ' . $issueId);
        } catch (\RuntimeException $e) {
            return [];
        }
    }

    private function extractStatusChangeFromComment(array $comment): ?array
    {
        // Check for issue status changes in the comment data
        $body = $comment['comment_body']['value'] ?? '';

        if (preg_match('/Status.*?changed.*?from.*?"([^"]+)".*?to.*?"([^"]+)"/si', $body, $match)) {
            return [
                'from' => $match[1],
                'to' => $match[2],
            ];
        }

        return null;
    }

    private function parseIssueHtml(string $html, string $issueId): array
    {
        $data = [
            'issue_id' => $issueId,
            'scraped_at' => date('c'),
            'has_merge_request' => false,
            'has_patch' => false,
            'mr_status' => null,
            'comments' => [],
            'ci_status' => null,
        ];

        // Check for merge request
        if (
            str_contains($html, 'gitlab.com') ||
            str_contains($html, 'Merge request') ||
            str_contains($html, 'merge_requests')
        ) {
            $data['has_merge_request'] = true;

            // Check MR draft status
            if (str_contains($html, 'Draft:') || str_contains($html, 'WIP:')) {
                $data['mr_status'] = 'draft';
            } else {
                $data['mr_status'] = 'ready';
            }
        }

        // Check for patches
        if (preg_match('/\.patch["\s<]/', $html) || str_contains($html, 'interdiff')) {
            $data['has_patch'] = true;
        }

        // Check CI status indicators
        if (str_contains($html, 'ci-status-fail') || str_contains($html, 'pipeline failed') || str_contains($html, 'status-icon--failure')) {
            $data['ci_status'] = 'failed';
        } elseif (str_contains($html, 'ci-status-success') || str_contains($html, 'pipeline passed') || str_contains($html, 'status-icon--success')) {
            $data['ci_status'] = 'passed';
        } elseif (str_contains($html, 'status-icon--pending') || str_contains($html, 'pipeline pending')) {
            $data['ci_status'] = 'pending';
        }

        // Extract comment count
        if (preg_match('/(\d+)\s+comments?/', $html, $matches)) {
            $data['comment_count'] = (int) $matches[1];
        }

        // Extract comments
        $data['comments'] = $this->extractComments($html);

        return $data;
    }

    private function extractComments(string $html): array
    {
        $comments = [];

        // Match comment sections - Drupal uses various patterns
        // Pattern 1: Standard comment divs with comment-* classes
        if (preg_match_all('/<article[^>]*class="[^"]*comment[^"]*"[^>]*id="comment-(\d+)"[^>]*>(.*?)<\/article>/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $comment = $this->parseCommentHtml($match[1], $match[2]);
                if ($comment) {
                    $comments[] = $comment;
                }
            }
        }

        // Pattern 2: Section-based comments (newer Drupal.org theme)
        if (empty($comments) && preg_match_all('/<section[^>]*class="[^"]*comment[^"]*"[^>]*>(.*?)<\/section>/si', $html, $matches, PREG_SET_ORDER)) {
            $commentNum = 0;
            foreach ($matches as $match) {
                $commentNum++;
                $comment = $this->parseCommentHtml((string) $commentNum, $match[1]);
                if ($comment) {
                    $comments[] = $comment;
                }
            }
        }

        // Pattern 3: Div-based comments with data attributes
        if (empty($comments) && preg_match_all('/<div[^>]*data-comment-id="(\d+)"[^>]*>(.*?)<\/div>\s*(?=<div[^>]*data-comment-id|$)/si', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $comment = $this->parseCommentHtml($match[1], $match[2]);
                if ($comment) {
                    $comments[] = $comment;
                }
            }
        }

        // Pattern 4: Try to find comment blocks by looking for typical comment structure
        if (empty($comments)) {
            // Look for divs that contain username links followed by "commented" or dates
            if (preg_match_all('/<div[^>]*class="[^"]*comment-wrapper[^"]*"[^>]*>(.*?)<\/div>\s*<\/div>/si', $html, $matches, PREG_SET_ORDER)) {
                $commentNum = 0;
                foreach ($matches as $match) {
                    $commentNum++;
                    $comment = $this->parseCommentHtml((string) $commentNum, $match[1]);
                    if ($comment) {
                        $comments[] = $comment;
                    }
                }
            }
        }

        return $comments;
    }

    private function parseCommentHtml(string $commentId, string $html): ?array
    {
        $comment = [
            'id' => $commentId,
            'author' => null,
            'date' => null,
            'content' => null,
            'status_change' => null,
            'has_attachment' => false,
            'has_patch' => false,
            'has_mr_reference' => false,
        ];

        // Extract author - look for username links
        if (preg_match('/<a[^>]*href="\/u(?:ser)?\/([^"]+)"[^>]*>([^<]+)<\/a>/i', $html, $authorMatch)) {
            $comment['author'] = trim($authorMatch[2]);
        } elseif (preg_match('/class="[^"]*username[^"]*"[^>]*>([^<]+)</i', $html, $authorMatch)) {
            $comment['author'] = trim($authorMatch[1]);
        }

        // Extract date - look for time elements or date patterns
        if (preg_match('/<time[^>]*datetime="([^"]+)"[^>]*>/i', $html, $dateMatch)) {
            $comment['date'] = $dateMatch[1];
        } elseif (preg_match('/(\d{1,2}\s+\w+\s+\d{4})/i', $html, $dateMatch)) {
            $comment['date'] = $dateMatch[1];
        }

        // Extract content - look for comment body
        if (preg_match('/<div[^>]*class="[^"]*(?:comment-body|field--name-comment-body|comment-content)[^"]*"[^>]*>(.*?)<\/div>/si', $html, $contentMatch)) {
            $comment['content'] = $this->cleanHtmlContent($contentMatch[1]);
        } elseif (preg_match('/<p[^>]*>(.*?)<\/p>/si', $html, $contentMatch)) {
            $comment['content'] = $this->cleanHtmlContent($contentMatch[1]);
        }

        // Check for status change indicators
        if (preg_match('/Status.*?changed.*?from.*?([^<]+).*?to.*?([^<]+)/si', $html, $statusMatch)) {
            $comment['status_change'] = [
                'from' => trim(strip_tags($statusMatch[1])),
                'to' => trim(strip_tags($statusMatch[2])),
            ];
        } elseif (preg_match('/&raquo;\s*([^<]+)/i', $html, $statusMatch)) {
            // Single status indicator (older format)
            $comment['status_change'] = [
                'to' => trim(strip_tags($statusMatch[1])),
            ];
        }

        // Check for attachments
        if (str_contains($html, '.patch') || str_contains($html, 'interdiff')) {
            $comment['has_patch'] = true;
            $comment['has_attachment'] = true;
        }
        if (preg_match('/\.(txt|zip|tar|gz|pdf|png|jpg|jpeg)["\'>\s]/i', $html)) {
            $comment['has_attachment'] = true;
        }

        // Check for MR references
        if (str_contains($html, 'merge_request') || str_contains($html, 'gitlab.com')) {
            $comment['has_mr_reference'] = true;
        }

        // Only return if we found meaningful content
        if ($comment['author'] || $comment['content'] || $comment['status_change']) {
            return $comment;
        }

        return null;
    }

    private function cleanHtmlContent(string $html): string
    {
        // Remove script and style tags
        $html = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $html);
        $html = preg_replace('/<style[^>]*>.*?<\/style>/si', '', $html);

        // Convert common elements to readable text
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<\/p>/i', "\n\n", $html);
        $html = preg_replace('/<\/li>/i', "\n", $html);

        // Strip remaining tags
        $text = strip_tags($html);

        // Clean up whitespace
        $text = preg_replace('/[ \t]+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = trim($text);

        return $text;
    }

    public function isActiveStatus(int $status): bool
    {
        return isset(self::ACTIVE_STATUSES[$status]);
    }

    public function getStatusName(int $status): string
    {
        return self::ALL_STATUSES[$status] ?? 'unknown';
    }
}
