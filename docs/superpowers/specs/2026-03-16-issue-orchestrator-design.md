# Issue Orchestrator Design Spec

**Date:** 2026-03-16
**Status:** Approved
**Repos affected:** waaseyaa (new package), claudriel (new entity + domain classes)

## Goal

Add a strongly stateful issue orchestrator to Claudriel that binds GitHub Issues to the existing Workspace + TemporalAgentOrchestrator + Claude Code sidecar pipeline. Fully operable through chat-only UX with optional CLI commands. Inspectable, resumable, explainable.

## Architecture

IssueRun is a thin binding layer connecting three existing systems:

```
GitHub Issue ──→ IssueRun ──→ Workspace (existing)
                    │              ↓
                    │   Claude Code Sidecar Pipeline (existing)
                    │              ↓
                    └────→ TemporalAgentOrchestrator (existing)
```

IssueRun does not own execution, lifecycle, chat, or Git operations. It coordinates existing systems.

## Component 1: `packages/github/` (Waaseyaa — new package)

**Layer:** 3 (Services)
**Purpose:** Reusable GitHub API client for issues, milestones, PRs.

### Public API

```php
namespace Waaseyaa\GitHub;

final class GitHubClient
{
    public function __construct(string $token, string $owner, string $repo) {}

    public function getIssue(int $number): Issue {}
    public function listIssues(array $filters = []): array {}
    public function getMilestone(int $number): Milestone {}
    public function listMilestones(string $state = 'open'): array {}
    public function createComment(int $issueNumber, string $body): void {}
    public function updateIssueState(int $issueNumber, string $state): void {}
    public function createPullRequest(string $title, string $head, string $base, string $body): PullRequest {}
}
```

### Value Objects

- `Issue` — number, title, body, state, milestone, labels, assignees. Immutable DTO.
- `Milestone` — number, title, description, state, openIssues, closedIssues. Immutable DTO.
- `PullRequest` — number, url, title, state. Immutable DTO.

### HTTP

Minimal HTTP client using Symfony HttpClient or raw `file_get_contents` with stream context. Auth via `Authorization: Bearer {token}` header.

### Config

- Token from env `GITHUB_TOKEN`
- Owner/repo from `config/waaseyaa.php` key `github`

### Package structure

```
packages/github/
├── composer.json
├── src/
│   ├── GitHubClient.php
│   ├── Issue.php
│   ├── Milestone.php
│   ├── PullRequest.php
│   └── GitHubException.php
└── tests/
    └── Unit/
        ├── GitHubClientTest.php
        └── IssueTest.php
```

## Component 2: `IssueRun` Entity (Claudriel)

**File:** `src/Entity/IssueRun.php`

```php
namespace Claudriel\Entity;

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
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
```

### Fields

| Field | Type | Purpose |
|-------|------|---------|
| `issue_number` | integer | GitHub issue number |
| `issue_title` | string | Cached issue title (label key) |
| `issue_body` | text_long | Cached issue body for prompt generation |
| `milestone_title` | string | Cached milestone name for context |
| `workspace_id` | integer | FK to Workspace.wid |
| `temporal_run_id` | string | ID from TemporalAgentOrchestrator — set on startRun |
| `status` | string | pending, running, paused, failed, completed |
| `branch_name` | string | e.g. `issue-123` |
| `pr_url` | string, nullable | Set when PR is created |
| `last_agent_output` | text_long, nullable | Most recent agent response summary |
| `event_log` | text_long | JSON array of structured events |

### EntityType Registration

Added to `ClaudrielServiceProvider::register()`:

