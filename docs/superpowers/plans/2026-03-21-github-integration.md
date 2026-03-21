# GitHub Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add full GitHub integration to Claudriel: OAuth authentication, internal API endpoints for the agent subprocess, agent chat tools, and event ingestion into the day brief.

**Architecture:** GitHub OAuth App for auth (permanent tokens, no refresh). Internal API endpoints mirror the Google pattern (HMAC-authed, called by Python agent subprocess). Polling command fetches notifications into McEvent pipeline. DayBriefAssembler gets a new `github` section.

**Tech Stack:** PHP 8.4, Waaseyaa framework, Python 3 (agent tools), SQLite (entity storage), GitHub REST API v3

**Spec:** `docs/superpowers/specs/2026-03-21-github-integration-design.md`

---

## File Map

### New Files

| File | Responsibility |
|------|---------------|
| `src/Support/GitHubTokenManager.php` | Retrieve stored GitHub access token from Integration entity |
| `src/Support/GitHubTokenManagerInterface.php` | Interface for token manager (testability) |
| `src/Controller/GitHubOAuthController.php` | OAuth connect/callback flow |
| `src/Controller/InternalGithubController.php` | Internal API endpoints for agent subprocess |
| `src/Ingestion/GitHubNotificationNormalizer.php` | Transform GitHub notifications into Envelope objects |
| `src/Command/GitHubSyncCommand.php` | Polling command for GitHub notifications |
| `agent/tools/github_notifications.py` | Agent tool: list unread notifications |
| `agent/tools/github_list_issues.py` | Agent tool: list issues |
| `agent/tools/github_read_issue.py` | Agent tool: read single issue |
| `agent/tools/github_list_pulls.py` | Agent tool: list PRs |
| `agent/tools/github_read_pull.py` | Agent tool: read single PR |
| `agent/tools/github_create_issue.py` | Agent tool: create issue |
| `agent/tools/github_add_comment.py` | Agent tool: comment on issue/PR |

### Modified Files

| File | Changes |
|------|---------|
| `src/Provider/AccountServiceProvider.php` | Add GitHub OAuth routes (following Google OAuth pattern) |
| `src/Provider/ChatServiceProvider.php` | Add InternalGithubController singleton + internal API routes |
| `src/Provider/ClaudrielServiceProvider.php` | Add GitHubSyncCommand registration in `commands()` |
| `src/Ingestion/EventCategorizer.php` | Add `github` source branch |
| `src/Domain/DayBrief/Assembler/DayBriefAssembler.php` | Add `github` section to brief output |

### Test Files

| File | Covers |
|------|--------|
| `tests/Unit/Support/GitHubTokenManagerTest.php` | Token retrieval, revocation detection |
| `tests/Unit/Controller/GitHubOAuthControllerTest.php` | OAuth connect/callback flow |
| `tests/Unit/Controller/InternalGithubControllerTest.php` | Internal API endpoints, HMAC auth |
| `tests/Unit/Ingestion/GitHubNotificationNormalizerTest.php` | Notification → Envelope transform |
| `tests/Unit/Ingestion/EventCategorizerGitHubTest.php` | GitHub category mapping |
| `tests/Unit/Command/GitHubSyncCommandTest.php` | Sync command idempotency, error handling |
| `tests/Unit/DayBrief/DayBriefAssemblerGitHubTest.php` | GitHub section in brief |
| `agent/tests/test_github_tools.py` | Agent tool TOOL_DEF and execute functions |

---

## Task 1: GitHubTokenManager

**Files:**
- Create: `src/Support/GitHubTokenManagerInterface.php`
- Create: `src/Support/GitHubTokenManager.php`
- Create: `tests/Unit/Support/GitHubTokenManagerTest.php`

**Reference:** `src/Support/GoogleTokenManager.php` (lines 15-122) and `src/Support/GoogleTokenManagerInterface.php`

- [ ] **Step 1: Write the interface**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Support;

interface GitHubTokenManagerInterface
{
    /**
     * Get the stored GitHub access token for an account.
     *
     * GitHub OAuth Apps issue permanent tokens (no expiry, no refresh).
     * Returns the stored token or throws if no active integration exists.
     */
    public function getValidAccessToken(string $accountId): string;

    public function hasActiveIntegration(string $accountId): bool;

