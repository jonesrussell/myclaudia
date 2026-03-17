# Google OAuth Integration Design

**Date:** 2026-03-16
**Status:** Draft
**Scope:** Phase 1 of 3 (OAuth foundation), with conceptual overview of Phases 2-3

## Context

Claudriel is moving to a multi-tenant SaaS product. Currently, Gmail and Calendar data flows through a Python sidecar that proxies Claude Code's MCP tools. This design replaces that with native Google OAuth per account, enabling direct API access from the PHP layer.

## Phase Overview

| Phase | Scope | Depends On |
|-------|-------|------------|
| 1 | Google OAuth flow, Integration entity, GoogleTokenManager | Account entity, existing auth middleware |
| 2 | Gmail, Calendar, Drive API clients; ingestion pipeline rewire | Phase 1 |
| 3 | Sidecar removal (delete code, deploy config, env vars) | Phase 2 stable |

This spec details Phase 1. Phases 2-3 are outlined conceptually to ensure the foundation supports them.

## Phase 1: Google OAuth per Account

### Integration Entity

Extends `ContentEntityBase`. Uses existing scaffold `entityKeys` (`'id' => 'iid'`, `'uuid' => 'uuid'`, `'label' => 'name'`).

Must be registered in `ClaudrielServiceProvider::register()` via `$this->entityType(new EntityType(...))` with an `EntityRepository` wired in the service container.

Fields (in addition to base entity fields):

| Field | Type | Notes |
|-------|------|-------|
| `account_id` | string | Required. FK to Account. Unique per `(account_id, provider)` |
| `provider` | string | `"google"`. Enum-like, extensible for future providers |
| `access_token` | string, nullable | Encrypted at rest. Null after revocation |
| `refresh_token` | string, nullable | Encrypted at rest. Required for long-lived integration |
| `token_expires_at` | datetime, nullable | Null until first token exchange |
| `scopes` | JSON array | Exact scopes Google returned, not what was requested |
| `status` | string | `pending`, `active`, `revoked`, `error` |
| `provider_email` | string, nullable | Google account email for display and debugging |
| `metadata` | JSON, nullable | Provider-specific extras (token_type, id_token claims, etc.) |

**Constraints:**
- Unique on `(account_id, provider)`: one Google integration per account for now
- Table auto-creates via Waaseyaa's `SqlSchemaHandler::ensureTable()`

### OAuth Flow

```
1. User clicks "Connect Google"
   → GET /auth/google

2. Controller generates state token via `bin2hex(random_bytes(32))`,
   stores in `$_SESSION['google_oauth_state']` (matching existing raw session pattern)
   → Redirects to Google consent URL with:
     - client_id (from env)
     - redirect_uri (from env)
     - response_type=code
     - scope: gmail.readonly gmail.send calendar.readonly calendar.events calendar.calendarlist.readonly calendar.freebusy drive.file
     - state: CSRF token
     - access_type=offline (to get refresh_token)
     - prompt=consent (force consent to ensure refresh_token)

3. User authorizes on Google
   → Google redirects to GET /auth/google/callback?code=...&state=...

4. Callback controller:
   a. Validate `$_SESSION['google_oauth_state']` matches `state` param (CSRF protection)
   b. Unset `$_SESSION['google_oauth_state']` immediately after validation
   c. Handle error params (error, access_denied) with clean UI message
   d. Exchange code for tokens via POST to https://oauth2.googleapis.com/token
   e. Fetch user info (email) from Google userinfo endpoint
   f. Query-before-write: check if Integration exists for (account_id, provider=google)
      - If exists: update tokens, scopes, status=active
      - If new: create with status=active
      - If scopes changed from previous: record scopes_changed_at in metadata (future use)
   g. Redirect to dashboard with success flash

5. Error states:
   - Google returns error param → redirect to dashboard with error message
   - State mismatch → 403, log suspicious activity
   - Token exchange fails → redirect with error, log details
```

### Scopes (Minimal, Non-Restricted, SaaS-Safe)

| Service | Scope | Purpose |
|---------|-------|---------|
| Gmail | `https://www.googleapis.com/auth/gmail.readonly` | Read inbox messages |
| Gmail | `https://www.googleapis.com/auth/gmail.send` | Send and reply to emails |
| Calendar | `https://www.googleapis.com/auth/calendar.readonly` | Read calendar events |
| Calendar | `https://www.googleapis.com/auth/calendar.events` | Create and edit events |
| Calendar | `https://www.googleapis.com/auth/calendar.calendarlist.readonly` | List available calendars |
| Calendar | `https://www.googleapis.com/auth/calendar.freebusy` | Check availability |
| Drive | `https://www.googleapis.com/auth/drive.file` | Per-file access only |

**Scope policy:** No restricted scopes. No Gmail add-on scopes. No full-drive or full-mailbox scopes. No calendar.acls or calendar.calendars scopes. This list is the single source of truth — `GoogleOAuthController::SCOPES` must match exactly.

