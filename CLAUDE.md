# Claudriel — Codified Context

Claudriel is an **AI personal operations system** built on the Waaseyaa PHP framework.
It ingests events (e.g. Gmail messages), extracts commitments via AI, and presents a daily brief.

## Architecture

```
Gmail API → GmailMessageNormalizer → Envelope
Envelope  → EventHandler → McEvent (saved) + Person (upserted)
McEvent   → CommitmentExtractionStep (AI, waaseyaa/ai-pipeline) → candidates
candidates → CommitmentHandler (confidence ≥ 0.7) → Commitment (saved)

DayBriefAssembler → { recent_events, pending_commitments, drifting_commitments }
DriftDetector     → active Commitments with updated_at < 48h ago

GET /brief           → DayBriefController → JSON
claudriel:brief      → BriefCommand → CLI
claudriel:commitments → CommitmentsCommand → CLI

Chat → ChatStreamController → SubprocessChatClient
  → agent/main.py (Python, stdin/stdout JSON-lines)
  → tools call back to /api/internal/* (HMAC Bearer auth)
  → InternalGoogleController → GoogleTokenManager → Google APIs

GraphQL (waaseyaa/graphql):
  POST /graphql → auto-generated schema from entity types
  Commitment, Person, Workspace, ScheduleEntry, TriageEntry fully migrated (REST controllers removed)
  Frontend uses graphqlFetch() composables
```

## Layers

```
Layer 0 — Waaseyaa packages (entity, foundation, ai-pipeline, routing, cli, api)
Layer 1 — Entity (src/Entity/*)            extends ContentEntityBase
Layer 2 — Ingestion (src/Ingestion/*)      depends on Layer 1 + Waaseyaa
         Pipeline (src/Pipeline/*)          depends on Layer 1 + ai-pipeline
         DayBrief (src/DayBrief/*)          depends on Layer 1
         Support (src/Support/*)            depends on Layer 1 (utilities)
         Domain (src/Domain/*)              depends on Layer 1 + Layer 2
Layer 3 — Web/CLI (src/Controller/*, src/Command/*)  depends on Layer 2
Layer 4 — Service registration (src/Provider/ClaudrielServiceProvider)  depends on all
```

Reserved namespaces (empty, scaffolded for future use): `src/Access/`, `src/Search/`, `src/Seed/`

Rule: higher layers import lower layers only. Never import from src/Command inside src/Ingestion.

## Orchestration

| File pattern | Specialist skill | Cold memory spec |
|---|---|---|
| `src/Entity/*` | — | `docs/specs/entity.md` |
| `src/Ingestion/*` | `claudriel:ingestion` | `docs/specs/ingestion.md` |
| `src/Pipeline/*` | `claudriel:ingestion` | `docs/specs/pipeline.md` |
| `src/DayBrief/*` | `claudriel:day-brief` | `docs/specs/day-brief.md` |
| `src/Support/*` | `claudriel:day-brief` | `docs/specs/infrastructure.md` |
| `src/Domain/Chat/*` | `claudriel:chat` | `docs/specs/chat.md` |
| `src/Entity/Chat*.php` | `claudriel:chat` | `docs/specs/chat.md` |
| `src/Controller/Chat*.php` | `claudriel:chat` | `docs/specs/chat.md` |
| `src/Controller/InternalGoogle*` | `claudriel:chat` | `docs/specs/agent-subprocess.md` |
| `agent/*` | `claudriel:chat` | `docs/specs/agent-subprocess.md` |
| `src/Controller/*, src/Command/*` | — | `docs/specs/web-cli.md` |
| `src/Provider/*` | — | `docs/specs/infrastructure.md` |
| `src/Entity/*`, `src/Provider/*`, `src/Access/*` | `waaseyaa-app-development` | `docs/specs/entity.md` |
| `src/Controller/*`, `src/Routing/*` | `waaseyaa-app-development` | — |
| GitHub issues, milestones, new features, roadmap | — | `docs/specs/workflow.md` |

## Common Operations

**Add a new entity type:**
1. Create `src/Entity/Foo.php` extending `ContentEntityBase` with `entityTypeId` and `entityKeys`
2. Register in `ClaudrielServiceProvider::register()` via `$this->entityType(new EntityType(...))`
3. Wire persistence via `SqlEntityStorage` + `StorageRepositoryAdapter` in the domain service provider (see `.claude/rules/waaseyaa-entity-wiring.md`)