```php
$this->entityType(new EntityType(
    id: 'issue_run',
    label: 'Issue Run',
    class: IssueRun::class,
    keys: ['id' => 'irid', 'uuid' => 'uuid', 'label' => 'issue_title'],
    group: 'orchestration',
    fieldDefinitions: [
        'issue_number' => ['type' => 'integer', 'label' => 'Issue Number'],
        'issue_title' => ['type' => 'string', 'label' => 'Issue Title'],
        'issue_body' => ['type' => 'text_long', 'label' => 'Issue Body'],
        'milestone_title' => ['type' => 'string', 'label' => 'Milestone'],
        'workspace_id' => ['type' => 'integer', 'label' => 'Workspace ID'],
        'temporal_run_id' => ['type' => 'string', 'label' => 'Temporal Run ID'],
        'status' => ['type' => 'string', 'label' => 'Status'],
        'branch_name' => ['type' => 'string', 'label' => 'Branch Name'],
        'pr_url' => ['type' => 'string', 'label' => 'PR URL'],
        'last_agent_output' => ['type' => 'text_long', 'label' => 'Last Agent Output'],
        'event_log' => ['type' => 'text_long', 'label' => 'Event Log'],
    ],
));
```

### Status Transitions

- `pending` → `running`
- `running` → `paused`, `failed`, `completed`
- `paused` → `running`, `failed`
- `failed` → `pending` (retry)

Validation enforced in IssueOrchestrator, not the entity. TemporalAgentOrchestrator makes lifecycle decisions; IssueRun records the outcomes.

### Event Log Format

```json
[
    {"time": "2026-03-16T14:30:00Z", "type": "created", "issue": 123},
    {"time": "2026-03-16T14:30:01Z", "type": "status_change", "from": "pending", "to": "running"},
    {"time": "2026-03-16T14:35:00Z", "type": "agent_iteration", "summary": "Added entity class"},
    {"time": "2026-03-16T14:40:00Z", "type": "pr_created", "url": "https://github.com/..."},
    {"time": "2026-03-16T14:40:01Z", "type": "status_change", "from": "running", "to": "completed"}
]
```

## Component 3: `IssueOrchestrator` Service (Claudriel)

**File:** `src/Domain/IssueOrchestrator.php`

```php
namespace Claudriel\Domain;

final class IssueOrchestrator
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly GitHubClient $gitHubClient,
        private readonly TemporalAgentOrchestrator $temporalOrchestrator,
        private readonly SidecarChatClient $sidecarClient,
        private readonly IssuePromptBuilder $promptBuilder,
        private readonly GitOperator $gitOperator,
    ) {}
}
```

### Public Methods

| Method | Signature | Behavior |
|--------|-----------|----------|
| `createRun` | `(int $issueNumber): IssueRun` | Fetch issue via GitHubClient, create/reuse Workspace (branch: `issue-{N}`), create IssueRun entity with cached issue data, status `pending`. Append `created` event. |
| `startRun` | `(IssueRun $run): void` | Set status `running`, set `temporal_run_id`, build prompt via IssuePromptBuilder, invoke Claude Code sidecar, let TemporalAgentOrchestrator evaluate. Append `status_change` event. |
| `pauseRun` | `(IssueRun $run): void` | Set status `paused`. Append `status_change` event. |
| `resumeRun` | `(IssueRun $run): void` | Set status `running`, re-invoke sidecar with resume context (includes `last_agent_output`). Append `status_change` event. |
| `abortRun` | `(IssueRun $run): void` | Set status `failed`. Append `status_change` + `aborted` events. |
| `completeRun` | `(IssueRun $run): void` | Set status `completed`. If diff exists, create PR via GitHubClient, store `pr_url`. Append `pr_created` + `status_change` events. |
| `getRun` | `(string $uuid): ?IssueRun` | Load by UUID from storage. |
| `getRunByIssue` | `(int $issueNumber): ?IssueRun` | Query storage for active run matching issue number. |
| `listRuns` | `(?string $status = null): array` | List all runs, optionally filtered by status. |
| `getWorkspaceDiff` | `(IssueRun $run): string` | Run `git diff` on workspace's `repo_path` via GitOperator. |
| `summarizeRun` | `(IssueRun $run): string` | Human-readable summary from entity fields + event_log. Used by chat responses. |