    /**
     * Mark the integration as revoked (e.g., after a 401 from GitHub API).
     */
    public function markRevoked(string $accountId): void;
}
```

- [ ] **Step 2: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Claudriel\Entity\Integration;
use Claudriel\Support\GitHubTokenManager;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\InMemoryStorageDriver;
use Waaseyaa\Entity\Storage\SqlEntityStorage;
use Waaseyaa\Entity\Storage\StorageRepositoryAdapter;

class GitHubTokenManagerTest extends TestCase
{
    public function testGetValidTokenReturnsStoredToken(): void
    {
        $repo = $this->buildRepoWithIntegration([
            'account_id' => 'acc-1',
            'provider' => 'github',
            'access_token' => 'ghp_test123',
            'status' => 'active',
        ]);
        $manager = new GitHubTokenManager($repo);

        $this->assertSame('ghp_test123', $manager->getValidAccessToken('acc-1'));
    }

    public function testGetValidTokenThrowsWhenNoIntegration(): void
    {
        $repo = $this->buildEmptyRepo();
        $manager = new GitHubTokenManager($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active GitHub integration');
        $manager->getValidAccessToken('acc-1');
    }

    public function testGetValidTokenThrowsWhenRevoked(): void
    {
        $repo = $this->buildRepoWithIntegration([
            'account_id' => 'acc-1',
            'provider' => 'github',
            'access_token' => 'ghp_test123',
            'status' => 'revoked',
        ]);
        $manager = new GitHubTokenManager($repo);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub integration has been revoked');
        $manager->getValidAccessToken('acc-1');
    }

    public function testHasActiveIntegration(): void
    {
        $repo = $this->buildRepoWithIntegration([
            'account_id' => 'acc-1',
            'provider' => 'github',
            'access_token' => 'ghp_test123',
            'status' => 'active',
        ]);
        $manager = new GitHubTokenManager($repo);

        $this->assertTrue($manager->hasActiveIntegration('acc-1'));
        $this->assertFalse($manager->hasActiveIntegration('acc-999'));
    }

    public function testMarkRevokedUpdatesStatus(): void
    {
        $repo = $this->buildRepoWithIntegration([
            'account_id' => 'acc-1',
            'provider' => 'github',
            'access_token' => 'ghp_test123',
            'status' => 'active',
        ]);
        $manager = new GitHubTokenManager($repo);

        $manager->markRevoked('acc-1');
        $this->assertFalse($manager->hasActiveIntegration('acc-1'));
    }

    // Helper methods build InMemoryStorageDriver-backed repos
    // following the SqlEntityStorage + StorageRepositoryAdapter pattern
}
```

Run: `vendor/bin/phpunit tests/Unit/Support/GitHubTokenManagerTest.php`
Expected: FAIL (class not found)

- [ ] **Step 3: Implement GitHubTokenManager**

Reference `GoogleTokenManager` (lines 15-122). Key differences:
- No refresh logic (permanent tokens)
- `markRevoked()` method to handle 401s
- Queries Integration by `account_id`, `provider='github'`

```php
<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Waaseyaa\Entity\EntityRepositoryInterface;

final class GitHubTokenManager implements GitHubTokenManagerInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $integrationRepo,
    ) {}

    public function getValidAccessToken(string $accountId): string
    {
        $integration = $this->findIntegration($accountId);
        if ($integration === null) {
            throw new \RuntimeException('No active GitHub integration found for this account. Connect GitHub at /github/connect');
        }

        $status = $integration->get('status');
        if ($status === 'revoked') {
            throw new \RuntimeException('GitHub integration has been revoked. Re-authorize at /github/connect');
        }

        return $integration->get('access_token');
    }

    public function hasActiveIntegration(string $accountId): bool
    {
        return $this->findIntegration($accountId) !== null;
    }

    public function markRevoked(string $accountId): void
    {
        $integrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => 'github',
        ]);

        foreach ($integrations as $integration) {
            $integration->set('status', 'revoked');
            $this->integrationRepo->save($integration);
        }
    }

    private function findIntegration(string $accountId): ?object
    {
        $results = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => 'github',
            'status' => 'active',
        ]);

        return $results[0] ?? null;
    }
}
```

- [ ] **Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Support/GitHubTokenManagerTest.php`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add src/Support/GitHubTokenManager*.php tests/Unit/Support/GitHubTokenManagerTest.php
git commit -m "feat(#423): add GitHubTokenManager for OAuth token retrieval"
```

---

## Task 2: GitHubOAuthController

**Files:**
- Create: `src/Controller/GitHubOAuthController.php`
- Create: `tests/Unit/Controller/GitHubOAuthControllerTest.php`
- Modify: `src/Provider/AccountServiceProvider.php` (routes at ~lines 250-265, following Google OAuth pattern)

**Reference:** `src/Controller/GoogleOAuthController.php` (lines 46-256), `src/Provider/AccountServiceProvider.php` (lines 250-265 for route pattern)

- [ ] **Step 1: Write failing tests**

