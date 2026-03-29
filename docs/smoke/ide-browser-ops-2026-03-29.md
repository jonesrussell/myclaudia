# IDE browser smoke — ops admin (2026-03-29)

## Follow-up: smoke completed (same day)

After fixes below, a full pass was run in the Cursor IDE browser against:

- PHP: `CLAUDRIEL_DEV_CLI_SESSION=1 php -S 127.0.0.1:8100 -t public public/router.php`
- Nuxt: `NUXT_PUBLIC_PHP_ORIGIN=http://127.0.0.1:8100 npm run dev -- --port 3334 --host 127.0.0.1`

**Verified**

| Area | Result |
|------|--------|
| `/admin/today` | Loads; shell shows Today, Live brief / Refresh, mode nav |
| `/admin/workspaces` | Lists workspaces from GraphQL |
| `/admin/pipeline` | Pipeline page shell loads |
| Chat rail | Opens; “Agent chat”, message field, Send, New, Refresh |
| Auth | Session via `CLAUDRIEL_DEV_CLI_SESSION` (php -S only); Logout visible |

**Fixes that unblocked smoke**

1. `ClaudrielServiceProvider::boot()` and `ChatServiceProvider` internal secret: do not throw on missing env when `PHP_SAPI === 'cli-server'` (php -S), so incomplete `.env` still boots.
2. `AuthenticatedAccountSessionResolver`: optional `CLAUDRIEL_DEV_CLI_SESSION=1` + php -S uses first verified DB account for `/admin/session`.
3. `composer serve:php` and `CLAUDE.md`: php -S **must** use `public/router.php`.
4. `nuxt.config.ts`: Nitro dev proxy / routeRules use `NUXT_PUBLIC_PHP_ORIGIN` (default `http://localhost:8081`).
5. `.env.example`: default `CLAUDRIEL_ENV=development`, documented dev CLI session and `GITHUB_SIGNIN_REDIRECT_URI`.

---

## Original notes (historical)

Environment: Cursor IDE browser + WSL2 Claudriel tree. PHP built-in server on `127.0.0.1:8081`, Nuxt on `127.0.0.1:3333` (**default** since `devServer.port` in `frontend/admin/nuxt.config.ts`; historical note on :3000 below).

### Summary (before fixes)

- **Nuxt `/admin/today`**: Loads correctly when the real Nuxt dev server is reached (title “Claudriel Admin”, Nuxt DevTools console message).
- **Unauthenticated flow**: Middleware sends the browser to PHP `GET /login?redirect=…` (expected).
- **Blocker**: `GET http://localhost:8081/login` returned **HTTP 500** with an **empty body** in curl and a generic Chrome error page in the IDE browser. Ops surfaces (Today, workspaces, pipeline, chat rail) were **not** exerciseable past auth.
- **Root cause (Claudriel)**: `ClaudrielServiceProvider::boot()` threw `RuntimeException` when `CLAUDRIEL_ENV=production` and any required env var was empty. Missing `GITHUB_SIGNIN_REDIRECT_URI` was reported in CLI bootstrap logs; production mode turned that into a hard failure → 500 with no HTML in the web SAPI path used here.

### Environment / setup issues

| Issue | Severity | Notes |
|-------|----------|--------|
| **Port 3000 not Nuxt** | High (local dev) | `*:3000` was bound by **Mercure** (Caddy), not Nuxt. Requests to `http://127.0.0.1:3000/admin/today` returned **200 with `Content-Length: 0`**, producing a **blank white** page in the IDE browser and a misleading “success” from curl. |
| **Default Nuxt port** | — | Repo default is **3333** (`nuxt.config.ts`). Use `nuxt dev --port …` only if 3333 is taken. |
| **`127.0.0.1:3000` vs IDE browser** | Medium | First navigation to `http://127.0.0.1:3000/...` landed on `chrome-error://chromewebdata/`; `http://localhost:3000/...` reached Mercure. Behaviour depends on how the embedded browser resolves localhost; prefer one host consistently and verify listener with `ss -tlnp`. |
| **PHP `/login` 500** | High | Addressed by cli-server boot lenience + `router.php` + optional `CLAUDRIEL_DEV_CLI_SESSION` (see above). |

### Framework / boot noise (Waaseyaa + Claudriel)

Observed on PHP bootstrap (stderr / error_log):

- Duplicate entity registration failures:
  - `artifact` (OperationsServiceProvider vs existing registration)
  - `relationship`, `taxonomy_term`, `taxonomy_vocabulary` (Waaseyaa providers vs prior registration)

These match known duplicate-registration patterns in codified context; they clutter logs and can mask real failures during triage.

### IDE browser tooling notes

- **Network panel**: After Mercure-on-3000, only the main document request appeared (empty 200). With real Nuxt on 3333, the document load succeeded; subresource requests may not all appear in the MCP `browser_network_requests` summary.
- **Accessibility snapshot**: Useful once the app shell renders; after redirect to Chrome’s generic 500 page, only “Show Details” / “Reload” / “Copy” appear — no app UI to click through.
- **`localhost` vs `127.0.0.1`**: Both can behave differently for reachability from the IDE browser vs WSL; confirm with a non-empty HTML response and `_nuxt` script tags.

### Not exercised in this run (optional deeper smoke)

- **Live brief** stream, **Refresh brief** full payload, **Send** on agent chat (needs model/agent), **All data** grid, entity **create/edit** flows, **Logout** round-trip.
- **`useRealtime` / `/api/broadcast`** — off in dev by default; endpoint missing (#564).

### Optional follow-ups

- Resolve duplicate entity-type registration noise at boot (artifact, relationship, taxonomy).
- Production: HTML body on env-validation 500 for easier operator debugging.

### Historical commands (pre-fix)

```bash
ss -tlnp | grep -E ':3000|:8081|:3333'
curl -sS -D - -o /tmp/body http://127.0.0.1:3000/admin/today   # Mercure: empty 200
curl -sS -D - -o /tmp/body http://127.0.0.1:3333/admin/today   # Nuxt: HTML
```
