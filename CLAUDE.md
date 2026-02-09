# Drupal Issue Hygiene Helper

This is a simple helper script to assist with the Drupal issue queue. It can be used to quickly check the status of an issue, and give suggestions to add comments or change the status of an issue.

## APIs
Visit https://www.drupal.org/drupalorg/docs/apis/rest-and-other-apis

To get information about the issues in an issue queue or what got updated, you can use the following API endpoint:

```https://www.drupal.org/api-d7/node.json?type=project_issue&field_project=3346420```

The 3346420 is the project ID for Drupal AI.

To get comments for an issue (API - used only as fallback):
```https://www.drupal.org/api-d7/comment.json?node={issue_id}&sort=created&direction=ASC```

**Important:** The API often returns incomplete comment content (missing metadata changes, partial text). Therefore, we always scrape comments via Chrome browser. The API is only used to identify which issues have changed.

We scrape the website using Chrome PHP with the new headless mode and bot evasion techniques:

```php
use HeadlessChromium\BrowserFactory;
use HeadlessChromium\Page;

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
        '--headless=new',  // Use new headless mode for better performance
    ],
]);
try {
    $page = $browser->createPage();
    // Override webdriver detection
    $page->evaluate('Object.defineProperty(navigator, "webdriver", {get: () => undefined});');
    $page->navigate($url)->waitForNavigation(Page::NETWORK_IDLE, 60000);
    sleep(3); // Wait for page to fully render
    $html = $page->getHtml();
} finally {
    $browser->close();
}
```

The issue for a page would be under https://www.drupal.org/node/3346420

Note that you will ever only need to get updates to pages that are in active, needs review, needs work, patch (to be ported), postponed (maintainer needs more info) or reviewed & tested by the community status. For all other updates, you can just skip them.

### API Pagination
The Drupal.org API returns 50 results per page. The `fetchProjectIssues` method automatically paginates through all pages until:
- The requested limit is reached
- No more results are returned
- A page returns fewer than 50 results (end of data)

### API Rate Limiting
The Drupal.org API may return 429 (Too Many Requests) errors. All API calls include retry logic:
- Max 3 retries
- 5-second delay between retries
- Retries on HTTP 429, 503, and connection errors

