# Infrastructure Specification

Covers the service provider (central wiring) and support utilities.

## File Map

| File | Purpose |
|------|---------|
| `src/Provider/ClaudrielServiceProvider.php` | Registers core entity types, routes, and CLI commands |
| `src/Provider/CodeTaskServiceProvider.php` | Registers CodeTask entity type and CodeTaskRunner singleton |
| `src/Provider/OperationsServiceProvider.php` | Registers operation, issue_run, and integration entity types |
| `src/Provider/WorkspaceServiceProvider.php` | Registers workspace-scoped entities (artifact); sole owner of artifact EntityType |
| `config/waaseyaa.php` | CORS origins and dev port configuration (37840 PHP, 37841 Nuxt) |
| `src/Support/BriefSignal.php` | File-based timestamp tracking for brief generation signals |
| `src/Support/DriftDetector.php` | Finds active commitments unchanged for 48+ hours |
| `src/Support/StorageRepositoryAdapter.php` | Adapts SqlEntityStorage to EntityRepositoryInterface |

## ClaudrielServiceProvider

Central wiring point for Claudriel. Extends Waaseyaa `ServiceProvider`.

### Entity Registration

`register()` registers 8 entity types via `$this->entityType(new EntityType(...))`:

| Entity Type ID | Key Mapping | Purpose |
|---------------|-------------|---------|
| `account` | id→aid, uuid, label→name | User accounts |
| `mc_event` | id→eid, uuid | Ingested events |
| `person` | id→pid, uuid, label→name | People extracted from events |
| `integration` | id→iid, uuid, label→name | External service integrations |
| `commitment` | id→cid, uuid, label→title | Tracked commitments |
| `skill` | id→sid, uuid, label→name | Matched skills |
| `chat_session` | id→csid, uuid, label→title | Chat conversation threads |
| `chat_message` | id→cmid, uuid | Individual chat messages |

### Route Registration

`routes(WaaseyaaRouter $router)` registers 9 routes:

| Method | Path | Handler | Notes |
|--------|------|---------|-------|
| `GET` | `/` | `DashboardController::show` | Dashboard unification |
| `GET` | `/brief` | `DayBriefController::show` | Legacy JSON API |
| `GET` | `/chat` | `ChatEntryRedirectController::redirectToAdmin` | Redirects to Nuxt admin with chat rail open |
| `GET` | `/stream/brief` | `BriefStreamController::stream` | SSE brief stream |
| `GET` | `/stream/chat/{messageId}` | `ChatStreamController::stream` | SSE chat stream |
| `PATCH` | `/commitments/{uuid}` | `CommitmentUpdateController::update` | Update commitment |
| `POST` | `/api/ingest` | `IngestController::handle` | Event ingestion |
| `GET` | `/api/context` | `ContextController::show` | Context data |
| `POST` | `/api/chat/send` | `ChatController::send` | Send chat message |

### CLI Command Bootstrap

`commands(EntityTypeManager, PdoDatabase, EventDispatcherInterface): array` creates:

1. `BriefCommand` (uses DayBriefAssembler + BriefSessionStore)
2. `CommitmentsCommand`
3. `CommitmentUpdateCommand`
4. `SkillsCommand`

Creates fresh `EntityRepository` instances with `SqlStorageDriver` + `SingleConnectionResolver`.

## BriefSignal

File-based timestamp utility. Tracks when the brief was last generated.

```php
final class BriefSignal
{
    public function __construct(string $filePath)
    public function touch(): void                       // writes current timestamp to file
    public function lastModified(): int                 // filemtime() with cache clearing
    public function hasChangedSince(int $sinceTimestamp): bool
}
```

No external dependencies (stdlib only). Used by `BriefSessionStore` in the DayBrief layer.

## DriftDetector

Identifies commitments that may need attention.

```php
final class DriftDetector
{
    const DRIFT_HOURS = 48;

    public function __construct(EntityRepositoryInterface $repo)  // commitment repo
    public function findDrifting(string $tenantId): ContentEntityInterface[]
}
```

Logic: loads all commitments, filters in-memory for `status === 'active'` AND `updated_at < (now - 48h)`. Pending commitments are NOT checked.

## CodeTaskServiceProvider

Dedicated provider for the CodeTask subsystem. Registers:

- `code_task` EntityType with full `fieldDefinitions` (enables GraphQL schema)
- `CodeTaskRunner` as a singleton (resolves its own `SqlEntityStorage` + `StorageRepositoryAdapter`)

```php
// CodeTaskRunner wiring
$storage = new SqlEntityStorage($entityType, $database, $dispatcher);
$repo = new StorageRepositoryAdapter($storage);
return new CodeTaskRunner($repo);
```

## Storage Strategy

Domain service providers (e.g., `CodeTaskServiceProvider`, `PipelineServiceProvider`) create entity repositories using `SqlEntityStorage` + `StorageRepositoryAdapter`. `ClaudrielServiceProvider::commands()` creates parallel repository instances for CLI commands using the same pattern. This dual-instance approach is intentional (#377).

## Multi-Provider Entity Registration

Entity types are distributed across multiple service providers. Each EntityType ID must be registered in exactly one provider to avoid duplicate registrations breaking GraphQL schema generation and storage bootstrap (#652).

| Provider | Entity Types |
|----------|-------------|
| `ClaudrielServiceProvider` | account, mc_event, person, commitment, skill, chat_session, chat_message |
| `WorkspaceServiceProvider` | artifact (sole owner, workspace-scoped repo metadata) |
| `OperationsServiceProvider` | operation, issue_run, integration |
| `CodeTaskServiceProvider` | code_task |
| `PipelineServiceProvider` | prospect, prospect_attachment, prospect_audit, filtered_prospect, pipeline_config |

**Critical:** The `artifact` EntityType is registered ONLY in `WorkspaceServiceProvider`. Do not duplicate it in `ClaudrielServiceProvider` or `OperationsServiceProvider`. Duplicate EntityType IDs break GraphQL and storage bootstrap.

## Configuration

`config/waaseyaa.php` contains CORS allowed origins and dev port configuration. Dev ports: PHP on **:37840**, Nuxt admin on **:37841** (see `frontend/admin/devPorts.ts`). The Nuxt dev server proxies `/api/**` to PHP.

## Adding New Entity Types

1. Create entity class in `src/Entity/` extending `ContentEntityBase`
2. Register EntityType with `fieldDefinitions` in the relevant service provider's `register()`
3. Wire persistence via `SqlEntityStorage` + `StorageRepositoryAdapter` (see `.claude/rules/waaseyaa-entity-wiring.md`)
4. If needed, register routes in `routes()` and/or commands in `commands()`
