# Issue Orchestrator Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a strongly stateful issue orchestrator to Claudriel that binds GitHub Issues to existing Workspace + CodexExecutionPipeline, operable through chat and CLI.

**Architecture:** IssueRun is a thin binding entity. IssueOrchestrator glues GitHubClient → Workspace → CodexExecutionPipeline. Chat integration extends existing `handleLocalAction()` pattern. GitHub client is a reusable waaseyaa package.

**Tech Stack:** PHP 8.3+, Waaseyaa entity system, Symfony Console, GitHub REST API v3, SSE streaming

**Spec:** `docs/superpowers/specs/2026-03-16-issue-orchestrator-design.md`

---

## Chunk 1: GitHub Client Package (Waaseyaa)

### Task 1: Value objects — Issue, Milestone, PullRequest

**Files:**
- Create: `packages/github/src/Issue.php`
- Create: `packages/github/src/Milestone.php`
- Create: `packages/github/src/PullRequest.php`
- Create: `packages/github/tests/Unit/IssueTest.php`

- [ ] **Step 1: Create package directory structure**

```bash
cd /home/fsd42/dev/waaseyaa
mkdir -p packages/github/src packages/github/tests/Unit
```

- [ ] **Step 2: Write the failing test for value objects**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\GitHub\Issue;
use Waaseyaa\GitHub\Milestone;
use Waaseyaa\GitHub\PullRequest;

#[CoversClass(Issue::class)]
#[CoversClass(Milestone::class)]
#[CoversClass(PullRequest::class)]
final class ValueObjectTest extends TestCase
{
    #[Test]
    public function issueHoldsAllFields(): void
    {
        $issue = new Issue(
            number: 42,
            title: 'Add orchestrator',
            body: 'Implement issue orchestrator',
            state: 'open',
            milestone: 'v1.0',
            labels: ['enhancement'],
            assignees: ['fsd42'],
        );

        $this->assertSame(42, $issue->number);
        $this->assertSame('Add orchestrator', $issue->title);
        $this->assertSame('Implement issue orchestrator', $issue->body);
        $this->assertSame('open', $issue->state);
        $this->assertSame('v1.0', $issue->milestone);
        $this->assertSame(['enhancement'], $issue->labels);
        $this->assertSame(['fsd42'], $issue->assignees);
    }

    #[Test]
    public function issueWithNullOptionalFields(): void
    {
        $issue = new Issue(
            number: 1,
            title: 'Test',
            body: '',
            state: 'open',
            milestone: null,
            labels: [],
            assignees: [],
        );

        $this->assertNull($issue->milestone);
    }

    #[Test]
    public function milestoneHoldsAllFields(): void
    {
        $milestone = new Milestone(
            number: 3,
            title: 'v1.0',
            description: 'First stable release',
            state: 'open',
            openIssues: 10,
            closedIssues: 5,
        );

        $this->assertSame(3, $milestone->number);
        $this->assertSame('v1.0', $milestone->title);
        $this->assertSame('First stable release', $milestone->description);
        $this->assertSame('open', $milestone->state);
        $this->assertSame(10, $milestone->openIssues);
        $this->assertSame(5, $milestone->closedIssues);
    }

