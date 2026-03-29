# Admin SPA Spec

## File Map

| File / Directory | Purpose |
|-----------------|---------|
| `frontend/admin/nuxt.config.ts` | Core config: SPA mode, base URL `/admin/`, dev proxy |
| `frontend/admin/package.json` | Dependencies (Nuxt 4.4.2, Vue 3.5.31, TypeScript 6.0.2) |
| `frontend/admin/app/host/claudrielAdapter.ts` | GraphQL transport adapter (CRUD ops, field mappings, TextValue wrapping) |
| `frontend/admin/app/host/hostAdapter.ts` | HostAdapter interface contract |
| `frontend/admin/app/utils/graphqlFetch.ts` | HTTP GraphQL client (15s timeout, credentials: include) |
| `frontend/admin/app/composables/` | Auth, schema, ops (`useDayBrief`, `useWorkspaceScope`, `useOpsGraphql`, `useChatRail`), realtime, i18n |
| `frontend/admin/app/pages/` | Ops routes (`/today`, `/workspaces`, `/pipeline`, `/data`), entity CRUD, login, telescope |
| `frontend/admin/app/components/` | Layout, `ops/*` (Today/GitHub/pipeline/chat), widgets, entity detail, telescope |
| `frontend/admin/app/ops/viewRegistry.ts` | Custom list views (`prospect` → `/pipeline`) |
| `frontend/admin/app/types/dayBrief.ts` | Typed subset of `GET /brief` JSON |
| `frontend/admin/app/middleware/auth.global.ts` | Client-side auth guard (UX redirect, PHP is authoritative) |
| `frontend/admin/app/i18n/en.json`, `fr.json` | Entity labels + ops UI strings (`ops_*` keys) |

## Architecture

- **Framework**: Nuxt 4.4.2 (Vue 3, SPA mode, SSR disabled)
- **Build**: Vite via Nuxt
- **Base URL**: `/admin/`
- **UI**: Custom widget components (no external UI library)
- **Testing**: Vitest + @vue/test-utils + happy-dom; Playwright E2E

## API Communication

### GraphQL (primary)

Single `/graphql` endpoint. Dev: Nitro proxy to `http://localhost:8081/graphql`.

**Day brief & chat streams**: `GET /brief` and `/stream/**` are proxied in dev (`nuxt.config.ts` `devProxy` + `routeRules`) so the browser stays same-origin with the SPA; use native `fetch` / `EventSource` with `credentials: include`.

`graphqlFetch()` utility handles typed queries with 15s timeout, `credentials: include`.

**claudrielAdapter.ts** (290 lines):
- `GRAPHQL_TYPES`: 11 core entity types + 4 audit types
- `GRAPHQL_FIELDS`: Per-type field lists for queries
- `LABEL_FIELDS`: Default label field per entity type
- `flattenTextValues()` / `wrapTextValues()`: Bridge `text_long` GraphQL TextValue format

Entity types in catalog: workspace, project, person, commitment, schedule_entry, triage_entry, pipeline_config, prospect, filtered_prospect, prospect_attachment, prospect_audit.

### REST (backup)

- `/api/*` — General API (Nitro devProxy), includes `/api/chat/send`, `/api/chat/sessions/...`, `/api/internal/session/.../continue`
- `/brief` — Day brief JSON (`useDayBrief` / Today page)
- `/admin/session` — Session fetch
- `/admin/logout` — Logout

### Realtime (SSE, conditional)

