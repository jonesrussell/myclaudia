# Entity Specification

## File Map

| File | Purpose |
|------|---------|
| `src/Entity/Account.php` | External account (email, calendar, etc.) linked to a Person |
| `src/Entity/Commitment.php` | Extracted obligation with status + confidence |
| `src/Entity/Integration.php` | Service integration config record |
| `src/Entity/McEvent.php` | Immutable ingested fact (source event) |
| `src/Entity/Person.php` | Contact/sender extracted from ingestion |
| `src/Entity/Workspace.php` | Named grouping context for events and commitments |
| `src/Entity/CodeTask.php` | Delegated Claude Code task with status tracking |
| `src/Provider/ClaudrielServiceProvider.php` | Registers core entity types + routes |
| `src/Provider/WorkspaceServiceProvider.php` | Registers workspace-scoped entities (artifact) |
| `src/Provider/OperationsServiceProvider.php` | Registers operation, issue_run, integration entities |

## Internal-Only Entities

The following entities are used internally and do not require CRUD surfaces
(admin UI, REST controllers, or GraphQL schema):

| Entity | Purpose |
|--------|---------|
| `Artifact` | Repo reference used by workspace system internally |
| `CodeTask` | Background Claude Code execution, managed via agent tools not user-facing |
| `Integration` | Service integration config, managed via OAuth flows not user-facing |
| `Operation` | Work unit tracking for AI code-gen, internal orchestration |
| `TriageEntry` | Unprocessed message buffer, consumed by ingestion pipeline |

## Interface Signatures

All entities extend `Waaseyaa\Entity\ContentEntityBase` (which implements `ContentEntityInterface`):

```php
// Field access
$entity->get(string $field): mixed
$entity->set(string $field, mixed $value): void
$entity->id(): string|int|null  // returns value of the 'id' entity key
$entity->uuid(): string|null

// EntityRepositoryInterface (Waaseyaa\Entity\Repository)
$repo->find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
$repo->findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
$repo->save(EntityInterface $entity): int  // returns SAVED_NEW=1 or SAVED_UPDATED=2
$repo->delete(EntityInterface $entity): void
$repo->exists(string $id): bool
$repo->count(array $criteria = []): int
```

## Entity Keys

| Entity | id key | uuid key | label key |
|--------|--------|----------|-----------|
| Account | `aid` | `uuid` | `name` |
| Commitment | `cid` | `uuid` | `title` |
| Integration | `iid` | `uuid` | `name` |
| McEvent | `eid` | `uuid` | — |
| Person | `pid` | `uuid` | `name` |
| Skill | `sid` | `uuid` | `name` |
| Workspace | `wid` | `uuid` | `name` |
| CodeTask | `ctid` | `uuid` | `prompt` |

## EntityType Registration

```php
// In ClaudrielServiceProvider::register()
$this->entityType(new EntityType(
    id: 'commitment',
    label: 'Commitment',
    class: Commitment::class,
    keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
));
```

## Commitment Fields

| Field | Type | Default | Notes |
|-------|------|---------|-------|
| `cid` | int | — | Primary key |
| `uuid` | string | — | |
| `title` | string | required | Extracted from AI |
| `confidence` | float | `1.0` | Set by extraction step |
| `status` | string | `'pending'` | Synced from `workflow_state` for backward compat |
| `direction` | string | `'outbound'` | `'outbound'` (you owe) or `'inbound'` (they owe) |
| `workflow_state` | string | `'pending'` | Canonical state: `pending`, `active`, `completed`, `archived` |
| `workspace_uuid` | string | `null` | Workspace scoping |
| `importance_score` | float | `1.0` | Adaptive memory decay weight |
| `access_count` | int | `0` | Decay tracking counter |
| `last_accessed_at` | string | `null` | ISO 8601; decay tracking timestamp |
| `source_event_id` | string | — | McEvent id |
| `person_id` | string | — | Person entity id |
| `tenant_id` | string | — | Multi-tenancy key |
| `updated_at` | string | — | Used by DriftDetector |

## McEvent Fields

| Field | Type | Notes |
|-------|------|-------|
| `eid` | int | Primary key |
| `source` | string | e.g. `'gmail'` |
| `type` | string | e.g. `'message.received'` |
| `payload` | string | JSON-encoded payload |
| `tenant_id` | string | |
| `trace_id` | string | Unique per ingestion |
| `occurred` | string | ISO 8601 timestamp |
| `workspace_id` | string | Optional; uuid of associated Workspace |

## Person Fields (enrichment)

| Field | Type | Notes |
|-------|------|-------|
| `pid` | int | Primary key |
| `uuid` | string | |
| `name` | string | Display name |
| `email` | string | Primary email address |
| `last_interaction_at` | string | ISO 8601; updated on each ingestion event |
| `source` | string | Origin of record e.g. `'gmail'` |
| `metadata` | string | JSON-encoded extra data |

## Workspace Fields

| Field | Type | Notes |
|-------|------|-------|
| `wid` | int | Primary key |
| `uuid` | string | |
| `name` | string | Human-readable workspace name |
| `description` | string | Optional description; defaults to `''` |
| `account_id` | string | Optional; links to an Account entity |
| `metadata` | string | JSON-encoded extra data; defaults to `'{}'` |

## CodeTask Fields

| Field | Type | Default | Notes |
|-------|------|---------|-------|
| `ctid` | int | — | Primary key |
| `uuid` | string | — | |
| `workspace_uuid` | string | required | Links to Workspace |
| `repo_uuid` | string | required | Links to Repo entity |
| `prompt` | text_long | required | Instructions for Claude Code |
| `status` | string | `'queued'` | `queued`, `running`, `completed`, `failed` |
| `branch_name` | string | — | Git branch (auto-generated from prompt if not provided) |
| `pr_url` | string | `null` | GitHub PR URL on completion |
| `summary` | text_long | `null` | Summary of changes (last 20 lines of output) |
| `diff_preview` | text_long | `null` | Truncated diff (max 200 lines) |
| `error` | text_long | `null` | Error message on failure |
| `claude_output` | text_long | `null` | Full Claude Code output (max 50K chars) |
| `started_at` | string | `null` | ISO 8601; set when status becomes `running` |
| `completed_at` | string | `null` | ISO 8601; set on `completed` or `failed` |
| `tenant_id` | string | — | Multi-tenancy key |

## Testing Pattern

Use `InMemoryStorageDriver` + `EventDispatcher` for repository tests:

```php
$repo = new EntityRepository(
    new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class,
        keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
    new InMemoryStorageDriver(),
    new EventDispatcher(),
);
```