    #[Test]
    public function pullRequestHoldsAllFields(): void
    {
        $pr = new PullRequest(
            number: 99,
            url: 'https://github.com/owner/repo/pull/99',
            title: 'feat: add orchestrator',
            state: 'open',
        );

        $this->assertSame(99, $pr->number);
        $this->assertSame('https://github.com/owner/repo/pull/99', $pr->url);
        $this->assertSame('feat: add orchestrator', $pr->title);
        $this->assertSame('open', $pr->state);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
cd /home/fsd42/dev/waaseyaa && ./vendor/bin/phpunit packages/github/tests/Unit/ValueObjectTest.php
```

Expected: FAIL — classes do not exist yet.

- [ ] **Step 4: Implement value objects**

`packages/github/src/Issue.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub;

final readonly class Issue
{
    public function __construct(
        public int $number,
        public string $title,
        public string $body,
        public string $state,
        public ?string $milestone,
        public array $labels,
        public array $assignees,
    ) {}
}
```

`packages/github/src/Milestone.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub;

final readonly class Milestone
{
    public function __construct(
        public int $number,
        public string $title,
        public string $description,
        public string $state,
        public int $openIssues,
        public int $closedIssues,
    ) {}
}
```

`packages/github/src/PullRequest.php`:
```php
<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub;

final readonly class PullRequest
{
    public function __construct(
        public int $number,
        public string $url,
        public string $title,
        public string $state,
    ) {}
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
cd /home/fsd42/dev/waaseyaa && ./vendor/bin/phpunit packages/github/tests/Unit/ValueObjectTest.php
```

Expected: 4 tests, all PASS.

- [ ] **Step 6: Commit**

```bash
cd /home/fsd42/dev/waaseyaa
git add packages/github/src/Issue.php packages/github/src/Milestone.php packages/github/src/PullRequest.php packages/github/tests/Unit/ValueObjectTest.php
git commit -m "feat(github): add Issue, Milestone, PullRequest value objects"
```

### Task 2: GitHubException and composer.json

**Files:**
- Create: `packages/github/src/GitHubException.php`
- Create: `packages/github/composer.json`

- [ ] **Step 1: Create GitHubException**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub;

final class GitHubException extends \RuntimeException
{
    public static function apiError(int $statusCode, string $message): self
    {
        return new self(sprintf('GitHub API error (%d): %s', $statusCode, $message), $statusCode);
    }

    public static function notFound(string $resource, int|string $id): self
    {
        return new self(sprintf('GitHub %s #%s not found', $resource, $id), 404);
    }
}
```

- [ ] **Step 2: Create composer.json**

```json
{
    "name": "waaseyaa/github",
    "description": "GitHub API client for issues, milestones, and pull requests",
    "type": "library",
    "license": "proprietary",
    "require": {
        "php": ">=8.3"
    },
    "autoload": {
        "psr-4": {
            "Waaseyaa\\GitHub\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Waaseyaa\\GitHub\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 3: Commit**

```bash
cd /home/fsd42/dev/waaseyaa
git add packages/github/composer.json packages/github/src/GitHubException.php
git commit -m "feat(github): add package scaffolding and GitHubException"
```

### Task 3: GitHubClient

**Files:**
- Create: `packages/github/src/GitHubClient.php`
- Create: `packages/github/tests/Unit/GitHubClientTest.php`

- [ ] **Step 1: Write the failing test**

The client uses `file_get_contents` with stream context. For testing, we subclass with a test double that captures the URL and returns canned JSON. This avoids mocking `final class`.

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\GitHub\GitHubClient;
use Waaseyaa\GitHub\GitHubException;
use Waaseyaa\GitHub\Issue;
use Waaseyaa\GitHub\Milestone;
use Waaseyaa\GitHub\PullRequest;

#[CoversClass(GitHubClient::class)]
final class GitHubClientTest extends TestCase
{
    #[Test]
    public function getIssueReturnsIssueObject(): void
    {
        $client = new class('fake-token', 'owner', 'repo') extends GitHubClient {
            protected function request(string $method, string $path, ?array $body = null): array
            {
                return [
                    'number' => 42,
                    'title' => 'Test issue',
                    'body' => 'Issue body',
                    'state' => 'open',
                    'milestone' => ['title' => 'v1.0'],
                    'labels' => [['name' => 'bug']],
                    'assignees' => [['login' => 'fsd42']],
                ];
            }
        };

        $issue = $client->getIssue(42);

        $this->assertInstanceOf(Issue::class, $issue);
        $this->assertSame(42, $issue->number);
        $this->assertSame('Test issue', $issue->title);
        $this->assertSame('v1.0', $issue->milestone);
        $this->assertSame(['bug'], $issue->labels);
        $this->assertSame(['fsd42'], $issue->assignees);
    }

    #[Test]
    public function getIssueWithNullMilestone(): void
    {
        $client = new class('fake-token', 'owner', 'repo') extends GitHubClient {
            protected function request(string $method, string $path, ?array $body = null): array
            {
                return [
                    'number' => 1,
                    'title' => 'No milestone',
                    'body' => '',
                    'state' => 'open',
                    'milestone' => null,
                    'labels' => [],
                    'assignees' => [],
                ];
            }
        };

        $issue = $client->getIssue(1);
        $this->assertNull($issue->milestone);
    }

    #[Test]
    public function listIssuesReturnsArray(): void
    {
        $client = new class('fake-token', 'owner', 'repo') extends GitHubClient {
            protected function request(string $method, string $path, ?array $body = null): array
            {
                return [
                    ['number' => 1, 'title' => 'A', 'body' => '', 'state' => 'open', 'milestone' => null, 'labels' => [], 'assignees' => []],
                    ['number' => 2, 'title' => 'B', 'body' => '', 'state' => 'open', 'milestone' => null, 'labels' => [], 'assignees' => []],
                ];
            }
        };

        $issues = $client->listIssues();
        $this->assertCount(2, $issues);
        $this->assertInstanceOf(Issue::class, $issues[0]);
    }

    #[Test]
    public function getMilestoneReturnsMilestoneObject(): void
    {
        $client = new class('fake-token', 'owner', 'repo') extends GitHubClient {
            protected function request(string $method, string $path, ?array $body = null): array
            {
                return [
                    'number' => 3,
                    'title' => 'v1.0',
                    'description' => 'First release',
                    'state' => 'open',
                    'open_issues' => 10,
                    'closed_issues' => 5,
                ];
            }
        };

        $milestone = $client->getMilestone(3);
        $this->assertInstanceOf(Milestone::class, $milestone);
        $this->assertSame('v1.0', $milestone->title);
        $this->assertSame(10, $milestone->openIssues);
    }

    #[Test]
    public function createPullRequestReturnsPrObject(): void
    {
        $client = new class('fake-token', 'owner', 'repo') extends GitHubClient {
            protected function request(string $method, string $path, ?array $body = null): array
            {
                return [
                    'number' => 99,
                    'html_url' => 'https://github.com/owner/repo/pull/99',
                    'title' => 'feat: test',
                    'state' => 'open',
                ];
            }
        };

        $pr = $client->createPullRequest('feat: test', 'issue-42', 'main', 'PR body');
        $this->assertInstanceOf(PullRequest::class, $pr);
        $this->assertSame('https://github.com/owner/repo/pull/99', $pr->url);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/fsd42/dev/waaseyaa && ./vendor/bin/phpunit packages/github/tests/Unit/GitHubClientTest.php
```

Expected: FAIL — `GitHubClient` class does not exist.

- [ ] **Step 3: Implement GitHubClient**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\GitHub;

class GitHubClient
{
    private string $baseUrl = 'https://api.github.com';

    public function __construct(
        private readonly string $token,
        private readonly string $owner,
        private readonly string $repo,
    ) {}

    public function getIssue(int $number): Issue
    {
        $data = $this->request('GET', "/repos/{$this->owner}/{$this->repo}/issues/{$number}");

        return new Issue(
            number: $data['number'],
            title: $data['title'],
            body: $data['body'] ?? '',
            state: $data['state'],
            milestone: $data['milestone']['title'] ?? null,
            labels: array_map(fn(array $l) => $l['name'], $data['labels'] ?? []),
            assignees: array_map(fn(array $a) => $a['login'], $data['assignees'] ?? []),
        );
    }

    /** @return Issue[] */
    public function listIssues(array $filters = []): array
    {
        $query = http_build_query($filters);
        $path = "/repos/{$this->owner}/{$this->repo}/issues";
        if ($query !== '') {
            $path .= '?' . $query;
        }

        $data = $this->request('GET', $path);

        return array_map(fn(array $item) => new Issue(
            number: $item['number'],
            title: $item['title'],
            body: $item['body'] ?? '',
            state: $item['state'],
            milestone: $item['milestone']['title'] ?? null,
            labels: array_map(fn(array $l) => $l['name'], $item['labels'] ?? []),
            assignees: array_map(fn(array $a) => $a['login'], $item['assignees'] ?? []),
        ), $data);
    }

    public function getMilestone(int $number): Milestone
    {
        $data = $this->request('GET', "/repos/{$this->owner}/{$this->repo}/milestones/{$number}");

        return new Milestone(
            number: $data['number'],
            title: $data['title'],
            description: $data['description'] ?? '',
            state: $data['state'],
            openIssues: $data['open_issues'],
            closedIssues: $data['closed_issues'],
        );
    }

    /** @return Milestone[] */
    public function listMilestones(string $state = 'open'): array
    {
        $data = $this->request('GET', "/repos/{$this->owner}/{$this->repo}/milestones?state={$state}");

        return array_map(fn(array $item) => new Milestone(
            number: $item['number'],
            title: $item['title'],
            description: $item['description'] ?? '',
            state: $item['state'],
            openIssues: $item['open_issues'],
            closedIssues: $item['closed_issues'],
        ), $data);
    }

    public function createComment(int $issueNumber, string $body): void
    {
        $this->request('POST', "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}/comments", [
            'body' => $body,
        ]);
    }

    public function updateIssueState(int $issueNumber, string $state): void
    {
        $this->request('PATCH', "/repos/{$this->owner}/{$this->repo}/issues/{$issueNumber}", [
            'state' => $state,
        ]);
    }

    public function createPullRequest(string $title, string $head, string $base, string $body): PullRequest
    {
        $data = $this->request('POST', "/repos/{$this->owner}/{$this->repo}/pulls", [
            'title' => $title,
            'head' => $head,
            'base' => $base,
            'body' => $body,
        ]);

        return new PullRequest(
            number: $data['number'],
            url: $data['html_url'],
            title: $data['title'],
            state: $data['state'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Waaseyaa-GitHub-Client',
        ];

        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body !== null ? json_encode($body, JSON_THROW_ON_ERROR) : null,
                'ignore_errors' => true,
            ],
        ]);

        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            throw GitHubException::apiError(0, 'Request failed: ' . $url);
        }

        // Parse status code from response headers
        $statusCode = 200;
        if (isset($http_response_header[0])) {
            preg_match('/HTTP\/\S+\s+(\d+)/', $http_response_header[0], $matches);
            $statusCode = (int) ($matches[1] ?? 200);
        }

        if ($statusCode === 404) {
            throw GitHubException::notFound('resource', $path);
        }

        if ($statusCode >= 400) {
            throw GitHubException::apiError($statusCode, $response);
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }
}
```

Note: `GitHubClient` is `class` (not `final class`) so tests can extend it to override `request()`. This is the only non-final class — justified by the need to test without hitting the real API.

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /home/fsd42/dev/waaseyaa && ./vendor/bin/phpunit packages/github/tests/Unit/GitHubClientTest.php
```

Expected: 5 tests, all PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/fsd42/dev/waaseyaa
git add packages/github/src/GitHubClient.php packages/github/tests/Unit/GitHubClientTest.php
git commit -m "feat(github): add GitHubClient with issue, milestone, PR operations"
```

### Task 4: Wire package into waaseyaa root composer.json

**Files:**
- Modify: `/home/fsd42/dev/waaseyaa/composer.json`
- Modify: `/home/fsd42/dev/waaseyaa/phpunit.xml.dist`

- [ ] **Step 1: Add path repository and require entry to root composer.json**

Add to `repositories` array:
```json
{"type": "path", "url": "packages/github"}
```

Add to `require`:
```json
"waaseyaa/github": "@dev"
```

- [ ] **Step 2: Add test suite entry to phpunit.xml.dist**

Add alongside existing package test directories:
```xml
<testsuite name="GitHub">
    <directory>packages/github/tests</directory>
</testsuite>
```

- [ ] **Step 3: Run composer update and verify**

```bash
cd /home/fsd42/dev/waaseyaa && composer update waaseyaa/github
```

- [ ] **Step 4: Run the full GitHub test suite from root**

```bash
cd /home/fsd42/dev/waaseyaa && ./vendor/bin/phpunit --testsuite GitHub
```

Expected: 9 tests (4 value object + 5 client), all PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/fsd42/dev/waaseyaa
git add composer.json composer.lock phpunit.xml.dist
git commit -m "chore: wire packages/github into root composer and test suite"
```

---

## Chunk 2: IssueRun Entity (Claudriel)

### Task 5: IssueRun entity and persistence test

**Files:**
- Create: `/home/fsd42/dev/claudriel/src/Entity/IssueRun.php`
- Create: `/home/fsd42/dev/claudriel/tests/Unit/Entity/IssueRunTest.php`

- [ ] **Step 1: Write the failing test**

Follow the same pattern as other claudriel entity tests. Uses in-memory SQLite via `PdoDatabase::createSqlite()`.

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\IssueRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueRun::class)]
final class IssueRunTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaults(): void
    {
        $run = new IssueRun();

        $this->assertSame('pending', $run->get('status'));
        $this->assertSame('[]', $run->get('event_log'));
    }

    #[Test]
    public function constructorAcceptsValues(): void
    {
        $run = new IssueRun([
            'issue_number' => 42,
            'issue_title' => 'Test issue',
            'issue_body' => 'Body text',
            'milestone_title' => 'v1.0',
            'workspace_id' => 7,
            'branch_name' => 'issue-42',
            'status' => 'running',
        ]);

        $this->assertSame(42, $run->get('issue_number'));
        $this->assertSame('Test issue', $run->get('issue_title'));
        $this->assertSame('Body text', $run->get('issue_body'));
        $this->assertSame('v1.0', $run->get('milestone_title'));
        $this->assertSame(7, $run->get('workspace_id'));
        $this->assertSame('issue-42', $run->get('branch_name'));
        $this->assertSame('running', $run->get('status'));
    }

    #[Test]
    public function entityTypeIdIsCorrect(): void
    {
        $run = new IssueRun();
        $this->assertSame('issue_run', $run->getEntityTypeId());
    }

    #[Test]
    public function labelKeyIsIssueTitle(): void
    {
        $run = new IssueRun(['issue_title' => 'My issue']);
        $this->assertSame('My issue', $run->label());
    }

    #[Test]
    public function eventLogDefaultsToEmptyJsonArray(): void
    {
        $run = new IssueRun();
        $decoded = json_decode($run->get('event_log'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $decoded);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Entity/IssueRunTest.php
```

Expected: FAIL — `IssueRun` class does not exist.

- [ ] **Step 3: Implement IssueRun entity**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class IssueRun extends ContentEntityBase
{
    protected string $entityTypeId = 'issue_run';

    protected array $entityKeys = [
        'id' => 'irid',
        'uuid' => 'uuid',
        'label' => 'issue_title',
    ];

    public function __construct(array $values = [])
    {
        $values += [
            'status' => 'pending',
            'event_log' => '[]',
        ];
        parent::__construct($values, 'issue_run', $this->entityKeys);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Entity/IssueRunTest.php
```

Expected: 5 tests, all PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/fsd42/dev/claudriel
git add src/Entity/IssueRun.php tests/Unit/Entity/IssueRunTest.php
git commit -m "feat: add IssueRun entity with defaults and persistence tests"
```

### Task 6: Register IssueRun entity type in ClaudrielServiceProvider

**Files:**
- Modify: `/home/fsd42/dev/claudriel/src/Provider/ClaudrielServiceProvider.php`

- [ ] **Step 1: Add entity type registration**

In `ClaudrielServiceProvider::register()`, after the existing `operation` entity type registration, add:

```php
$this->entityType(new EntityType(
    id: 'issue_run',
    label: 'Issue Run',
    class: \Claudriel\Entity\IssueRun::class,
    keys: ['id' => 'irid', 'uuid' => 'uuid', 'label' => 'issue_title'],
    group: 'orchestration',
    fieldDefinitions: [
        'issue_number' => ['type' => 'integer', 'label' => 'Issue Number'],
        'issue_title' => ['type' => 'string', 'label' => 'Issue Title'],
        'issue_body' => ['type' => 'text_long', 'label' => 'Issue Body'],
        'milestone_title' => ['type' => 'string', 'label' => 'Milestone'],
        'workspace_id' => ['type' => 'integer', 'label' => 'Workspace ID'],
        'status' => ['type' => 'string', 'label' => 'Status'],
        'branch_name' => ['type' => 'string', 'label' => 'Branch Name'],
        'pr_url' => ['type' => 'string', 'label' => 'PR URL'],
        'last_agent_output' => ['type' => 'text_long', 'label' => 'Last Agent Output'],
        'event_log' => ['type' => 'text_long', 'label' => 'Event Log'],
    ],
));
```

- [ ] **Step 2: Delete stale manifest cache if it exists**

```bash
rm -f /home/fsd42/dev/claudriel/storage/framework/packages.php
```

- [ ] **Step 3: Run existing tests to verify no regression**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit
```

Expected: All existing tests still pass.

- [ ] **Step 4: Commit**

```bash
cd /home/fsd42/dev/claudriel
git add src/Provider/ClaudrielServiceProvider.php
git commit -m "feat: register issue_run entity type in ClaudrielServiceProvider"
```

---

## Chunk 3: IssueInstructionBuilder + IssueIntentDetector (Claudriel)

### Task 7: IssueInstructionBuilder

**Files:**
- Create: `/home/fsd42/dev/claudriel/src/Domain/IssueInstructionBuilder.php`
- Create: `/home/fsd42/dev/claudriel/tests/Unit/Domain/IssueInstructionBuilderTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain;

use Claudriel\Domain\IssueInstructionBuilder;
use Claudriel\Entity\IssueRun;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueInstructionBuilder::class)]
final class IssueInstructionBuilderTest extends TestCase
{
    private IssueInstructionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new IssueInstructionBuilder();
    }

    #[Test]
    public function instructionIncludesIssueTitle(): void
    {
        $run = $this->makeRun(['issue_title' => 'Add user auth']);
        $instruction = $this->builder->build($run, $this->makeWorkspace());

        $this->assertStringContainsString('Add user auth', $instruction);
    }

    #[Test]
    public function instructionIncludesIssueBody(): void
    {
        $run = $this->makeRun(['issue_body' => 'Implement OAuth2 flow']);
        $instruction = $this->builder->build($run, $this->makeWorkspace());

        $this->assertStringContainsString('Implement OAuth2 flow', $instruction);
    }

    #[Test]
    public function instructionIncludesMilestoneContext(): void
    {
        $run = $this->makeRun(['milestone_title' => 'v1.0']);
        $instruction = $this->builder->build($run, $this->makeWorkspace());

        $this->assertStringContainsString('v1.0', $instruction);
    }

    #[Test]
    public function instructionIncludesRunUuid(): void
    {
        $run = $this->makeRun();
        $run->set('uuid', 'abc-123-def');
        $instruction = $this->builder->build($run, $this->makeWorkspace());

        $this->assertStringContainsString('abc-123-def', $instruction);
    }

    #[Test]
    public function instructionIncludesResumeContext(): void
    {
        $run = $this->makeRun(['last_agent_output' => 'Added entity class, tests passing']);
        $instruction = $this->builder->build($run, $this->makeWorkspace());

        $this->assertStringContainsString('Added entity class, tests passing', $instruction);
    }

    #[Test]
    public function instructionExcludesResumeContextOnFirstRun(): void
    {
        $run = $this->makeRun(['last_agent_output' => null]);
        $instruction = $this->builder->build($run, $this->makeWorkspace());

        $this->assertStringNotContainsString('Previous progress', $instruction);
    }

    private function makeRun(array $overrides = []): IssueRun
    {
        return new IssueRun(array_merge([
            'issue_number' => 42,
            'issue_title' => 'Test issue',
            'issue_body' => 'Issue body text',
            'milestone_title' => 'v1.0',
            'branch_name' => 'issue-42',
            'status' => 'running',
        ], $overrides));
    }

    private function makeWorkspace(): Workspace
    {
        return new Workspace([
            'name' => 'test-workspace',
            'repo_path' => '/tmp/test-repo',
            'branch' => 'issue-42',
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Domain/IssueInstructionBuilderTest.php
```

Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement IssueInstructionBuilder**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain;

use Claudriel\Entity\IssueRun;
use Claudriel\Entity\Workspace;

final class IssueInstructionBuilder
{
    public function build(IssueRun $run, Workspace $workspace): string
    {
        $sections = [];

        // Run header for traceability
        $uuid = $run->get('uuid') ?? 'unknown';
        $sections[] = "## Issue Run: {$uuid}";

        // Issue context
        $sections[] = "## Issue #{$run->get('issue_number')}: {$run->get('issue_title')}";
        $sections[] = $run->get('issue_body') ?? '';

        // Milestone context
        $milestone = $run->get('milestone_title');
        if ($milestone !== null && $milestone !== '') {
            $sections[] = "**Milestone:** {$milestone}";
        }

        // Guardrails
        $sections[] = implode("\n", [
            '## Guardrails',
            '- Follow the coding standards in CLAUDE.md',
            '- Write tests for new functionality',
            '- Use small, focused commits',
            '- Do not modify files outside the scope of this issue',
        ]);

        // Resume context
        $lastOutput = $run->get('last_agent_output');
        if ($lastOutput !== null && $lastOutput !== '') {
            $sections[] = "## Previous progress\n\n{$lastOutput}";
        }

        return implode("\n\n", array_filter($sections));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Domain/IssueInstructionBuilderTest.php
```

Expected: 6 tests, all PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/fsd42/dev/claudriel
git add src/Domain/IssueInstructionBuilder.php tests/Unit/Domain/IssueInstructionBuilderTest.php
git commit -m "feat: add IssueInstructionBuilder for deterministic agent prompts"
```

### Task 8: OrchestratorIntent value object and IssueIntentDetector

**Files:**
- Create: `/home/fsd42/dev/claudriel/src/Domain/Chat/OrchestratorIntent.php`
- Create: `/home/fsd42/dev/claudriel/src/Domain/Chat/IssueIntentDetector.php`
- Create: `/home/fsd42/dev/claudriel/tests/Unit/Domain/Chat/IssueIntentDetectorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\IssueIntentDetector;
use Claudriel\Domain\Chat\OrchestratorIntent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueIntentDetector::class)]
#[CoversClass(OrchestratorIntent::class)]
final class IssueIntentDetectorTest extends TestCase
{
    #[Test]
    public function detectRunIssueIntent(): void
    {
        $intent = IssueIntentDetector::detect('run issue #123');

        $this->assertNotNull($intent);
        $this->assertSame('run_issue', $intent->action);
        $this->assertSame(123, $intent->params['issueNumber']);
    }

    #[Test]
    public function detectWorkOnIssueVariant(): void
    {
        $intent = IssueIntentDetector::detect('work on issue #45');

        $this->assertNotNull($intent);
        $this->assertSame('run_issue', $intent->action);
        $this->assertSame(45, $intent->params['issueNumber']);
    }

    #[Test]
    public function detectStartIssueVariant(): void
    {
        $intent = IssueIntentDetector::detect('start issue #7');

        $this->assertNotNull($intent);
        $this->assertSame('run_issue', $intent->action);
        $this->assertSame(7, $intent->params['issueNumber']);
    }

    #[Test]
    public function detectShowRunIntent(): void
    {
        $intent = IssueIntentDetector::detect('show run abc-123-def');

        $this->assertNotNull($intent);
        $this->assertSame('show_run', $intent->action);
        $this->assertSame('abc-123-def', $intent->params['runId']);
    }

    #[Test]
    public function detectStatusOfRunVariant(): void
    {
        $intent = IssueIntentDetector::detect('status of run abc-123');

        $this->assertNotNull($intent);
        $this->assertSame('show_run', $intent->action);
    }

    #[Test]
    public function detectListRunsIntent(): void
    {
        $intent = IssueIntentDetector::detect('list runs');

        $this->assertNotNull($intent);
        $this->assertSame('list_runs', $intent->action);
        $this->assertSame([], $intent->params);
    }

    #[Test]
    public function detectShowAllRunsVariant(): void
    {
        $intent = IssueIntentDetector::detect('show all runs');

        $this->assertNotNull($intent);
        $this->assertSame('list_runs', $intent->action);
    }

    #[Test]
    public function detectActiveRunsVariant(): void
    {
        $intent = IssueIntentDetector::detect('active runs');

        $this->assertNotNull($intent);
        $this->assertSame('list_runs', $intent->action);
    }

    #[Test]
    public function detectShowDiffIntent(): void
    {
        $intent = IssueIntentDetector::detect('diff for run abc-123');

        $this->assertNotNull($intent);
        $this->assertSame('show_diff', $intent->action);
        $this->assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detectShowDiffVariant(): void
    {
        $intent = IssueIntentDetector::detect('show diff abc-123');

        $this->assertNotNull($intent);
        $this->assertSame('show_diff', $intent->action);
    }

    #[Test]
    public function detectPauseRunIntent(): void
    {
        $intent = IssueIntentDetector::detect('pause run abc-123');

        $this->assertNotNull($intent);
        $this->assertSame('pause_run', $intent->action);
        $this->assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detectResumeRunIntent(): void
    {
        $intent = IssueIntentDetector::detect('resume run abc-123');

        $this->assertNotNull($intent);
        $this->assertSame('resume_run', $intent->action);
        $this->assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detectAbortRunIntent(): void
    {
        $intent = IssueIntentDetector::detect('abort run abc-123');

        $this->assertNotNull($intent);
        $this->assertSame('abort_run', $intent->action);
        $this->assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function unrecognizedMessageReturnsNull(): void
    {
        $this->assertNull(IssueIntentDetector::detect('hello, how are you?'));
        $this->assertNull(IssueIntentDetector::detect('what is the weather like?'));
        $this->assertNull(IssueIntentDetector::detect('tell me about issue 123'));
    }

    #[Test]
    public function caseInsensitiveDetection(): void
    {
        $intent = IssueIntentDetector::detect('Run Issue #123');

        $this->assertNotNull($intent);
        $this->assertSame('run_issue', $intent->action);
        $this->assertSame(123, $intent->params['issueNumber']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Domain/Chat/IssueIntentDetectorTest.php
```

Expected: FAIL — classes do not exist.

- [ ] **Step 3: Implement OrchestratorIntent**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

final readonly class OrchestratorIntent
{
    public function __construct(
        public string $action,
        public array $params = [],
    ) {}
}
```

- [ ] **Step 4: Implement IssueIntentDetector**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

final class IssueIntentDetector
{
    private const PATTERNS = [
        // "run issue #123", "work on issue #123", "start issue #123"
        '/^(?:run|work\s+on|start)\s+issue\s+#?(\d+)$/i' => 'run_issue',
        // "show run {id}", "status of run {id}"
        '/^(?:show|status\s+of)\s+run\s+([\w-]+)$/i' => 'show_run',
        // "list runs", "show all runs", "active runs"
        '/^(?:list\s+runs|show\s+all\s+runs|active\s+runs)$/i' => 'list_runs',
        // "diff for run {id}", "show diff {id}"
        '/^(?:diff\s+for\s+run|show\s+diff)\s+([\w-]+)$/i' => 'show_diff',
        // "pause run {id}"
        '/^pause\s+run\s+([\w-]+)$/i' => 'pause_run',
        // "resume run {id}"
        '/^resume\s+run\s+([\w-]+)$/i' => 'resume_run',
        // "abort run {id}"
        '/^abort\s+run\s+([\w-]+)$/i' => 'abort_run',
    ];

    public static function detect(string $message): ?OrchestratorIntent
    {
        $message = trim($message);

        foreach (self::PATTERNS as $pattern => $action) {
            if (preg_match($pattern, $message, $matches)) {
                $params = match ($action) {
                    'run_issue' => ['issueNumber' => (int) $matches[1]],
                    'list_runs' => [],
                    default => ['runId' => $matches[1]],
                };

                return new OrchestratorIntent($action, $params);
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Domain/Chat/IssueIntentDetectorTest.php
```

Expected: 15 tests, all PASS.

- [ ] **Step 6: Commit**

```bash
cd /home/fsd42/dev/claudriel
git add src/Domain/Chat/OrchestratorIntent.php src/Domain/Chat/IssueIntentDetector.php tests/Unit/Domain/Chat/IssueIntentDetectorTest.php
git commit -m "feat: add IssueIntentDetector for chat-based orchestrator control"
```

---

## Chunk 4: IssueOrchestrator Service (Claudriel)

### Task 9: IssueOrchestrator — createRun and lifecycle

**Files:**
- Create: `/home/fsd42/dev/claudriel/src/Domain/IssueOrchestrator.php`
- Create: `/home/fsd42/dev/claudriel/tests/Unit/Domain/IssueOrchestratorTest.php`

- [ ] **Step 1: Write the failing test**

This test uses anonymous classes for `GitHubClient` (override `request()`) and real in-memory entity storage. `CodexExecutionPipeline` is not `final` in the test — we wrap it in a recording spy since its constructor has heavy deps. Instead we test the orchestrator's logic around it: status transitions, workspace creation, event logging.

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain;

use Claudriel\Domain\IssueInstructionBuilder;
use Claudriel\Domain\IssueOrchestrator;
use Claudriel\Entity\IssueRun;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GitHub\GitHubClient;
use Waaseyaa\GitHub\Issue;

#[CoversClass(IssueOrchestrator::class)]
final class IssueOrchestratorTest extends TestCase
{
    #[Test]
    public function createRunFetchesIssueAndCreatesWorkspace(): void
    {
        $orchestrator = $this->buildOrchestrator();

        $run = $orchestrator->createRun(42);

        $this->assertInstanceOf(IssueRun::class, $run);
        $this->assertSame(42, $run->get('issue_number'));
        $this->assertSame('Test issue', $run->get('issue_title'));
        $this->assertSame('Issue body', $run->get('issue_body'));
        $this->assertSame('v1.0', $run->get('milestone_title'));
        $this->assertSame('issue-42', $run->get('branch_name'));
        $this->assertSame('pending', $run->get('status'));
    }

    #[Test]
    public function createRunSetsWorkspaceId(): void
    {
        $orchestrator = $this->buildOrchestrator();

        $run = $orchestrator->createRun(42);

        $this->assertNotNull($run->get('workspace_id'));
    }

    #[Test]
    public function createRunAppendsCreatedEvent(): void
    {
        $orchestrator = $this->buildOrchestrator();

        $run = $orchestrator->createRun(42);

        $events = json_decode($run->get('event_log'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(1, $events);
        $this->assertSame('created', $events[0]['type']);
        $this->assertSame(42, $events[0]['issue']);
    }

    #[Test]
    public function pauseRunSetsStatusAndAppendsEvent(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run = $orchestrator->createRun(42);
        // Simulate startRun by setting status to running
        $run->set('status', 'running');

        $orchestrator->pauseRun($run);

        $this->assertSame('paused', $run->get('status'));
        $events = json_decode($run->get('event_log'), true, 512, JSON_THROW_ON_ERROR);
        $lastEvent = end($events);
        $this->assertSame('status_change', $lastEvent['type']);
        $this->assertSame('running', $lastEvent['from']);
        $this->assertSame('paused', $lastEvent['to']);
    }

    #[Test]
    public function abortRunSetsFailedStatus(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run = $orchestrator->createRun(42);
        $run->set('status', 'running');

        $orchestrator->abortRun($run);

        $this->assertSame('failed', $run->get('status'));
    }

    #[Test]
    public function invalidStatusTransitionThrows(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run = $orchestrator->createRun(42);
        // Status is 'pending' — cannot go directly to 'completed'

        $this->expectException(\InvalidArgumentException::class);
        $orchestrator->completeRun($run);
    }

    #[Test]
    public function invalidTransitionFromCompletedThrows(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run = $orchestrator->createRun(42);
        $run->set('status', 'completed');

        $this->expectException(\InvalidArgumentException::class);
        $orchestrator->pauseRun($run);
    }

    #[Test]
    public function listRunsReturnsAllRuns(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $orchestrator->createRun(1);
        $orchestrator->createRun(2);

        $runs = $orchestrator->listRuns();
        $this->assertCount(2, $runs);
    }

    #[Test]
    public function summarizeRunReturnsString(): void
    {
        $orchestrator = $this->buildOrchestrator();
        $run = $orchestrator->createRun(42);

        $summary = $orchestrator->summarizeRun($run);

        $this->assertStringContainsString('#42', $summary);
        $this->assertStringContainsString('Test issue', $summary);
        $this->assertStringContainsString('pending', $summary);
    }

    private function buildOrchestrator(): IssueOrchestrator
    {
        // Fake GitHubClient that returns canned data
        $gitHubClient = new class('fake', 'owner', 'repo') extends GitHubClient {
            private int $issueCounter = 0;
            protected function request(string $method, string $path, ?array $body = null): array
            {
                // Return different data based on issue number in path
                return [
                    'number' => ++$this->issueCounter,
                    'title' => 'Test issue',
                    'body' => 'Issue body',
                    'state' => 'open',
                    'milestone' => ['title' => 'v1.0'],
                    'labels' => [],
                    'assignees' => [],
                ];
            }
        };

        // Real EntityTypeManager with in-memory storage
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $entityTypeManager = new EntityTypeManager($dispatcher, function ($definition) {
            $db = \Waaseyaa\Database\PdoDatabase::createSqlite();
            $schema = new \Waaseyaa\EntityStorage\Schema\SqlSchemaHandler($db, $definition);
            $schema->createTable();
            return new \Waaseyaa\EntityStorage\SqlEntityStorage($db, $definition, $dispatcher);
        });

        // Register required entity types
        $entityTypeManager->addDefinition(new EntityType(
            id: 'issue_run',
            label: 'Issue Run',
            class: IssueRun::class,
            keys: ['id' => 'irid', 'uuid' => 'uuid', 'label' => 'issue_title'],
            fieldDefinitions: [
                'issue_number' => ['type' => 'integer', 'label' => 'Issue Number'],
                'issue_title' => ['type' => 'string', 'label' => 'Issue Title'],
                'issue_body' => ['type' => 'text_long', 'label' => 'Issue Body'],
                'milestone_title' => ['type' => 'string', 'label' => 'Milestone'],
                'workspace_id' => ['type' => 'integer', 'label' => 'Workspace ID'],
                'status' => ['type' => 'string', 'label' => 'Status'],
                'branch_name' => ['type' => 'string', 'label' => 'Branch Name'],
                'pr_url' => ['type' => 'string', 'label' => 'PR URL'],
                'last_agent_output' => ['type' => 'text_long', 'label' => 'Last Agent Output'],
                'event_log' => ['type' => 'text_long', 'label' => 'Event Log'],
            ],
        ));
        $entityTypeManager->addDefinition(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'name' => ['type' => 'string', 'label' => 'Name'],
                'repo_path' => ['type' => 'string', 'label' => 'Repo Path'],
                'branch' => ['type' => 'string', 'label' => 'Branch'],
                'description' => ['type' => 'string', 'label' => 'Description'],
                'tenant_id' => ['type' => 'string', 'label' => 'Tenant ID'],
            ],
        ));

        return new IssueOrchestrator(
            entityTypeManager: $entityTypeManager,
            gitHubClient: $gitHubClient,
            pipeline: null, // Not testing execution in unit tests
            instructionBuilder: new IssueInstructionBuilder(),
            gitOperator: null, // Not testing git ops in unit tests
        );
    }
}
```

Note: `pipeline` and `gitOperator` are passed as `null` because unit tests don't exercise execution. These params should be nullable in the constructor — `?CodexExecutionPipeline` and `?GitOperator`.

- [ ] **Step 2: Run test to verify it fails**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Domain/IssueOrchestratorTest.php
```

Expected: FAIL — `IssueOrchestrator` class does not exist.

- [ ] **Step 3: Implement IssueOrchestrator**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain;

use Claudriel\AI\CodexExecutionPipeline;
use Claudriel\Entity\IssueRun;
use Claudriel\Entity\Workspace;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\GitHub\GitHubClient;

final class IssueOrchestrator
{
    private const VALID_TRANSITIONS = [
        'pending' => ['running'],
        'running' => ['paused', 'failed', 'completed'],
        'paused' => ['running', 'failed'],
        'failed' => ['pending'],
        'completed' => [],
    ];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly GitHubClient $gitHubClient,
        private readonly ?CodexExecutionPipeline $pipeline,
        private readonly IssueInstructionBuilder $instructionBuilder,
        private readonly ?GitOperator $gitOperator,
    ) {}

    public function createRun(int $issueNumber): IssueRun
    {
        $issue = $this->gitHubClient->getIssue($issueNumber);

        // Create or reuse workspace
        $workspace = $this->findOrCreateWorkspace($issueNumber);

        $run = new IssueRun([
            'issue_number' => $issue->number,
            'issue_title' => $issue->title,
            'issue_body' => $issue->body,
            'milestone_title' => $issue->milestone,
            'workspace_id' => $workspace->id(),
            'branch_name' => 'issue-' . $issueNumber,
        ]);

        $this->appendEvent($run, ['type' => 'created', 'issue' => $issueNumber]);

        $storage = $this->entityTypeManager->getStorage('issue_run');
        $run->enforceIsNew();
        $storage->create($run);

        return $run;
    }

    public function startRun(IssueRun $run): void
    {
        $this->transitionStatus($run, 'running');

        if ($this->pipeline !== null) {
            $workspace = $this->loadWorkspace($run);
            $instruction = $this->instructionBuilder->build($run, $workspace);
            $this->pipeline->execute($workspace, $instruction);
        }
    }

    public function pauseRun(IssueRun $run): void
    {
        $this->transitionStatus($run, 'paused');
    }

    public function resumeRun(IssueRun $run): void
    {
        $this->transitionStatus($run, 'running');

        if ($this->pipeline !== null) {
            $workspace = $this->loadWorkspace($run);
            $instruction = $this->instructionBuilder->build($run, $workspace);
            $this->pipeline->execute($workspace, $instruction);
        }
    }

    public function abortRun(IssueRun $run): void
    {
        $this->transitionStatus($run, 'failed');
        $this->appendEvent($run, ['type' => 'aborted']);
        $this->saveRun($run);
    }

    public function completeRun(IssueRun $run): void
    {
        $this->transitionStatus($run, 'completed');

        // Create PR if there are changes
        $diff = $this->getWorkspaceDiff($run);
        if ($diff !== '') {
            $pr = $this->gitHubClient->createPullRequest(
                title: "feat(#{$run->get('issue_number')}): {$run->get('issue_title')}",
                head: $run->get('branch_name'),
                base: 'main',
                body: "Resolves #{$run->get('issue_number')}\n\nAutomated by Claudriel Issue Orchestrator.",
            );
            $run->set('pr_url', $pr->url);
            $this->appendEvent($run, ['type' => 'pr_created', 'url' => $pr->url]);
        }

        $this->saveRun($run);
    }

    public function getRun(string $uuid): ?IssueRun
    {
        $storage = $this->entityTypeManager->getStorage('issue_run');
        $entities = $storage->loadByProperties(['uuid' => $uuid]);

        return $entities[0] ?? null;
    }

    public function getRunByIssue(int $issueNumber): ?IssueRun
    {
        $storage = $this->entityTypeManager->getStorage('issue_run');
        $entities = $storage->loadByProperties(['issue_number' => $issueNumber]);

        // Return the most recent active run
        foreach (array_reverse($entities) as $entity) {
            if (in_array($entity->get('status'), ['pending', 'running', 'paused'], true)) {
                return $entity;
            }
        }

        return null;
    }

    /** @return IssueRun[] */
    public function listRuns(?string $status = null): array
    {
        $storage = $this->entityTypeManager->getStorage('issue_run');

        if ($status !== null) {
            return $storage->loadByProperties(['status' => $status]);
        }

        return $storage->loadMultiple();
    }

    public function getWorkspaceDiff(IssueRun $run): string
    {
        if ($this->gitOperator === null) {
            return '';
        }

        $workspace = $this->loadWorkspace($run);
        $repoPath = $workspace->get('repo_path');

        if ($repoPath === null || !is_dir($repoPath)) {
            return '';
        }

        return $this->gitOperator->diff($repoPath) ?? '';
    }

    public function summarizeRun(IssueRun $run): string
    {
        $lines = [];
        $lines[] = "**Issue #{$run->get('issue_number')}:** {$run->get('issue_title')}";
        $lines[] = "**Status:** {$run->get('status')}";
        $lines[] = "**Branch:** {$run->get('branch_name')}";

        $prUrl = $run->get('pr_url');
        if ($prUrl !== null && $prUrl !== '') {
            $lines[] = "**PR:** {$prUrl}";
        }

        $lastOutput = $run->get('last_agent_output');
        if ($lastOutput !== null && $lastOutput !== '') {
            $lines[] = "**Last output:** {$lastOutput}";
        }

        $events = json_decode($run->get('event_log') ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        $lines[] = "**Events:** " . count($events);

        return implode("\n", $lines);
    }

    private function transitionStatus(IssueRun $run, string $newStatus): void
    {
        $currentStatus = $run->get('status');
        $allowed = self::VALID_TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            throw new \InvalidArgumentException(
                "Cannot transition from '{$currentStatus}' to '{$newStatus}'"
            );
        }

        $this->appendEvent($run, [
            'type' => 'status_change',
            'from' => $currentStatus,
            'to' => $newStatus,
        ]);

        $run->set('status', $newStatus);
        $this->saveRun($run);
    }

    private function appendEvent(IssueRun $run, array $event): void
    {
        $event['time'] = gmdate('Y-m-d\TH:i:s\Z');
        $events = json_decode($run->get('event_log') ?? '[]', true, 512, JSON_THROW_ON_ERROR);
        $events[] = $event;
        $run->set('event_log', json_encode($events, JSON_THROW_ON_ERROR));
    }

    private function saveRun(IssueRun $run): void
    {
        $storage = $this->entityTypeManager->getStorage('issue_run');
        $storage->save($run);
    }

    private function findOrCreateWorkspace(int $issueNumber): Workspace
    {
        $branchName = 'issue-' . $issueNumber;
        $storage = $this->entityTypeManager->getStorage('workspace');
        $existing = $storage->loadByProperties(['branch' => $branchName]);

        if (!empty($existing)) {
            return $existing[0];
        }

        $workspace = new Workspace([
            'name' => "Issue #{$issueNumber}",
            'branch' => $branchName,
        ]);
        $workspace->enforceIsNew();
        $storage->create($workspace);

        return $workspace;
    }

    private function loadWorkspace(IssueRun $run): Workspace
    {
        $storage = $this->entityTypeManager->getStorage('workspace');
        $workspace = $storage->load($run->get('workspace_id'));

        if ($workspace === null) {
            throw new \RuntimeException("Workspace not found for run {$run->get('uuid')}");
        }

        return $workspace;
    }
}
```

Note: `GitOperator` is referenced but may not exist in the `Claudriel\Domain` namespace — check the actual import path when implementing. It's currently at `Claudriel\AI\GitOperator` based on the `CodexExecutionPipeline` constructor.

- [ ] **Step 4: Run test to verify it passes**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Domain/IssueOrchestratorTest.php
```

Expected: 9 tests, all PASS.

- [ ] **Step 5: Commit**

```bash
cd /home/fsd42/dev/claudriel
git add src/Domain/IssueOrchestrator.php tests/Unit/Domain/IssueOrchestratorTest.php
git commit -m "feat: add IssueOrchestrator with lifecycle, workspace binding, and event logging"
```

---

## Chunk 5: Chat Integration + CLI Commands (Claudriel)

### Task 10: Extend ChatStreamController with intent detection

**Files:**
- Modify: `/home/fsd42/dev/claudriel/src/Controller/ChatStreamController.php`

- [ ] **Step 1: Add nullable IssueOrchestrator to constructor**

In the constructor, add a new parameter after the existing ones:

```php
private readonly ?IssueOrchestrator $orchestrator = null,
```

Import: `use Claudriel\Domain\IssueOrchestrator;`

- [ ] **Step 2: Add handleOrchestratorIntent() method**

Add a private method modeled on the existing `handleLocalAction()` pattern:

```php
private function handleOrchestratorIntent(ChatMessage $userMsg, mixed $msgStorage): ?StreamedResponse
{
    if ($this->orchestrator === null) {
        return null;
    }

    $intent = \Claudriel\Domain\Chat\IssueIntentDetector::detect($userMsg->get('content'));
    if ($intent === null) {
        return null;
    }

    return new StreamedResponse(function () use ($intent, $userMsg, $msgStorage) {
        try {
            $result = match ($intent->action) {
                'run_issue' => $this->handleRunIssue($intent->params['issueNumber']),
                'show_run' => $this->handleShowRun($intent->params['runId']),
                'list_runs' => $this->handleListRuns(),
                'show_diff' => $this->handleShowDiff($intent->params['runId']),
                'pause_run' => $this->handlePauseRun($intent->params['runId']),
                'resume_run' => $this->handleResumeRun($intent->params['runId']),
                'abort_run' => $this->handleAbortRun($intent->params['runId']),
                default => 'Unknown orchestrator command.',
            };

            $this->emitSseEvent('chat-token', ['token' => $result]);
            $this->emitSseEvent('chat-done', ['content' => $result]);

            // Save assistant response
            $assistantMsg = new \Claudriel\Entity\ChatMessage([
                'session_id' => $userMsg->get('session_id'),
                'role' => 'assistant',
                'content' => $result,
            ]);
            $assistantMsg->enforceIsNew();
            $msgStorage->create($assistantMsg);
        } catch (\Throwable $e) {
            $this->emitSseEvent('chat-error', ['error' => $e->getMessage()]);
        }
    }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache']);
}
```

- [ ] **Step 3: Add handler helper methods**

```php
private function handleRunIssue(int $issueNumber): string
{
    $run = $this->orchestrator->createRun($issueNumber);
    $this->orchestrator->startRun($run);
    return $this->orchestrator->summarizeRun($run);
}

private function handleShowRun(string $runId): string
{
    $run = $this->orchestrator->getRun($runId);
    return $run !== null
        ? $this->orchestrator->summarizeRun($run)
        : "Run {$runId} not found.";
}

private function handleListRuns(): string
{
    $runs = $this->orchestrator->listRuns();
    if (empty($runs)) {
        return 'No issue runs found.';
    }
    return implode("\n\n---\n\n", array_map(
        fn($run) => $this->orchestrator->summarizeRun($run),
        $runs,
    ));
}

private function handleShowDiff(string $runId): string
{
    $run = $this->orchestrator->getRun($runId);
    if ($run === null) {
        return "Run {$runId} not found.";
    }
    $diff = $this->orchestrator->getWorkspaceDiff($run);
    return $diff !== '' ? "```diff\n{$diff}\n```" : 'No changes detected.';
}

private function handlePauseRun(string $runId): string
{
    $run = $this->orchestrator->getRun($runId);
    if ($run === null) return "Run {$runId} not found.";
    $this->orchestrator->pauseRun($run);
    return "Run {$runId} paused.\n\n" . $this->orchestrator->summarizeRun($run);
}

private function handleResumeRun(string $runId): string
{
    $run = $this->orchestrator->getRun($runId);
    if ($run === null) return "Run {$runId} not found.";
    $this->orchestrator->resumeRun($run);
    return "Run {$runId} resumed.\n\n" . $this->orchestrator->summarizeRun($run);
}

private function handleAbortRun(string $runId): string
{
    $run = $this->orchestrator->getRun($runId);
    if ($run === null) return "Run {$runId} not found.";
    $this->orchestrator->abortRun($run);
    return "Run {$runId} aborted.\n\n" . $this->orchestrator->summarizeRun($run);
}
```

- [ ] **Step 4: Hook into stream() method**

In the `stream()` method, after the existing `$localResponse = $this->handleLocalAction(...)` check, add:

```php
$orchestratorResponse = $this->handleOrchestratorIntent($userMsg, $msgStorage);
if ($orchestratorResponse !== null) {
    return $orchestratorResponse;
}
```

- [ ] **Step 5: Run existing tests to verify no regression**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit
```

Expected: All tests pass.

- [ ] **Step 6: Commit**

```bash
cd /home/fsd42/dev/claudriel
git add src/Controller/ChatStreamController.php
git commit -m "feat: add orchestrator intent detection to ChatStreamController"
```

### Task 11: CLI commands

**Files:**
- Create: `/home/fsd42/dev/claudriel/src/Command/IssueRunCommand.php`
- Create: `/home/fsd42/dev/claudriel/src/Command/IssueListCommand.php`
- Create: `/home/fsd42/dev/claudriel/src/Command/IssueStatusCommand.php`

- [ ] **Step 1: Implement IssueRunCommand**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\IssueOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:issue:run', description: 'Create and start a run for a GitHub issue')]
final class IssueRunCommand extends Command
{
    public function __construct(
        private readonly IssueOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('number', InputArgument::REQUIRED, 'GitHub issue number');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issueNumber = (int) $input->getArgument('number');

        $output->writeln("Creating run for issue #{$issueNumber}...");

        $run = $this->orchestrator->createRun($issueNumber);
        $output->writeln("Run created: {$run->get('uuid')}");

        $this->orchestrator->startRun($run);
        $output->writeln($this->orchestrator->summarizeRun($run));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 2: Implement IssueListCommand**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\IssueOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:issue:list', description: 'List issue runs')]
final class IssueListCommand extends Command
{
    public function __construct(
        private readonly IssueOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', 's', InputOption::VALUE_OPTIONAL, 'Filter by status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $input->getOption('status');
        $runs = $this->orchestrator->listRuns($status);

        if (empty($runs)) {
            $output->writeln('No issue runs found.');
            return Command::SUCCESS;
        }

        foreach ($runs as $run) {
            $output->writeln($this->orchestrator->summarizeRun($run));
            $output->writeln('---');
        }

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 3: Implement IssueStatusCommand**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\IssueOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:issue:status', description: 'Show status of an issue run')]
final class IssueStatusCommand extends Command
{
    public function __construct(
        private readonly IssueOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'Run UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = $input->getArgument('uuid');
        $run = $this->orchestrator->getRun($uuid);

        if ($run === null) {
            $output->writeln("Run {$uuid} not found.");
            return Command::FAILURE;
        }

        $output->writeln($this->orchestrator->summarizeRun($run));

        return Command::SUCCESS;
    }
}
```

- [ ] **Step 4: Run all tests to verify no regression**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit
```

Expected: All tests pass.

- [ ] **Step 5: Commit**

```bash
cd /home/fsd42/dev/claudriel
git add src/Command/IssueRunCommand.php src/Command/IssueListCommand.php src/Command/IssueStatusCommand.php
git commit -m "feat: add CLI commands for issue orchestrator (run, list, status)"
```

### Task 12: Wire IssueOrchestrator in ClaudrielServiceProvider

**Files:**
- Modify: `/home/fsd42/dev/claudriel/src/Provider/ClaudrielServiceProvider.php`

- [ ] **Step 1: Add orchestrator wiring in register()**

Add after entity type registrations:

```php
// Issue orchestrator wiring
$gitHubClient = null;
$githubToken = $_ENV['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN') ?: null;
if ($githubToken !== null) {
    $githubOwner = $_ENV['GITHUB_OWNER'] ?? getenv('GITHUB_OWNER') ?: '';
    $githubRepo = $_ENV['GITHUB_REPO'] ?? getenv('GITHUB_REPO') ?: '';
    $gitHubClient = new \Waaseyaa\GitHub\GitHubClient($githubToken, $githubOwner, $githubRepo);
}
```

- [ ] **Step 2: Wire the IssueOrchestrator construction**

This should be lazy — only construct when needed. Wire it as a factory that the CLI commands and ChatStreamController can resolve:

```php
$this->container->set(IssueOrchestrator::class, function () use ($gitHubClient) {
    if ($gitHubClient === null) {
        return null;
    }
    return new \Claudriel\Domain\IssueOrchestrator(
        entityTypeManager: $this->container->get(EntityTypeManager::class),
        gitHubClient: $gitHubClient,
        pipeline: $this->container->get(\Claudriel\AI\CodexExecutionPipeline::class),
        instructionBuilder: new \Claudriel\Domain\IssueInstructionBuilder(),
        gitOperator: $this->container->get(\Claudriel\AI\GitOperator::class),
    );
});
```

Note: Adapt to the actual DI pattern used by `ClaudrielServiceProvider`. The container/wiring approach should match how existing services like `CodexExecutionPipeline` are resolved.

- [ ] **Step 3: Run all tests**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit
```

Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
cd /home/fsd42/dev/claudriel
git add src/Provider/ClaudrielServiceProvider.php
git commit -m "feat: wire IssueOrchestrator in ClaudrielServiceProvider"
```

### Task 13: Add waaseyaa/github dependency to claudriel

**Files:**
- Modify: `/home/fsd42/dev/claudriel/composer.json`

- [ ] **Step 1: Add waaseyaa/github to require**

Add to `require` section, matching the version constraint pattern used for other waaseyaa packages:

```json
"waaseyaa/github": "v0.1.0-alpha.1"
```

If the package isn't published yet, temporarily use:

```json
"waaseyaa/github": "@dev"
```

And add path repository if not already present:

```json
{"type": "path", "url": "../waaseyaa/packages/github"}
```

- [ ] **Step 2: Run composer update**

```bash
cd /home/fsd42/dev/claudriel && composer update waaseyaa/github
```

- [ ] **Step 3: Run all tests**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit
```

Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
cd /home/fsd42/dev/claudriel
git add composer.json composer.lock
git commit -m "chore: add waaseyaa/github dependency"
```

### Task 14: Final integration verification

- [ ] **Step 1: Run waaseyaa test suite**

```bash
cd /home/fsd42/dev/waaseyaa && ./vendor/bin/phpunit
```

Expected: All tests pass including new GitHub package tests.

- [ ] **Step 2: Run claudriel test suite**

```bash
cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit
```

Expected: All tests pass including new entity, orchestrator, intent detector, and instruction builder tests.

- [ ] **Step 3: Verify CLI command discovery**

```bash
cd /home/fsd42/dev/claudriel && php bin/claudriel list | grep issue
```

Expected: Shows `claudriel:issue:run`, `claudriel:issue:list`, `claudriel:issue:status`.
