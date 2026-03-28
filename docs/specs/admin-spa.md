# Admin SPA Spec

## File Map

| File / Directory | Purpose |
|-----------------|---------|
| `frontend/admin/nuxt.config.ts` | Core config: SPA mode, base URL `/admin/`, dev proxy |
| `frontend/admin/package.json` | Dependencies (Nuxt 4.4.2, Vue 3.5.31, TypeScript 6.0.2) |
| `frontend/admin/app/host/claudrielAdapter.ts` | GraphQL transport adapter (CRUD ops, field mappings, TextValue wrapping) |
| `frontend/admin/app/host/hostAdapter.ts` | HostAdapter interface contract |
| `frontend/admin/app/utils/graphqlFetch.ts` | HTTP GraphQL client (15s timeout, credentials: include) |
| `frontend/admin/app/composables/` | 14 composables (auth, schema, queries, realtime, i18n) |
| `frontend/admin/app/pages/` | 7 dynamic routes (entity CRUD, login, telescope) |
| `frontend/admin/app/components/` | Layout, widgets, entity detail views, telescope |
| `frontend/admin/app/middleware/auth.global.ts` | Client-side auth guard (UX redirect, PHP is authoritative) |
| `frontend/admin/app/i18n/en.json`, `fr.json` | 121 i18n keys (entity labels, field labels, UI strings) |

## Architecture

- **Framework**: Nuxt 4.4.2 (Vue 3, SPA mode, SSR disabled)
- **Build**: Vite via Nuxt
- **Base URL**: `/admin/`
- **UI**: Custom widget components (no external UI library)
- **Testing**: Vitest + @vue/test-utils + happy-dom; Playwright E2E

## API Communication

### GraphQL (primary)

Single `/graphql` endpoint. Dev: Nitro proxy to `http://localhost:8081/graphql`.

`graphqlFetch()` utility handles typed queries with 15s timeout, `credentials: include`.

**claudrielAdapter.ts** (290 lines):
- `GRAPHQL_TYPES`: 11 core entity types + 4 audit types
- `GRAPHQL_FIELDS`: Per-type field lists for queries
- `LABEL_FIELDS`: Default label field per entity type
- `flattenTextValues()` / `wrapTextValues()`: Bridge `text_long` GraphQL TextValue format

Entity types in catalog: workspace, project, person, commitment, schedule_entry, triage_entry, pipeline_config, prospect, filtered_prospect, prospect_attachment, prospect_audit.

### REST (backup)

- `/api/*` — General API (Nitro devProxy)
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
| `/[entityType]/` | List all entities of a type |
| `/[entityType]/create` | Create form |
| `/[entityType]/[id]` | Detail + edit + relationships |
| `/login` | Auth redirect |
| `/telescope/codified-context/[sessionId]` | Context review UI |

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

## Config Vars

| Variable | Default | Purpose |
|----------|---------|---------|
| `NUXT_PUBLIC_ENABLE_REALTIME` | false (dev), true (prod) | Enable SSE broadcast |
| `NUXT_PUBLIC_APP_NAME` | "Claudriel Admin" | Page title |
| `NUXT_PUBLIC_PHP_ORIGIN` | — | Override PHP backend origin |
| `PLAYWRIGHT_BASE_URL` | `http://localhost:3000/admin` | E2E test base URL |

## Build / Deploy

- `npm run dev` — Nuxt dev server on :3000
- `npm run build` — Production static build
- Production: static build served by Caddy (not a running Nuxt server)
- `public/admin/` is built by CI, listed in `.gitignore`

## Known Constraints

- Must use native `fetch()` not Nuxt `$fetch()` for backend API calls (`$fetch` resolves against `app.baseURL` `/admin/`)
- Client-side auth middleware is UX only; PHP backend is authoritative for security
- SSE disabled in dev to avoid single-process request starvation with `php -S`
- 15s fixed GraphQL timeout in `graphqlFetch`
- TextValue wrapping is hardcoded per entity type for `text_long` fields
- Schema caching is in-memory Map per entityType (no persistence)
- Playwright E2E spins up both PHP (:8081) and Nuxt (:3000); skips chat tests in CI