Tests for:
- `testConnectRedirectsToGitHub` — verify redirect URL contains correct client_id, scopes, state
- `testCallbackExchangesCodeForToken` — mock HTTP response, verify Integration entity created with `provider: 'github'`
- `testCallbackUpdatesExistingIntegration` — re-auth updates tokens, doesn't create duplicate
- `testCallbackWithInvalidStateRejects` — returns error redirect

Run: `vendor/bin/phpunit tests/Unit/Controller/GitHubOAuthControllerTest.php`
Expected: FAIL (class not found)

- [ ] **Step 2: Implement GitHubOAuthController**

Follow `GoogleOAuthController` pattern exactly:
- `connect()` — build GitHub OAuth URL with `client_id`, `redirect_uri`, `scope` (repo,notifications,read:org), `state` (CSRF token stored in session)
- `callback()` — validate state, exchange code for token via `POST https://github.com/login/oauth/access_token`, fetch user info via `GET https://api.github.com/user`, call `upsertIntegration()`
- `upsertIntegration()` — query Integration by account_id + provider='github', update or create
- `resolveAccount()` — use `AuthenticatedAccountSessionResolver` (per `allowAll()` gotcha)

HTTP via `file_get_contents` with `stream_context_create` and `'ignore_errors' => true`.

- [ ] **Step 3: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Controller/GitHubOAuthControllerTest.php`
Expected: All PASS

- [ ] **Step 4: Wire routes in AccountServiceProvider**

Add to `routes()` method in `src/Provider/AccountServiceProvider.php` (after Google OAuth routes at ~line 265). Use the `RouteBuilder` pattern matching the existing Google OAuth routes:

```php
// GitHub OAuth
$router->addRoute(
    'claudriel.auth.github.connect',
    RouteBuilder::create('/github/connect')
        ->controller(GitHubOAuthController::class.'::connect')
        ->methods('GET')
        ->build(),
);

$githubCallbackRoute = RouteBuilder::create('/github/callback')
    ->controller(GitHubOAuthController::class.'::callback')
    ->allowAll()
    ->methods('GET')
    ->build();
$githubCallbackRoute->setOption('_csrf', false);
$router->addRoute('claudriel.auth.github.callback', $githubCallbackRoute);
```

`GitHubOAuthController` does NOT need explicit singleton registration. It uses auto-resolution via constructor reflection (same as `GoogleOAuthController`).

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add src/Controller/GitHubOAuthController.php tests/Unit/Controller/GitHubOAuthControllerTest.php src/Provider/AccountServiceProvider.php
git commit -m "feat(#423): add GitHub OAuth connect/callback flow"
```

---

## Task 3: InternalGithubController

**Files:**
- Create: `src/Controller/InternalGithubController.php`
- Create: `tests/Unit/Controller/InternalGithubControllerTest.php`
- Modify: `src/Provider/ChatServiceProvider.php` (singleton + routes, following InternalGoogleController pattern)

**Reference:** `src/Controller/InternalGoogleController.php` (lines 15-193), `src/Provider/ChatServiceProvider.php` (lines 104-170)

- [ ] **Step 1: Write failing tests**

Tests for:
- `testListNotificationsRequiresHmac` — no Bearer token returns 401
- `testListNotificationsReturnsFormatted` — mock GitHub API, verify JSON response
- `testListIssuesFiltersbyRepo` — repo param passed to GitHub API
- `testReadIssueIncludesComments` — fetches issue + comments
- `testListPullsReturnsFormatted` — mock GitHub API
- `testCreateIssueForwardsPayload` — verify POST body sent to GitHub
- `testAddCommentForwardsPayload` — verify POST body
- `testRepoFilteringRespectsWatchedRepos` — Integration metadata filtering

Run: `vendor/bin/phpunit tests/Unit/Controller/InternalGithubControllerTest.php`
Expected: FAIL

- [ ] **Step 2: Implement InternalGithubController**

Constructor: `GitHubTokenManagerInterface`, `InternalApiTokenGenerator`, `EntityRepositoryInterface` (for Integration metadata lookup)

Private `authenticate()` method copied from `InternalGoogleController` (lines 181-193).

Private `githubApi()` helper for `file_get_contents` calls to `api.github.com` with Bearer token and `User-Agent` header (required by GitHub API).

Methods:
- `notifications()` — `GET https://api.github.com/notifications`
- `listIssues()` — `GET https://api.github.com/repos/{owner}/{repo}/issues` (filter by query params)
- `readIssue()` — `GET https://api.github.com/repos/{owner}/{repo}/issues/{number}` + comments
- `listPulls()` — `GET https://api.github.com/repos/{owner}/{repo}/pulls`
- `readPull()` — `GET https://api.github.com/repos/{owner}/{repo}/pulls/{number}` + reviews
- `createIssue()` — `POST https://api.github.com/repos/{owner}/{repo}/issues`
- `addComment()` — `POST https://api.github.com/repos/{owner}/{repo}/issues/{number}/comments`