### Key Design Decisions

1. **Workspace reuse:** If a workspace with branch `issue-{N}` exists, reuse it. Prevents workspace sprawl.
2. **No run loop:** `startRun` invokes a single Claude Code sidecar execution. For continuous iteration, use the existing `WorkspaceRunLoopCommand`.
3. **No process management:** Synchronous per call. No PID tracking, no daemon spawning.
4. **Event logging on every state change:** All transitions append to `event_log` for audit trail and chat summaries.

## Component 3a: `IssuePromptBuilder` (Claudriel)

**File:** `src/Domain/IssuePromptBuilder.php`

```php
namespace Claudriel\Domain;

final class IssuePromptBuilder
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function build(IssueRun $run, Workspace $workspace): string {}
}
```

### Prompt Structure

The deterministic "work this issue" prompt includes:

1. **Run header:** IssueRun UUID for traceability
2. **Issue context:** title, body, labels, milestone name
3. **Workspace context:** repo path, current branch, recent commits
4. **Architectural constraints:** contents of `CLAUDE.md` from workspace repo root
5. **Guardrails:** directories to avoid, test requirements, commit conventions
6. **Resume context:** if `last_agent_output` exists, includes it for continuity

Pure function — no side effects, fully testable.

## Component 4: Chat Integration (Claudriel)

### Approach

Extend `ChatStreamController::stream()` to detect orchestrator intents before sending to the AI client. Unrecognized messages continue through the existing AI chat flow unchanged.

### Intent Detection

**File:** `src/Domain/Chat/IssueIntentDetector.php`

```php
namespace Claudriel\Domain\Chat;

final class IssueIntentDetector
{
    public static function detect(string $message): ?OrchestratorIntent {}
}
```

Simple regex-based pattern matching (not AI-based):

| Pattern | Intent | Params |
|---------|--------|--------|
| `run issue #123` / `work on issue #123` / `start issue #123` | `run_issue` | `issueNumber: 123` |
| `show run {uuid}` / `status of run {uuid}` | `show_run` | `runId: uuid` |
| `list runs` / `show all runs` / `active runs` | `list_runs` | — |
| `diff for run {uuid}` / `show diff {uuid}` | `show_diff` | `runId: uuid` |
| `pause run {uuid}` | `pause_run` | `runId: uuid` |
| `resume run {uuid}` | `resume_run` | `runId: uuid` |
| `abort run {uuid}` | `abort_run` | `runId: uuid` |

### OrchestratorIntent Value Object

```php
namespace Claudriel\Domain\Chat;

final readonly class OrchestratorIntent
{
    public function __construct(
        public string $action,
        public array $params = [],
    ) {}
}
```

### Chat Flow for "Run issue #123"

1. User sends message via `ChatController::send()`
2. `ChatStreamController::stream()` loads message text
3. `IssueIntentDetector::detect($text)` returns `OrchestratorIntent('run_issue', ['issueNumber' => 123])`
4. Handler calls `$orchestrator->createRun(123)` then `$orchestrator->startRun($run)`
5. Progress streams as SSE events: `chat-progress` ("Fetching issue #123..."), `chat-token` (agent output), `chat-done` (run UUID)
6. If no intent detected, existing AI chat flow continues unchanged

### Response Format

Orchestrator responses stream through the existing SSE mechanism:

- `chat-progress` events for status updates ("Creating workspace...", "Starting agent...")
- `chat-token` events for agent output
- `chat-done` with structured metadata including run UUID

The chat UI does not need changes.

## Component 5: CLI Commands (Claudriel — optional)

Three thin commands delegating to `IssueOrchestrator`:

| Command | Args | Maps to |
|---------|------|---------|
| `claudriel:issue:run {number}` | issue number | `createRun()` + `startRun()` |
| `claudriel:issue:list` | `--status` filter | `listRuns()` |
| `claudriel:issue:status {uuid}` | run UUID | `getRun()` + `summarizeRun()` |

Standard Symfony Console commands following existing `WorkspacesCommand` patterns.

## Files Created/Modified

### Waaseyaa (new package)

```
packages/github/
├── composer.json
├── src/
│   ├── GitHubClient.php
│   ├── GitHubException.php
│   ├── Issue.php
│   ├── Milestone.php
│   └── PullRequest.php
└── tests/
    └── Unit/
        ├── GitHubClientTest.php
        └── IssueTest.php
```

### Claudriel (new files)

```
src/Entity/IssueRun.php
src/Domain/IssueOrchestrator.php
src/Domain/IssuePromptBuilder.php
src/Domain/Chat/IssueIntentDetector.php
src/Domain/Chat/OrchestratorIntent.php
src/Command/IssueRunCommand.php
src/Command/IssueListCommand.php
src/Command/IssueStatusCommand.php
tests/Claudriel/Unit/Entity/IssueRunTest.php
tests/Claudriel/Unit/Domain/IssueOrchestratorTest.php
tests/Claudriel/Unit/Domain/IssuePromptBuilderTest.php
tests/Claudriel/Unit/Domain/Chat/IssueIntentDetectorTest.php
```

### Claudriel (modified)

```
src/Provider/ClaudrielServiceProvider.php  (add issue_run entity type + orchestrator wiring)
src/Controller/ChatStreamController.php    (add intent detection before AI call)
```

## Test Plan

### IssueRun persistence
- `testCreateRunPersistsState` — create, save, reload, verify fields
- `testUpdateRunStatus` — change status, verify event_log appended
- `testEventLogAppend` — multiple events, verify JSON structure

### Orchestrator lifecycle
- `testCreateRunFetchesIssueAndCreatesWorkspace` — mock GitHubClient, verify workspace + IssueRun created
- `testCreateRunReusesExistingWorkspace` — workspace with matching branch already exists
- `testStartRunInvokesSidecar` — verify prompt built, sidecar called
- `testPauseRunSetsStatus` — verify status change + event
- `testResumeRunIncludesLastOutput` — verify prompt includes resume context
- `testAbortRunSetsFailedStatus`
- `testCompleteRunCreatesPR` — verify GitHubClient::createPullRequest called, pr_url stored
- `testListRunsFiltersbyStatus`

### Chat integration
- `testDetectRunIssueIntent` — "run issue #123" → run_issue intent
- `testDetectShowRunIntent` — "show run {uuid}" → show_run intent
- `testDetectListRunsIntent` — "list runs" → list_runs intent
- `testDetectDiffIntent` — "diff for run {uuid}" → show_diff intent
- `testUnrecognizedMessageReturnsNull` — normal chat passes through
- `testCaseInsensitiveDetection` — "Run Issue #123" works

### Prompt generation
- `testPromptIncludesIssueTitle`
- `testPromptIncludesIssueBody`
- `testPromptIncludesMilestoneContext`
- `testPromptIncludesRunUuid` — traceability header
- `testPromptIncludesClaudeMd` — architectural constraints
- `testPromptIncludesResumeContext` — when last_agent_output present
- `testPromptExcludesResumeContextOnFirstRun`

### Workspace diff
- `testWorkspaceDiffShowsChanges`
- `testWorkspaceDiffEmptyWhenNoChanges`

## Implementation Order

1. `packages/github/` — value objects + client + tests
2. `IssueRun` entity + entity type registration + persistence tests
3. `IssueOrchestrator` — createRun/startRun/lifecycle + tests
4. `IssuePromptBuilder` + tests
5. `IssueIntentDetector` + `OrchestratorIntent` + tests
6. `ChatStreamController` extension (intent detection hook)
7. CLI commands
8. Integration testing
