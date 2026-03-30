# Web & CLI Specification

## File Map

| File | Purpose |
|------|---------|
| `src/Controller/DayBriefController.php` | `GET /brief` — returns JSON day brief |
| `src/Controller/InternalCodeTaskController.php` | `POST /api/internal/code-tasks/create`, `GET /api/internal/code-tasks/{uuid}/status` |
| `src/Command/BriefCommand.php` | `claudriel:brief` — prints day brief to terminal |
| `src/Command/CommitmentsCommand.php` | `claudriel:commitments` — lists active commitments |
| `src/Command/CodeTaskRunCommand.php` | `claudriel:code-task:run {uuid}` — executes a queued code task |
| `src/McClaudiaServiceProvider.php` | Registers routes + entity types |
| `public/index.php` | HTTP entry point (Waaseyaa HttpKernel) |
| `bin/waaseyaa` | CLI entry point (Waaseyaa ConsoleKernel) |
| `templates/day-brief.html.twig` | Twig template (exists, not yet rendered) |

## Route Registration

```php
// In McClaudiaServiceProvider::routes(WaaseyaaRouter $router)
$router->addRoute(
    'claudriel.brief',
    RouteBuilder::create('/brief')
        ->controller(DayBriefController::class . '::show')
        ->allowAll()
        ->methods('GET')
        ->build(),
);
```

## CLI Command Registration

Commands use Symfony Console `#[AsCommand]` attribute:

```php
#[AsCommand(name: 'claudriel:brief', description: 'Show your Day Brief')]
final class BriefCommand extends Command { ... }
```