On 401 from GitHub: call `$tokenManager->markRevoked()`, return 502 with message.
On 403 with `X-RateLimit-Remaining: 0`: return 429 with retry-after.

- [ ] **Step 3: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Controller/InternalGithubControllerTest.php`
Expected: All PASS

- [ ] **Step 4: Wire singleton and routes in ChatServiceProvider**

Add to `src/Provider/ChatServiceProvider.php`, following the `InternalGoogleController` pattern (lines 104-170).

Singleton (after `InternalGoogleController` singleton at ~line 109):

```php
$this->singleton(InternalGithubController::class, function () {
    return new InternalGithubController(
        $this->resolve(GitHubTokenManagerInterface::class),
        $this->resolve(InternalApiTokenGenerator::class),
    );
});
```

Routes (after Google internal routes at ~line 170). Each route uses `RouteBuilder` with `->allowAll()` and `->setOption('_csrf', false)`:

```php
// Internal GitHub API (agent subprocess)
$internalGithubNotificationsRoute = RouteBuilder::create('/api/internal/github/notifications')
    ->controller(InternalGithubController::class.'::notifications')
    ->allowAll()
    ->methods('GET')
    ->build();
$internalGithubNotificationsRoute->setOption('_csrf', false);
$router->addRoute('claudriel.internal.github.notifications', $internalGithubNotificationsRoute);

// Repeat pattern for: listIssues, readIssue, listPulls, readPull (GET)
// and createIssue, addComment (POST — use ->methods('POST'))
```

Follow the exact same `RouteBuilder` pattern for all 7 endpoints listed in the spec.

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add src/Controller/InternalGithubController.php tests/Unit/Controller/InternalGithubControllerTest.php src/Provider/ChatServiceProvider.php
git commit -m "feat(#423): add internal GitHub API endpoints for agent subprocess"
```

---

## Task 4: Agent Chat Tools

**Files:**
- Create: `agent/tools/github_notifications.py`
- Create: `agent/tools/github_list_issues.py`
- Create: `agent/tools/github_read_issue.py`
- Create: `agent/tools/github_list_pulls.py`
- Create: `agent/tools/github_read_pull.py`
- Create: `agent/tools/github_create_issue.py`
- Create: `agent/tools/github_add_comment.py`
- Create: `agent/tests/test_github_tools.py`

**Reference:** `agent/tools/gmail_send.py` for TOOL_DEF + execute pattern

- [ ] **Step 1: Write test for tool definitions and executors**

```python
"""Tests for GitHub agent tools."""
import importlib
import types

GITHUB_TOOLS = [
    "github_notifications",
    "github_list_issues",
    "github_read_issue",
    "github_list_pulls",
    "github_read_pull",
    "github_create_issue",
    "github_add_comment",
]

def test_all_tools_have_valid_tool_def():
    for tool_name in GITHUB_TOOLS:
        mod = importlib.import_module(f"tools.{tool_name}")
        assert hasattr(mod, "TOOL_DEF"), f"{tool_name} missing TOOL_DEF"
        td = mod.TOOL_DEF
        assert "name" in td
        assert "description" in td
        assert "input_schema" in td
        assert td["input_schema"]["type"] == "object"

def test_all_tools_have_execute():
    for tool_name in GITHUB_TOOLS:
        mod = importlib.import_module(f"tools.{tool_name}")
        assert hasattr(mod, "execute"), f"{tool_name} missing execute"
        assert callable(mod.execute)

def test_write_tools_mention_confirmation():
    for tool_name in ["github_create_issue", "github_add_comment"]:
        mod = importlib.import_module(f"tools.{tool_name}")
        desc = mod.TOOL_DEF["description"].lower()
        assert "confirm" in desc, f"{tool_name} should mention confirmation in description"
```

Run: `cd agent && python -m pytest tests/test_github_tools.py -v`
Expected: FAIL (modules not found)

- [ ] **Step 2: Implement github_notifications.py**

```python
TOOL_DEF = {
    "name": "github_notifications",
    "description": "List unread GitHub notifications (mentions, review requests, CI status, etc.).",
    "input_schema": {
        "type": "object",
        "properties": {},
        "required": [],
    },
}

def execute(api, args: dict) -> dict:
    return api.get("/api/internal/github/notifications")
```

- [ ] **Step 3: Implement github_list_issues.py**