Persist exactly what Google returns. Do not assume requested equals granted.

### GoogleTokenManager

**Interface:**

```php
interface GoogleTokenManagerInterface
{
    /**
     * Returns a valid access token for the given account.
     *
     * @throws IntegrationNotFoundException  No active Google integration
     * @throws TokenRefreshException         Refresh failed (revoked, invalid_grant)
     */
    public function getValidAccessToken(string $accountId): string;

    /**
     * Check if an account has an active Google integration.
     */
    public function hasActiveIntegration(string $accountId): bool;
}
```

**Behavior:**
1. Look up active Integration for `(accountId, provider=google)`
2. If `token_expires_at` is in the future (with 60s skew buffer), return `access_token`
3. If expired and `refresh_token` present:
   - POST to `https://oauth2.googleapis.com/token` with grant_type=refresh_token
   - Update Integration with new access_token, token_expires_at
   - Return new access_token
4. If refresh fails (`invalid_grant`, revoked):
   - Set Integration `status='error'`
   - Throw `TokenRefreshException`
   - UI surfaces "Reconnect Google" state

**Principle:** Gmail/Calendar/Drive clients receive only a valid access_token. They never know about refresh logic.

### Routes

| Method | Path | Controller | Auth |
|--------|------|------------|------|
| GET | `/auth/google` | `GoogleOAuthController::redirect` | Authenticated account |
| GET | `/auth/google/callback` | `GoogleOAuthController::callback` | Authenticated account (session) |

Registered in `ClaudrielServiceProvider::routes()`.

### New Files

```
src/Controller/GoogleOAuthController.php        # OAuth redirect + callback
src/Support/GoogleTokenManager.php              # Token lifecycle management
src/Support/GoogleTokenManagerInterface.php     # Contract
src/Entity/Integration.php                      # Updated with fields
```

**Controller method signatures** follow established Waaseyaa pattern:
```php
public function redirect(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null, ?Environment $twig = null): Response
public function callback(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null, ?Environment $twig = null): Response
```

**Service registration:** `GoogleTokenManager` and `GoogleOAuthController` wired in `ClaudrielServiceProvider::register()`. Integration entity type registered via `$this->entityType(new EntityType(...))`.

### Tenant Isolation

- Integration records scoped to Account, Account scoped to Tenant
- Existing `TenantWorkspaceResolver` middleware enforces tenant boundaries
- All queries go through account context; no raw Integration-by-id without account_id check
- Future admin dashboards filter by tenant, never global

### UI (Minimal for v1)

- Dashboard shows "Connect Google" button if no active integration
- After connecting: "Connected as user@gmail.com" with "Reconnect" option
- On error status: "Google connection lost. Reconnect" warning

### Environment Variables

```
GOOGLE_CLIENT_ID=          # OAuth client ID
GOOGLE_CLIENT_SECRET=      # OAuth client secret (encrypted/secured)
GOOGLE_REDIRECT_URI=       # e.g. https://claudriel.northcloud.one/auth/google/callback
```

Already added to `.env.example` and production `.env`.

## Phase 2: Google API Clients (Conceptual)

Once Phase 1 is stable:

- **GmailApiClient**: uses `GoogleTokenManager::getValidAccessToken()` to call Gmail REST API
  - `listMessages(accountId, query)`, `getMessage(accountId, messageId)`
  - Output matches format `GmailMessageNormalizer` already expects
- **CalendarApiClient**: same pattern for Calendar events
  - `listEvents(accountId, calendarId, timeMin, timeMax)`
  - Output feeds into `CalendarEventIngestHandler`
- **DriveApiClient**: file listing, metadata, content download
- **Ingestion rewire**: replace sidecar-based ingestion with direct API client calls
- All clients accept `accountId`, internally call `GoogleTokenManager`, never handle refresh

## Phase 3: Sidecar Removal (Conceptual)

Once Phase 2 is stable and ingestion runs directly:

- Delete `src/Domain/Chat/SidecarChatClient.php`
- Delete `src/Service/SidecarWorkspaceBootstrapService.php`
- Delete `docker/sidecar/`, `docker-compose.sidecar.yml`
- Remove sidecar deploy tasks from `deploy.php`
- Remove sidecar env vars from `.env.example` and production
- `AnthropicChatClient` remains (direct Claude API for chat)

## Gotchas

- Google only returns `refresh_token` on first consent or when `prompt=consent` is set. Always set `prompt=consent` for reliability.
- Token refresh can fail silently if the user revokes access from Google's security settings. The `error` status + UI reconnect flow handles this.
- Waaseyaa auto-creates tables, so no migration files needed. Integration entity fields are defined in the entity class.
- The `(account_id, provider)` uniqueness is enforced at the application layer via query-before-write in `GoogleOAuthController::callback`. The callback queries for existing Integration by `(account_id, provider)` before creating, and updates in place if found.