**Known issue:** ConsoleKernel auto-discovery may not pick up commands automatically (issue #9 — "wire CLI commands into ConsoleKernel" is open). Manual registration in kernel bootstrap may be needed.

## DayBriefController

```php
public function show(): Response
// Calls DayBriefAssembler::assemble(tenantId: 'default', since: -24 hours)
// Returns: Symfony\Component\HttpFoundation\Response with JSON body
// Content-Type: application/json, HTTP 200
```

## BriefCommand Output Format

```
<info>Day Brief</info>

<comment>Recent events (N)</comment>
  [source] type

<comment>Pending commitments (N)</comment>
  • Title (80% confidence)

<error>Drifting (no activity 48h+)</error>  ← only if drifting_commitments non-empty
  ! Title
```

## CommitmentsCommand Output Format

```
[STATUS] Title
```
Outputs "No active commitments." if none found. Uses `findBy(['status' => 'active'])`.

## Waaseyaa Kernels

```
public/index.php  → new Waaseyaa\Foundation\Kernel\HttpKernel(dirname(__DIR__))
bin/waaseyaa      → new Waaseyaa\Foundation\Kernel\ConsoleKernel(dirname(__DIR__))
```

Both kernels resolve service providers from `src/McClaudiaServiceProvider.php` automatically if registered in config.

## InternalCodeTaskController

HMAC-authenticated internal API for the agent subprocess to create and monitor code tasks.

```php
// POST /api/internal/code-tasks/create
// Body: { "repo": "owner/name", "prompt": "...", "branch_name"?: "..." }
// Returns: { "task_uuid": "...", "status": "queued", "branch_name": "..." }
public function create(...): SsrResponse

// GET /api/internal/code-tasks/{uuid}/status
// Returns: { "uuid", "status", "branch_name", "pr_url", "summary", "diff_preview", "error", "started_at", "completed_at" }
public function status(...): SsrResponse
```

The `create` endpoint resolves or creates a workspace + repo for the given GitHub repo, saves a `CodeTask` entity, then dispatches `claudriel:code-task:run {uuid}` as a background process.

## CodeTaskRunCommand

```php
#[AsCommand(name: 'claudriel:code-task:run', description: 'Execute a queued code task via Claude Code CLI')]
// Argument: uuid (required) — CodeTask UUID
// Loads CodeTask, resolves repo path via GitRepositoryManager, delegates to CodeTaskRunner::run()
// Exit: SUCCESS if task completed, FAILURE otherwise
```

## OAuth System

`OAuthController` provides a unified OAuth flow for both account connection (adding provider integrations to an existing account) and sign-in/sign-up (creating or logging into an account via OAuth).

### Flow Scopes

Each provider has separate scope sets for `connect` vs `signin`:

- **Google connect**: Gmail, Calendar, Drive scopes (full API access for integrations)
- **Google signin**: `openid`, `userinfo.email`, `userinfo.profile` (minimal identity)
- **GitHub connect**: `repo`, `notifications`, `read:org`
- **GitHub signin**: `user:email`, `read:user`

### Routes

| Route | Method | Purpose |
|-------|--------|---------|
| `/auth/{provider}/connect` | GET | Redirect to OAuth provider for integration connection (requires authenticated session) |
| `/auth/{provider}/connect/callback` | GET | Handle connect callback, upsert Integration entity |
| `/auth/{provider}/signin` | GET | Redirect to OAuth provider for sign-in/sign-up (public) |
| `/auth/{provider}/signin/callback` | GET | Handle signin callback, create-or-find account, establish session |

### Signin Flow (`/auth/{provider}/signin`)

1. Stores `oauth_post_login_redirect` in session (sanitized via `ClaudrielAdminHost::sanitizeRedirectTarget`)
2. Sets `oauth_flow = 'signin'` in session
3. Uses `{provider}-signin` registry key if available (separate redirect URI), falls back to base provider
4. On callback: validates state + flow, exchanges code, gets user profile
5. Calls `PublicAccountSignupService::createFromOAuth()` to find-or-create account
6. Sets `claudriel_account_uuid` session, regenerates session ID + CSRF
7. Redirects to stored `oauth_post_login_redirect` (or `/app` default)

### Integration Upsert

`upsertIntegration()` creates or updates an `Integration` entity keyed by `account_id` + `provider`. On update, preserves existing `refresh_token` if the new token lacks one. Tracks `scopes_changed_at` in metadata when scopes differ.

### OAuthTokenManager

Token refresh uses a 60-second expiry buffer: tokens are refreshed before they actually expire to prevent mid-request failures. Uses base provider names (`google`/`github`) for refresh since redirect URI is not needed for token refresh.

## PublicSessionController

### Login Error Handling

The `login()` method differentiates three failure modes:

1. **Account not found**: `findVerifiedAccountByEmail()` returns null. Shows generic "Invalid credentials." error.
2. **OAuth-only account** (no password hash): Account exists but `password_hash` is empty. Shows provider-specific guidance: "This account uses Google sign-in. Use the 'Sign in with Google' button below." with `show_google_signin = true` template variable.
3. **Wrong password**: `password_verify()` fails. Shows generic "Invalid credentials." error.

## PublicAccountController

### Admin Notification on Verification

`PublicAccountSignupService` accepts an optional `adminEmail` parameter sourced from the `CLAUDRIEL_ADMIN_EMAIL` env var. When set, an admin notification is sent on successful email verification. This is best-effort: notification failure never blocks the verification flow.

### Templates

- `login.twig` and `signup.twig` include Google OAuth signin/signup buttons. Both receive `public_origin` from the request for constructing OAuth redirect URLs.
- `login.twig` conditionally shows the Google sign-in prompt when `show_google_signin` is set (OAuth-only account attempted password login).

## Adding New Routes

1. Create controller in `src/Controller/`
2. Add `->addRoute(name, RouteBuilder::create('/path')...->build())` in `McClaudiaServiceProvider::routes()`
3. Name routes as `claudriel.<name>` for clarity

## Adding New Commands

1. Create in `src/Command/`, extend `Symfony\Component\Console\Command\Command`
2. Add `#[AsCommand(name: 'claudriel:foo')]` attribute
3. Verify ConsoleKernel picks it up (see issue #9)