- `useRealtime()` composable connects to `/api/broadcast` with channel param
- Events: `entity.saved`, `entity.deleted`, `connected`
- Disabled in dev by default; 10 retry limit, max 100 message buffer
- `/api/broadcast` does not exist yet (#564); composable error-loops on reconnection

## Page Routes

| Route | Purpose |
|-------|---------|
| `/` | Redirects to `/today` |
| `/today` | Day brief dashboard (`GET /brief`), GitHub panel, deep links to entities |
| `/workspaces` | Workspace picker |
| `/workspaces/[uuid]` | Hub: prospects for workspace; tenant-wide commitments / schedule / triage lists |
| `/pipeline` | Kanban-style prospects (`?workspace=` optional filter) |
| `/data` | Legacy entity-type card grid (all GraphQL types) |
| `/[entityType]/` | List entities (`prospect` redirects to `/pipeline`) |
| `/[entityType]/create` | Create form |
| `/[entityType]/[id]` | Detail + edit + relationships |
| `/login` | Auth redirect |
| `/telescope/codified-context/[sessionId]` | Context review UI |

**Shell**: [`AdminShell`](frontend/admin/app/components/layout/AdminShell.vue) adds a collapsible right **Chat** rail (`OpsChatRail` → `useChatRail`: `POST /api/chat/send` + `EventSource` `/stream/chat/{messageId}`). Brand link targets `/today`.

**Tracking**: GitHub issue [#644](https://github.com/jonesrussell/claudriel/issues/644) (ops-first admin).

## Key Composables

| Composable | Purpose |
|------------|---------|
| `useAuth()` | Session state, login/logout, entity type catalog |
| `useSchema(entityType)` | Cached schema fetch + sorted properties |
| `useEntity(type, id)` | Single entity fetch + mutations |
| `useCommitmentsQuery()` | Paginated commitment list |
| `usePeopleQuery()` | Paginated person list |
| `useRealtime(channels)` | SSE broadcast with reconnect |
| `useLanguage()` | i18n with entityLabel() fallback |
| `useCodifiedContext()` | Codified context details |
| `useNavGroups()` | Navigation catalog building |
| `useEntityDetailConfig()` | Per-type field config overrides |
| `useClock()` | Clock display |
| `useDayBrief()` | `GET /brief` for Today dashboard |
| `useWorkspaceScope()` | Optional `workspace_uuid` for brief/chat; syncs `/workspaces/:uuid` and `?workspace=` |
| `useOpsGraphql()` | Workspace/prospect/commitment/schedule/triage list helpers for hub + pipeline |
| `useChatRail()` | Agent chat send + SSE stream + continuation bar |

## Config Vars

| Variable | Default | Purpose |
|----------|---------|---------|
| `NUXT_PUBLIC_ENABLE_REALTIME` | false (dev), true (prod) | Enable SSE broadcast |
| `NUXT_PUBLIC_APP_NAME` | "Claudriel Admin" | Page title |
| `NUXT_PUBLIC_PHP_ORIGIN` | `http://localhost:8081` (dev; unset in prod builds) | PHP backend for Nitro `devProxy` / `routeRules` and `runtimeConfig.public.phpOrigin` (`publicPhpOrigin()` in `nuxt.config.ts`: dev matches this default, prod `''` for same-origin) |
| `PLAYWRIGHT_BASE_URL` | `http://localhost:3333/admin` | E2E test base URL |

## Build / Deploy

- `npm run dev` — Nuxt dev server on **:3333** by default (`nuxt.config.ts` `devServer.port`), avoiding collisions with Mercure on :3000; override with `nuxt dev --port …` if needed
- `composer serve:php` — `php -S 0.0.0.0:8081 -t public public/router.php` (**must** pass `public/router.php` so `/login`, `/graphql`, etc. are routed to `index.php`; `-t public` alone serves static files and breaks the API)
- Local smoke without OAuth: set `CLAUDRIEL_DEV_CLI_SESSION=1` in the environment for the PHP process (php -S only); uses the first **verified** account in the DB for `/admin/session`. Never enable in production.
- `npm run build` — Production static build
- Production: static build served by Caddy (not a running Nuxt server)
- `public/admin/` is built by CI, listed in `.gitignore`

## Local dev pitfalls

- **Port 3000**: Mercure or other tools often bind :3000; hitting that URL expecting Nuxt yields empty 200s and a blank page. Default admin dev is **:3333**; `config/waaseyaa.php` allows both 3333 and 3000 origins for CORS.
- **PHP `/login` 500**: With **php-fpm/Caddy** and `CLAUDRIEL_ENV=production`, missing required env vars still fail hard at boot. Under **php -S** (`cli-server`), boot logs missing vars but continues so local `/login` works. Prefer `CLAUDRIEL_ENV=development` in copied `.env` (see `.env.example`).
- **php -S without `router.php`**: Requests hit static files or 404; use `composer serve:php` or `php -S … -t public public/router.php`.

## Known Constraints

- Must use native `fetch()` not Nuxt `$fetch()` for backend API calls (`$fetch` resolves against `app.baseURL` `/admin/`)
- Client-side auth middleware is UX only; PHP backend is authoritative for security
- SSE disabled in dev to avoid single-process request starvation with `php -S`
- 15s fixed GraphQL timeout in `graphqlFetch`
- TextValue wrapping is hardcoded per entity type for `text_long` fields
- Schema caching is in-memory Map per entityType (no persistence)
- Playwright E2E spins up both PHP (:8081) and Nuxt (:3333); skips chat tests in CI