**Add an ingestion handler:**
1. Create `src/Ingestion/FooNormalizer.php` → produces an `Envelope`
2. Extend `EventHandler::handle()` or create a parallel handler
3. Wire both into pipeline or kernel bootstrap

**Add a pipeline step:**
1. Implement `PipelineStepInterface` in `src/Pipeline/`
2. Return `StepResult::success(['key' => $data])` or `StepResult::failure('reason')`
3. Register step in pipeline configuration

**Add a CLI command:**
1. Create `src/Command/FooCommand.php` with `#[AsCommand(name: 'claudriel:foo')]`
2. Wire dependencies in service container
3. Confirm ConsoleKernel discovers it (see issue #9)

**Add a web route:**
1. Create controller in `src/Controller/`
2. Register in `ClaudrielServiceProvider::routes()` via `$router->addRoute(...)`

**Migrate a REST controller to GraphQL:**
1. Add `fieldDefinitions` to the entity's `EntityType` registration in `ClaudrielServiceProvider::register()`
2. Add the type to `GRAPHQL_TYPES` and `GRAPHQL_FIELDS` in `frontend/admin/app/host/claudrielAdapter.ts`
3. Remove REST routes from `ClaudrielServiceProvider::routes()`
4. Delete the REST controller and its test
5. Add schema contract tests in `tests/Integration/GraphQL/SchemaContractTest.php`

**Release a Waaseyaa framework fix:**
1. Edit code in `/home/jones/dev/waaseyaa/packages/<package>/`
2. Run relevant tests: `vendor/bin/phpunit packages/<package>/tests/`
3. Commit, push to main
4. Tag: `git tag v0.1.0-alpha.N && git push origin v0.1.0-alpha.N`
5. Wait for "Split Monorepo" GitHub Action to publish sub-packages
6. In Claudriel: update `composer.json` versions, run `composer update 'waaseyaa/*'`
7. Commit composer.json + composer.lock, push to trigger CI deploy

## GitHub Workflow

All work starts with an issue. Before writing code, ask for or create the issue number.

1. All work begins with an issue — check for or create one before writing code
2. Every issue belongs to a milestone — unassigned issues are incomplete triage
3. Milestones define the roadmap — check active milestone before proposing work
4. PRs must reference issues — title format `feat(#N): description`
5. Read drift report at session start — flag `bin/check-milestones` warnings first

See `docs/specs/workflow.md` for milestone list and versioning model.

## Internal API Routes (agent subprocess)

```
GET  /api/internal/gmail/list         # List Gmail messages
GET  /api/internal/gmail/read/{id}    # Read a Gmail message
POST /api/internal/gmail/send         # Send a Gmail message
GET  /api/internal/calendar/list      # List calendar events
POST /api/internal/calendar/create    # Create calendar event
```

All require HMAC Bearer token via `InternalApiTokenGenerator`. See `docs/specs/agent-subprocess.md`.

## Critical Gotchas

- **Skills orchestrate, API implements**: Entity CRUD skills (workspace, person, commitment, etc.) must call GraphQL mutations/queries, never create files/directories or manipulate storage directly. Base pattern is in `.claude/skills/_templates/entity-crud.md`
- **Intent parsing in skills**: Never use the full user sentence as an entity field value. Extract the noun phrase (entity name) and secondary intents separately. See any entity CRUD skill's "Intent Parsing" section for examples.
- `McEvent` is named to avoid PHP reserved-word conflicts with `Event`
- `ClaudrielServiceProvider::commands()` creates parallel `SqlEntityStorage` + `StorageRepositoryAdapter` instances alongside domain-provider-registered storage; this dual-instance pattern is intentional (#377)
- `CommitmentHandler` silently skips candidates with `confidence < 0.7`
- `DriftDetector::findDrifting()` only checks `status=active` + `updated_at < 48h`; pending commitments are NOT checked
- `GmailMessageNormalizer::normalize()` base64-decodes body with URL-safe alphabet (`-_` → `+/`)
- `InternalWorkspaceController::create()` must set `account_id` on new workspaces; without it, `WorkspaceAccessPolicy` denies access and workspaces are invisible in GraphQL/sidebar
- `WorkspaceAccessPolicy` matches ownership by numeric ID (admin SPA session) OR UUID string (agent subprocess HMAC token); both paths must be checked
- After `composer update 'waaseyaa/*'`, run `vendor/bin/phpstan analyse` and regenerate baseline (`--generate-baseline`) if `ignore.unmatched` errors appear; also check `tests/Feature/Access/AccessPolicyTestHelpers.php` anonymous classes implement all interface methods
- Repo entity lookup from workspace uses `WorkspaceRepoResolver` (class in `src/Support/`); query `workspace_repo` junction by `workspace_uuid`, then load Repo by `repo_uuid` — do not access `repo_path`/`repo_url` fields on workspace directly (#517)
- When refactoring a subsystem, update the relevant `docs/specs/` file. Stale specs cause agents to generate conflicting code.
- ConsoleKernel auto-discovery of CLI commands may need explicit wiring (issue #9 is open)
- `EntityRepositoryInterface::findBy()` returns `EntityInterface[]`, but most callers need `ContentEntityInterface`. Use `assert($entity instanceof ConcreteType)` for type narrowing (established pattern), or `/** @var */` for array-level annotations before `array_filter`
- Controllers accept an optional `$twig = null` parameter for forward-compatibility with the Waaseyaa DI resolver; do not remove it without checking injection wiring
- Pre-push hook blocks `curl_exec`; use `file_get_contents` with `stream_context_create` for HTTP requests
- PHPStan treats `$http_response_header` (from `file_get_contents`) as always-defined `list<string>`; suppress with `@phpstan-ignore isset.variable, booleanAnd.alwaysTrue, function.alreadyNarrowedType`
- Controllers that don't render templates but keep `?Environment $twig` for DI compat need `@phpstan-ignore constructor.unusedParameter`
- `->allowAll()` routes receive `AnonymousUser` for `$account` regardless of `->render()`; controllers needing the authenticated user must fall back to `AuthenticatedAccountSessionResolver` (see `GoogleOAuthController::resolveAccount()` pattern)
- `SqlSchemaHandler::ensureTable()` only creates tables, never adds columns to existing ones; fields added to `fieldDefinitions` after initial table creation require manual `ALTER TABLE` or table recreation (#353)
- Integration entity OAuth fields (`account_id`, `provider`, `access_token`, etc.) are stored in `_data` JSON blob, not as real columns; queries work via `json_extract` but field names must match exactly what `GoogleOAuthController::upsertIntegration()` saves (e.g., `provider_email` not `google_email`)
- `file_get_contents` needs `'ignore_errors' => true` in stream context to get response body on HTTP 4xx/5xx (otherwise returns `false`)
- Entity types without `fieldDefinitions` in their `EntityType` registration produce no GraphQL schema; add fieldDefinitions matching the entity class constructor fields
- `raw_payload` fields return raw JSON strings via GraphQL (REST controllers did `json_decode`); frontend consumers need `JSON.parse()`
- Production deploys to `claudriel.ai` via `/home/deployer/claudriel-prod/`; staging deploys to `claudriel.northcloud.one` via `/home/deployer/claudriel/`; pipeline: verify -> build -> staging -> production
- `NotFoundController` Twig is null in production (DI doesn't inject it); the inline HTML fallback is what users see for 404s, not the Twig template
- GraphQL endpoint (`/graphql`) is publicly accessible via `->allowAll()`; entity access guards filter items post-fetch, so unauthenticated requests get `total > 0` but empty `items`
- Admin SPA Nuxt proxy routes `/api/**` to `localhost:8081`; in production the admin runs as a static build, not a Nuxt server, so API calls must be handled by the PHP backend directly via Caddy reverse-proxy rules
- Admin adapter must use native `fetch()` not Nuxt `$fetch()` for backend API calls; `$fetch` resolves paths against `app.baseURL` (`/admin/`), turning `/api/schema/commitment` into `/admin/api/schema/commitment`
- Do not commit `public/admin/` to git; it is built by CI and listed in `.gitignore`
- Chat agent runs via Docker per-request (`docker run --rm -i`); requires `AGENT_DOCKER_IMAGE=claudriel-agent` and `CLAUDRIEL_API_URL=https://<domain>` in shared .env; the agent calls internal API routes through Caddy (PHP-FPM uses Unix socket, no TCP port)
- Commitment `direction` field defaults to `'outbound'`; existing commitments without direction are treated as outbound. Valid values: `'outbound'` (you owe them), `'inbound'` (they owe you)
- Commitment `fieldDefinitions` live in `CommitmentServiceProvider`, NOT `ClaudrielServiceProvider` (which has a duplicate EntityType registration without fieldDefinitions for DI wiring)
- `CommitmentCompletionDetector` exists but is not wired into any automated flow; it's a building block for future commitment lifecycle automation
- `DayBriefAssembler::assemble()` returns `waiting_on` (inbound pending commitments) inside `commitments` key, and `follow_ups` (unanswered emails) at top level; both have corresponding entries in `counts`
- `InMemoryStorageDriver` uses the entity's id key as storage key; when seeding multiple entities in tests, you MUST set unique IDs (e.g., `'eid' => 1`, `'eid' => 2`) or each `save()` overwrites the previous
- `EntityRepositoryInterface::findBy()` only supports exact-match key-value criteria, not range queries (`>=`, `LIKE`, etc.); date filtering or substring search must happen in PHP after fetching results
- Streaming controllers (`ChatStreamController`, `BriefStreamController`) MUST call `set_time_limit(0)` inside the `StreamedResponse` callback; PHP's default 30s `max_execution_time` kills SSE streams mid-response, producing `ERR_HTTP2_PROTOCOL_ERROR`
- Waaseyaa `resolveControllerInstance()` (v0.1.0-alpha.35+) checks the service resolver for pre-registered controller singletons before reflection; controllers with ambiguous constructor types (e.g., `EntityRepositoryInterface`) MUST be registered as singletons in their service provider or DI will fail with "Cannot resolve required parameter"
- Agent subprocess requires `cache_control: {"type": "ephemeral"}` on system prompt and last tool definition; prompt caching reduces cost but cached tokens still count toward rate limits — long sessions with many tool turns can still hit 30K input tokens/min even with caching enabled
- Agent retries 429 rate limit errors with exponential backoff (3 attempts, 5-60s); emits `progress` events during retry so frontend shows status instead of crashing
- Conversation history sent to the agent is capped at 20 messages; older assistant responses are truncated to 500 chars; the last 4 messages (2 exchanges) are always kept in full
- Ansible Caddyfile template (`waaseyaa-caddyfile.j2`) overwrites any hand-edited Caddy config on deploy; SSE-specific directives (stream path matcher, gzip exclusion, `Alt-Svc "clear"`) must be in the template itself (northcloud-ansible#2)
- Staging and production use separate Anthropic API keys from different workspaces: `vault_claudriel_staging_anthropic_api_key` (staging) and `vault_claudriel_anthropic_api_key` (production) in Ansible vault
- CLI commands crash on a fresh database (`no such table: artifact`) because entity types only registered locally in `commands()` (not in domain providers) don't get tables created by the `ensureTable` loop (#378)
- Admin registration notifications require `CLAUDRIEL_ADMIN_EMAIL` in `.env`; without it, no notification is sent (best-effort, never blocks verification flow)
- GraphQL mutations from the admin SPA require the Waaseyaa framework fix in `ControllerDispatcher` (waaseyaa/framework#602) to resolve session accounts on `allowAll()` routes; without it, mutations get `AnonymousUser` and fail with "Access denied"
- Deploy validation uses `--insecure` TLS fallback (curl exit codes 35/60) when staging cert is broken; this masks the real issue (#474) but keeps deploys flowing
- Workspace creation/deletion is handled by agent subprocess tools (`workspace_create`, `workspace_delete`, `repo_clone`), NOT by ChatStreamController regex (removed in #477); the agent handles intent parsing naturally
- PHP built-in dev server (`php -S`) is single-threaded; agent callbacks hang while SSE streams hold the thread. Use `PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8081 -t public` for local dev (#490)
- `GitRepositoryManager` is NOT registered in the DI container; instantiate inline with `new GitRepositoryManager` (same pattern as `ClaudrielServiceProvider`)