```python
TOOL_DEF = {
    "name": "github_list_issues",
    "description": "List issues for a GitHub repository.",
    "input_schema": {
        "type": "object",
        "properties": {
            "repo": {"type": "string", "description": "Repository in owner/repo format"},
            "state": {"type": "string", "description": "Filter by state: open, closed, all", "default": "open"},
            "labels": {"type": "string", "description": "Comma-separated label names", "default": ""},
        },
        "required": ["repo"],
    },
}

def execute(api, args: dict) -> dict:
    params = {"repo": args["repo"], "state": args.get("state", "open")}
    labels = args.get("labels", "")
    if labels:
        params["labels"] = labels
    return api.get("/api/internal/github/issues", params=params)
```

- [ ] **Step 4: Implement github_read_issue.py**

```python
TOOL_DEF = {
    "name": "github_read_issue",
    "description": "Read a single GitHub issue with its comments.",
    "input_schema": {
        "type": "object",
        "properties": {
            "owner": {"type": "string", "description": "Repository owner"},
            "repo": {"type": "string", "description": "Repository name"},
            "number": {"type": "integer", "description": "Issue number"},
        },
        "required": ["owner", "repo", "number"],
    },
}

def execute(api, args: dict) -> dict:
    owner = args["owner"]
    repo = args["repo"]
    number = args["number"]
    return api.get(f"/api/internal/github/issue/{owner}/{repo}/{number}")
```

- [ ] **Step 5: Implement github_list_pulls.py**

Same pattern as `github_list_issues.py` but hits `/api/internal/github/pulls`.

- [ ] **Step 6: Implement github_read_pull.py**

Same pattern as `github_read_issue.py` but hits `/api/internal/github/pull/{owner}/{repo}/{number}`.

- [ ] **Step 7: Implement github_create_issue.py**

```python
TOOL_DEF = {
    "name": "github_create_issue",
    "description": "Create a new GitHub issue. Requires user confirmation before executing.",
    "input_schema": {
        "type": "object",
        "properties": {
            "owner": {"type": "string", "description": "Repository owner"},
            "repo": {"type": "string", "description": "Repository name"},
            "title": {"type": "string", "description": "Issue title"},
            "body": {"type": "string", "description": "Issue body (markdown)", "default": ""},
            "labels": {"type": "array", "items": {"type": "string"}, "description": "Labels to apply", "default": []},
        },
        "required": ["owner", "repo", "title"],
    },
}

def execute(api, args: dict) -> dict:
    owner = args["owner"]
    repo = args["repo"]
    payload = {"title": args["title"]}
    if args.get("body"):
        payload["body"] = args["body"]
    if args.get("labels"):
        payload["labels"] = args["labels"]
    return api.post(f"/api/internal/github/issue/{owner}/{repo}", json_data=payload)
```

- [ ] **Step 8: Implement github_add_comment.py**

```python
TOOL_DEF = {
    "name": "github_add_comment",
    "description": "Add a comment to a GitHub issue or pull request. Requires user confirmation before executing.",
    "input_schema": {
        "type": "object",
        "properties": {
            "owner": {"type": "string", "description": "Repository owner"},
            "repo": {"type": "string", "description": "Repository name"},
            "number": {"type": "integer", "description": "Issue or PR number"},
            "body": {"type": "string", "description": "Comment body (markdown)"},
        },
        "required": ["owner", "repo", "number", "body"],
    },
}

def execute(api, args: dict) -> dict:
    owner = args["owner"]
    repo = args["repo"]
    number = args["number"]
    return api.post(f"/api/internal/github/comment/{owner}/{repo}/{number}", json_data={"body": args["body"]})
```

- [ ] **Step 9: Run tests**

Run: `cd agent && python -m pytest tests/test_github_tools.py -v`
Expected: All PASS

- [ ] **Step 10: Verify dynamic discovery**

Run: `cd agent && python -c "from main import TOOLS; print([t['name'] for t in TOOLS if 'github' in t['name']])"`
Expected: List of all 7 github tool names

- [ ] **Step 11: Commit**

```bash
git add agent/tools/github_*.py agent/tests/test_github_tools.py
git commit -m "feat(#423): add GitHub agent chat tools"
```

---

## Task 5: EventCategorizer GitHub Branch

**Files:**
- Modify: `src/Ingestion/EventCategorizer.php` (lines 25-36)
- Create: `tests/Unit/Ingestion/EventCategorizerGitHubTest.php`

**Reference:** Existing `categorize()` method at lines 25-36

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Ingestion;

use Claudriel\Ingestion\EventCategorizer;
use PHPUnit\Framework\TestCase;

class EventCategorizerGitHubTest extends TestCase
{
    private EventCategorizer $categorizer;

    protected function setUp(): void
    {
        $this->categorizer = new EventCategorizer();
    }

    public function testMentionCategorizesAsGitHubMention(): void
    {
        $this->assertSame('github_mention', $this->categorizer->categorize('github', 'mention'));
    }

    public function testReviewRequestedCategorizesAsGitHubReviewRequest(): void
    {
        $this->assertSame('github_review_request', $this->categorizer->categorize('github', 'review_requested'));
    }