### Field Issue Status
1 = active
2 = fixed
3 = closed (duplicate)
4 = postponed
5 = closed (won't fix)
6 = closed (works as designed)
7 = closed (fixed)
8 = needs review
13 = needs work
14 = reviewed & tested by the community
15 = patch (to be ported)
16 = postponed (maintainer needs more info)
17 = closed (outdated)
18 = closed (cannot reproduce)

## .env file
The project uses a .env file to store the API key for the OpenAI API and the project IDs for the Drupal projects. The .env file should look like this:

```
OPENAI_API_KEY=your_openai_api_key
DRUPAL_PROJECT_IDS='{"AI": 3346420, "Core": 1234567, "AnotherProject": 8901234}'
```

**Important:** The JSON value for DRUPAL_PROJECT_IDS must be wrapped in single quotes because phpdotenv cannot parse JSON with spaces otherwise.

Create an example .env file and add it to the repository, but make sure to add the actual .env file to the .gitignore file. The example can have the AI module project ID, but not the API key.

## UI
The project uses Symfony Console to create a command line interface. It uses Symfony Console's SymfonyStyle for user input and output display.

### Interactive Mode
Running `php issue.php` (or `php issue.php interactive`) starts interactive mode:
1. **Project picker first** - Select which project to work with
2. **Command menu** - Choose: Update issues, Check issues, Give suggestions, Change project, or Exit
3. The selected project is passed to all subsequent commands

### Global Parameters
All commands accept a `--project` option to specify the project name (as defined in .env). If not provided, the user is prompted to select interactively.

### Commands

#### Update Issues (`update-issues`)
Fetches latest issues from the Drupal.org API and stores them locally.
- Uses the API only to identify which issues have changed
- Always scrapes comments via Chrome browser (API may have incomplete content)
- Extracts MR status, CI status, patch information from scraped pages
- Each state file includes `state_updated_at` timestamp for filtering in suggestions
- Options: `--project`, `--limit`, `--all` (ignore last run timestamp), `--no-scrape` (fallback to API)

#### Give Suggestions (`give-suggestions`)
Analyzes stored issues and generates suggestions for improvements.
- Runs non-AI checks first (fast, deterministic)
- Then runs AI-based checks if needed (one AI request per issue max)
- Tracks `last_suggestions_run` timestamp to skip unchanged issues
- Options: `--project`, `--issue` (specific issue), `--limit`, `--all`

**Non-AI Checks (IssueChecker):**
- Patch/MR uploaded but status is "Active" -> suggest "Needs Review"
- Failing CI but status is "Needs Review" -> suggest "Needs Work"
- Issue stale for 5+ days with no activity
- Question asked but unanswered for 2+ weeks

**AI-Based Checks:**
- Bug report not detailed enough -> suggest status 16 (Postponed)
- No test steps for GUI changes -> suggest status 13 (Needs Work)
- Status changed without explanation -> suggest reverting
- RTBC with unresolved discussion -> suggest status 13 (Needs Work)
- Feature request without use case -> suggest status 16 (Postponed)

#### Check Issues (`check-issues`)
Interactive UI to review pending suggestions:
- Lists all pending suggestions with arrow key navigation
- Select an issue to view full details (title, URL, type, reason, suggested comment, suggested status)
- Actions: Mark as checked (moves to issues_checked), Open in browser, Back to list
- Options: `--project`

## AI
The project uses OpenAI API directly via Guzzle HTTP client (not Symfony AI, which had compatibility issues).

Configuration:
- Model: `gpt-4o-mini`
- Temperature: 0.3 (conservative)
- Max tokens: 1000

The AI is only used where needed. Simple checks (MR without review status, failing CI, stale issues) are done without AI. AI is used for semantic analysis like checking if bug reports are detailed enough or if discussions were resolved.

API calls include retry logic (3 retries, 5-second delay) for rate limiting.

## State handling
The project uses a simple state handling system with JSON files:
- One file per issue in each state directory
- **latest_state files are named `{changed}_{issueId}.json`** - timestamp is the issue's `changed` value from Drupal.org
- The `state_updated_at` field inside the JSON matches the filename timestamp
- Global state file tracks timestamps (last_checked, last_suggestions_run)
- Suggestions command filters files by filename timestamp to only process issues changed since last run
- State prevents duplicate processing and suggestions

### Directory structure
```
- src/
    - Command/
        - InteractiveCommand.php      # Default command, project picker + menu
        - UpdateIssuesCommand.php     # Fetch issues from API
        - GiveSuggestionsCommand.php  # Analyze issues, create suggestions
        - CheckIssuesCommand.php      # Review pending suggestions
        - Traits/
            - ProjectAwareTrait.php   # Shared project selection logic
    - Service/
        - DrupalApiService.php        # Drupal.org API + browser scraping
        - IssueChecker.php            # Non-AI + AI checks
        - AIService.php               # OpenAI API integration
    - State/
        - StateHandler.php            # JSON file state management
- state/
    .gitkeep
    - {project_id}/
      - latest_state/                 # Current issue data (scraped)
          {timestamp}_{issue_id}.json # e.g., 1707480000_3546465.json
      - issues_to_verify/             # Pending suggestions
          {issue_id}.json
      - issues_checked/               # Reviewed/actioned suggestions
          {issue_id}.json
      - global_state.json             # Timestamps and counters
- issue.php                           # Entry point
- .env
- .env.example
- .gitignore
- composer.json
- CLAUDE.md
```

Note that the state files should be added to the .gitignore file, but an example state file should be added to the repository to show the structure of the state files.

We will later sync this with s3 or something similar, but for now we can just use the local file system to store the state files.

## Example usage

Run interactively (recommended):
```
php issue.php
```

Update issues for a specific project:
```
php issue.php update-issues --project=AI
```

Update with a limit:
```
php issue.php update-issues --project=AI --limit=10
```

Generate suggestions:
```
php issue.php give-suggestions --project=AI
```

Generate suggestions for a specific issue:
```
php issue.php give-suggestions --project=AI --issue=123456
```

Review pending suggestions:
```
php issue.php check-issues --project=AI
```

## Dependencies (composer.json)
- php: ^8.2
- symfony/console: ^7.0
- guzzlehttp/guzzle: ^7.0
- vlucas/phpdotenv: ^5.6
- chrome-php/chrome: ^1.0