    public function testAssignCategorizesAsGitHubAssignment(): void
    {
        $this->assertSame('github_assignment', $this->categorizer->categorize('github', 'assign'));
    }

    public function testCiActivityCategorizesAsGitHubCi(): void
    {
        $this->assertSame('github_ci', $this->categorizer->categorize('github', 'ci_activity'));
    }

    public function testStateChangeCategorizesAsGitHubActivity(): void
    {
        $this->assertSame('github_activity', $this->categorizer->categorize('github', 'state_change'));
    }

    public function testUnknownReasonCategorizesAsGitHubActivity(): void
    {
        $this->assertSame('github_activity', $this->categorizer->categorize('github', 'subscribed'));
    }
}
```

Run: `vendor/bin/phpunit tests/Unit/Ingestion/EventCategorizerGitHubTest.php`
Expected: FAIL (returns 'notification' instead of 'github_*')

- [ ] **Step 2: Add GitHub branch to EventCategorizer::categorize()**

Add after the existing `gmail` branch (line 32) and before the default return:

```php
if ($source === 'github') {
    return $this->categorizeGitHub($type);
}
```

Add private method:

```php
private function categorizeGitHub(string $type): string
{
    return match ($type) {
        'mention' => 'github_mention',
        'review_requested' => 'github_review_request',
        'assign' => 'github_assignment',
        'ci_activity' => 'github_ci',
        default => 'github_activity',
    };
}
```

- [ ] **Step 3: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Ingestion/EventCategorizerGitHubTest.php`
Expected: All PASS

- [ ] **Step 4: Run full test suite to verify no regressions**

Run: `vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add src/Ingestion/EventCategorizer.php tests/Unit/Ingestion/EventCategorizerGitHubTest.php
git commit -m "feat(#423): add GitHub source branch to EventCategorizer"
```

---

## Task 6: GitHubNotificationNormalizer

**Files:**
- Create: `src/Ingestion/GitHubNotificationNormalizer.php`
- Create: `tests/Unit/Ingestion/GitHubNotificationNormalizerTest.php`

**Reference:** `src/Ingestion/GmailMessageNormalizer.php` (lines 11-38)

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Ingestion;

use Claudriel\Ingestion\GitHubNotificationNormalizer;
use PHPUnit\Framework\TestCase;

class GitHubNotificationNormalizerTest extends TestCase
{
    private GitHubNotificationNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new GitHubNotificationNormalizer();
    }

    public function testNormalizerProducesEnvelope(): void
    {
        $raw = $this->buildRawNotification('mention', 'jonesrussell/claudriel', 'Issue title');
        $envelope = $this->normalizer->normalize($raw, 'tenant-1');

        $this->assertSame('github', $envelope->source);
        $this->assertSame('mention', $envelope->type);
        $this->assertSame('tenant-1', $envelope->tenantId);
        $this->assertArrayHasKey('repo', $envelope->payload);
        $this->assertArrayHasKey('title', $envelope->payload);
        $this->assertArrayHasKey('github_username', $envelope->payload);
    }

    public function testEnvelopePayloadContainsNotificationFields(): void
    {
        $raw = $this->buildRawNotification('review_requested', 'jonesrussell/claudriel', 'PR title');
        $envelope = $this->normalizer->normalize($raw, 'tenant-1');

        $this->assertSame('jonesrussell/claudriel', $envelope->payload['repo']);
        $this->assertSame('PR title', $envelope->payload['title']);
        $this->assertSame('review_requested', $envelope->type);
    }

    public function testNullFromEmailForGitHubUsers(): void
    {
        $raw = $this->buildRawNotification('mention', 'jonesrussell/claudriel', 'Test');
        $envelope = $this->normalizer->normalize($raw, 'tenant-1');

        $this->assertNull($envelope->payload['from_email']);
        $this->assertSame('octocat', $envelope->payload['from_name']);
    }

    private function buildRawNotification(string $reason, string $repo, string $title): array
    {
        return [
            'id' => '12345',
            'reason' => $reason,
            'subject' => ['title' => $title, 'type' => 'Issue', 'url' => "https://api.github.com/repos/{$repo}/issues/1"],
            'repository' => ['full_name' => $repo, 'owner' => ['login' => 'jonesrussell']],
            'updated_at' => '2026-03-21T00:00:00Z',
            'unread' => true,
            // Actor who triggered the notification (not always present)
            'actor' => ['login' => 'octocat'],
        ];
    }
}
```

Run: `vendor/bin/phpunit tests/Unit/Ingestion/GitHubNotificationNormalizerTest.php`
Expected: FAIL

- [ ] **Step 2: Implement GitHubNotificationNormalizer**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

use Waaseyaa\Foundation\Ingestion\Envelope;

final class GitHubNotificationNormalizer
{
    public function normalize(array $raw, string $tenantId): Envelope
    {
        $repo = $raw['repository']['full_name'] ?? 'unknown';
        $reason = $raw['reason'] ?? 'unknown';
        $title = $raw['subject']['title'] ?? '';
        $subjectType = $raw['subject']['type'] ?? 'Unknown';
        $subjectUrl = $raw['subject']['url'] ?? '';
        $actor = $raw['actor']['login'] ?? null;
        $updatedAt = $raw['updated_at'] ?? (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        return new Envelope(
            source: 'github',
            type: $reason,
            payload: [
                'notification_id' => $raw['id'] ?? '',
                'repo' => $repo,
                'title' => $title,
                'subject_type' => $subjectType,
                'subject_url' => $subjectUrl,
                'from_name' => $actor,
                'from_email' => null,
                'github_username' => $actor,
                'date' => $updatedAt,
            ],
            timestamp: $updatedAt,
            traceId: uniqid('github-', true),
            tenantId: $tenantId,
        );
    }
}
```

- [ ] **Step 3: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Ingestion/GitHubNotificationNormalizerTest.php`
Expected: All PASS

- [ ] **Step 4: Commit**

```bash
git add src/Ingestion/GitHubNotificationNormalizer.php tests/Unit/Ingestion/GitHubNotificationNormalizerTest.php
git commit -m "feat(#423): add GitHubNotificationNormalizer"
```

---

## Task 7: GitHubSyncCommand

**Files:**
- Create: `src/Command/GitHubSyncCommand.php`
- Create: `tests/Unit/Command/GitHubSyncCommandTest.php`
- Modify: `src/Provider/ClaudrielServiceProvider.php` (commands section)

**Reference:** Existing commands in `src/Command/` for Symfony Console pattern

- [ ] **Step 1: Write failing tests**

Tests for:
- `testSyncFetchesAndCreatesEvents` — mock GitHub API, verify McEvents created
- `testSyncDeduplicates` — same notifications twice, only one McEvent each
- `testSyncWithRevokedTokenSkips` — logs warning, exits cleanly
- `testSyncWithRateLimitLogs` — 403 rate limit response logged

Run: `vendor/bin/phpunit tests/Unit/Command/GitHubSyncCommandTest.php`
Expected: FAIL

- [ ] **Step 2: Implement GitHubSyncCommand**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Ingestion\EventHandler;
use Claudriel\Ingestion\GitHubNotificationNormalizer;
use Claudriel\Support\GitHubTokenManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:github:sync', description: 'Fetch GitHub notifications and ingest as events')]
final class GitHubSyncCommand extends Command
{
    public function __construct(
        private readonly GitHubTokenManagerInterface $tokenManager,
        private readonly EventHandler $eventHandler,
        private readonly GitHubNotificationNormalizer $normalizer,
        private readonly string $tenantId,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $token = $this->tokenManager->getValidAccessToken($this->tenantId);
        } catch (\RuntimeException $e) {
            $output->writeln("<comment>Skipping GitHub sync: {$e->getMessage()}</comment>");
            return Command::SUCCESS;
        }

        $notifications = $this->fetchNotifications($token);
        if ($notifications === null) {
            $output->writeln('<comment>GitHub API returned an error, will retry next cycle</comment>');
            return Command::SUCCESS;
        }

        $created = 0;
        foreach ($notifications as $raw) {
            $envelope = $this->normalizer->normalize($raw, $this->tenantId);
            $event = $this->eventHandler->handle($envelope);
            if ($event->isNew()) {
                $created++;
            }
        }

        $output->writeln("<info>GitHub sync: {$created} new events from " . count($notifications) . " notifications</info>");
        return Command::SUCCESS;
    }

    private function fetchNotifications(string $token): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$token}\r\nUser-Agent: Claudriel\r\nAccept: application/vnd.github+json\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $response = file_get_contents('https://api.github.com/notifications', false, $context);
        if ($response === false) {
            return null;
        }

        /** @phpstan-ignore isset.variable, booleanAnd.alwaysTrue */
        $statusLine = $http_response_header[0] ?? '';
        if (str_contains($statusLine, '401')) {
            $this->tokenManager->markRevoked($this->tenantId);
            return null;
        }
        if (str_contains($statusLine, '403')) {
            return null;
        }

        return json_decode($response, true) ?: [];
    }
}
```

- [ ] **Step 3: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Command/GitHubSyncCommandTest.php`
Expected: All PASS

- [ ] **Step 4: Register command in ClaudrielServiceProvider**

Add to `commands()` method following existing command registration pattern.

- [ ] **Step 5: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 6: Commit**

```bash
git add src/Command/GitHubSyncCommand.php tests/Unit/Command/GitHubSyncCommandTest.php src/Provider/ClaudrielServiceProvider.php
git commit -m "feat(#423): add claudriel:github:sync polling command"
```

---

## Task 8: DayBriefAssembler GitHub Section

**Files:**
- Modify: `src/Domain/DayBrief/Assembler/DayBriefAssembler.php` (lines 36-163)
- Create: `tests/Unit/DayBrief/DayBriefAssemblerGitHubTest.php`

**Reference:** Current `assemble()` method at lines 36-163

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\DayBrief;

use PHPUnit\Framework\TestCase;

class DayBriefAssemblerGitHubTest extends TestCase
{
    public function testBriefIncludesGitHubSection(): void
    {
        // Seed McEvents with github_mention, github_review_request categories
        // Call assemble()
        // Assert 'github' key exists in result
        // Assert 'mentions' and 'review_requests' sub-keys populated
    }

    public function testBriefCountsIncludeGitHub(): void
    {
        // Seed GitHub McEvents
        // Assert result['counts']['github'] > 0
    }

    public function testBriefWithoutGitHubEventsOmitsSection(): void
    {
        // No GitHub McEvents seeded
        // Assert 'github' key does NOT exist in result
    }

    public function testGitHubEventsNotInNotificationsBucket(): void
    {
        // Seed GitHub McEvents
        // Assert result['notifications'] does NOT contain github events
    }
}
```

Run: `vendor/bin/phpunit tests/Unit/DayBrief/DayBriefAssemblerGitHubTest.php`
Expected: FAIL

- [ ] **Step 2: Add GitHub section to DayBriefAssembler::assemble()**

In the event sorting loop, add GitHub category handling:
- `github_mention` → `$github['mentions'][]`
- `github_review_request` → `$github['review_requests'][]`
- `github_ci` → `$github['ci_failures'][]` (if payload indicates failure)
- `github_activity`, `github_assignment` → `$github['activity'][]` (capped at 10)

Skip GitHub events from falling into the `notifications` bucket.

Add to return array (only if non-empty):

```php
if (!empty($github['mentions']) || !empty($github['review_requests']) || !empty($github['ci_failures']) || !empty($github['activity'])) {
    $result['github'] = $github;
    $result['counts']['github'] = count($github['mentions'] ?? [])
        + count($github['review_requests'] ?? [])
        + count($github['ci_failures'] ?? [])
        + count($github['activity'] ?? []);
}
```

- [ ] **Step 3: Run tests**

Run: `vendor/bin/phpunit tests/Unit/DayBrief/DayBriefAssemblerGitHubTest.php`
Expected: All PASS

- [ ] **Step 4: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All PASS

- [ ] **Step 5: Commit**

```bash
git add src/Domain/DayBrief/Assembler/DayBriefAssembler.php tests/Unit/DayBrief/DayBriefAssemblerGitHubTest.php
git commit -m "feat(#423): add GitHub section to day brief"
```

---

## Task 9: Deploy Configuration

**Files:**
- No code files; infrastructure config only

- [ ] **Step 1: Add env vars to staging .env**

SSH to server, add to `/home/deployer/claudriel/shared/.env`:

```
GITHUB_CLIENT_ID=<staging_client_id>
GITHUB_CLIENT_SECRET=<staging_client_secret>
```

(Register GitHub OAuth App at github.com/settings/developers with callback URL `https://claudriel.northcloud.one/github/callback`)

- [ ] **Step 2: Add env vars to production .env**

Add to `/home/deployer/claudriel-prod/shared/.env`:

```
GITHUB_CLIENT_ID=<production_client_id>
GITHUB_CLIENT_SECRET=<production_client_secret>
```

(Register separate GitHub OAuth App with callback URL `https://claudriel.ai/github/callback`)

- [ ] **Step 3: Add Ansible vault entries**

Add to northcloud-ansible vault:
- `vault_claudriel_staging_github_client_id`
- `vault_claudriel_staging_github_client_secret`
- `vault_claudriel_github_client_id`
- `vault_claudriel_github_client_secret`

- [ ] **Step 4: Add cron for github:sync**

Add to Ansible-managed crontab for deployer user:

```
*/5 * * * * deployer cd /home/deployer/claudriel-prod/current && php bin/console claudriel:github:sync >> /home/deployer/claudriel-prod/shared/logs/github-sync.log 2>&1
```

- [ ] **Step 5: Deploy and verify**

Push to main, watch deploy, then:
- Visit `https://claudriel.ai/github/connect` and authorize
- Verify Integration entity created in database
- Run `claudriel:github:sync` manually and verify McEvents created
- Check `/brief` endpoint includes `github` section
