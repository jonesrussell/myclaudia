# OAuth Provider Adoption Design

**Date:** 2026-03-28
**Status:** Draft
**Issue:** jonesrussell/claudriel#637
**Depends on:** waaseyaa/oauth-provider package (waaseyaa/framework#721)

## Summary

Build the `waaseyaa/oauth-provider` package (per its existing spec), then refactor Claudriel to use it. Replace `GoogleOAuthController` and `GitHubOAuthController` with a unified `OAuthController` that handles both providers via `ProviderRegistry`. Replace `GoogleTokenManager` and `GitHubTokenManager` with a provider-agnostic `OAuthTokenManager`. Add GitHub sign-in as a new flow (GitHub connect already exists).

## Scope

### Phase 1: Build waaseyaa/oauth-provider package

Build per the validated spec at `docs/superpowers/specs/2026-03-28-oauth-provider-package-design.md`. Delivers:

- `OAuthProviderInterface` with `getAuthorizationUrl()`, `exchangeCode()`, `refreshToken()`, `getUserProfile()`
- `OAuthToken` (immutable value object: accessToken, refreshToken, expiresAt, scopes, tokenType)
- `OAuthUserProfile` (immutable value object: providerId, email, name, avatarUrl)
- `OAuthStateManager` + `SessionInterface` for CSRF state management
- `ProviderRegistry` for name-based provider lookup
- `GoogleOAuthProvider` and `GitHubOAuthProvider` concrete implementations
- `UnsupportedOperationException` (thrown by GitHub's `refreshToken()`)
- Uses `waaseyaa/http-client` (`HttpClientInterface` / `StreamHttpClient`)

### Phase 2: Claudriel adoption

#### Unified OAuthController

Replace both `GoogleOAuthController` and `GitHubOAuthController` with a single `OAuthController`. Four route-facing methods, all provider-agnostic:

| Route | Method | Purpose |
|---|---|---|
| `GET /oauth/{provider}/connect` | `connect()` | Link provider to existing account |
| `GET /oauth/{provider}/connect/callback` | `connectCallback()` | Handle connect callback |
| `GET /oauth/{provider}/signin` | `signin()` | Authenticate/create account via provider |
| `GET /oauth/{provider}/signin/callback` | `signinCallback()` | Handle sign-in callback |

**Flow config map** (defined as class constant):

| Provider | Flow | Scopes | Redirect URI env var |
|---|---|---|---|
| google | connect | userinfo.email, gmail.readonly, gmail.send, calendar.readonly, calendar.events, calendar.calendarlist.readonly, calendar.freebusy, drive.file | `GOOGLE_REDIRECT_URI` |
| google | signin | openid, userinfo.email, userinfo.profile | `GOOGLE_SIGNIN_REDIRECT_URI` |
| github | connect | repo, notifications, read:org | `GITHUB_REDIRECT_URI` |
| github | signin | user:email, read:user | `GITHUB_SIGNIN_REDIRECT_URI` |

**Constructor dependencies:**
- `ProviderRegistry` (from package)
- `OAuthStateManager` (from package)
- `EntityTypeManager` (for integration upsert)
- `PublicAccountSignupService` (for sign-in account creation)

**Key behaviors:**
- State validation via `OAuthStateManager` (replaces manual `$_SESSION` state handling in both controllers)
- `upsertIntegration()` generalized to accept provider name, handles both Google (refresh token, expiry) and GitHub (no refresh token, no expiry) patterns
- Sign-in callback uses `PublicAccountSignupService::createFromOAuth()` (generalized from `createFromGoogle()`)
- Session regeneration + CSRF regeneration preserved on sign-in
- All OAuth protocol logic (URL building, token exchange, user profile fetch) delegated to package providers
- GitHub scope separator is comma (not space like Google); the package handles this per-provider

#### OAuthTokenManager

Replace both `GoogleTokenManager` and `GitHubTokenManager` with a single provider-agnostic `OAuthTokenManager`.

**Interface:**
```php
interface OAuthTokenManagerInterface
{
    public function getValidAccessToken(string $accountId, string $provider = 'google'): string;
    public function hasActiveIntegration(string $accountId, string $provider = 'google'): bool;
    public function markRevoked(string $accountId, string $provider): void;
}
```

Note: `markRevoked()` is preserved from `GitHubTokenManagerInterface`. It works for any provider.

**Implementation:**
- Constructor takes `EntityRepositoryInterface` (for integration entity) + `ProviderRegistry`
- Uses `EntityRepositoryInterface::findBy()` consistently (not `EntityTypeManager::getStorage()`, resolving the current Google/GitHub inconsistency)
- For providers with token expiry (Google): checks `token_expires_at`, calls `$this->providerRegistry->get($provider)->refreshToken($refreshToken)` when expired
- For providers without expiry (GitHub): returns access token directly, no refresh attempt
- Returns `OAuthToken` value object from refresh, maps onto integration entity fields
- Integration marked `status=error` on refresh failure (preserved behavior)
- Checks for `status=revoked` and gives specific error message (preserved from `GitHubTokenManager`)
- `parseHttpStatusCode()` deleted (package handles HTTP errors)

**Consumer impact:**
- `ChatServiceProvider` wires `OAuthTokenManager` instead of both `GoogleTokenManager` and `GitHubTokenManager`
- `GoogleApiTrait` calls `getValidAccessToken($accountId, 'google')` (default param, zero changes needed)
- GitHub agent tools call `getValidAccessToken($accountId, 'github')`
- `markRevoked()` callers updated to pass provider name

#### NativeSessionAdapter

Thin adapter bridging Claudriel's `$_SESSION` to the package's `SessionInterface`:

```php
class NativeSessionAdapter implements SessionInterface
{
    public function get(string $key): mixed { return $_SESSION[$key] ?? null; }
    public function set(string $key, mixed $value): void { $_SESSION[$key] = $value; }
    public function remove(string $key): void { unset($_SESSION[$key]); }
}
```

#### Service Wiring

**AccountServiceProvider** (where OAuth routes currently live):
- Route registration updated to point to `OAuthController` with new URL patterns
- Old routes removed, new `/oauth/{provider}/*` routes added

**ChatServiceProvider** (where token managers are wired):
- Replace `GoogleTokenManagerInterface` singleton with `OAuthTokenManagerInterface`
- Replace `GitHubTokenManagerInterface` singleton with same `OAuthTokenManagerInterface` instance
- Build `ProviderRegistry` with both providers from env vars
- Register `OAuthStateManager` with `NativeSessionAdapter`
- Register `OAuthController` as singleton

**ClaudrielServiceProvider**:
- Add `GITHUB_SIGNIN_REDIRECT_URI` to env var validation (other GitHub env vars already validated)
- Remove Google env var validation if moved to ChatServiceProvider (or keep, depending on where providers are built)

#### PublicAccountSignupService

Generalize `createFromGoogle()` to `createFromOAuth(string $provider, string $email, string $name)`. The existing `createFromGoogle()` method delegates to it for backward compatibility.

#### Route Changes

Old routes removed:
- `/auth/google` (connect redirect)
- `/auth/google/callback`
- `/auth/google/signin`
- `/auth/google/signin/callback`
- `/github/connect`
- `/github/callback`

New routes:
- `/oauth/google/connect`, `/oauth/google/connect/callback`
- `/oauth/google/signin`, `/oauth/google/signin/callback`
- `/oauth/github/connect`, `/oauth/github/connect/callback`
- `/oauth/github/signin`, `/oauth/github/signin/callback`

#### Integration Entity

No schema changes. GitHub integrations already stored with `provider = 'github'`, `token_expires_at = null`, `refresh_token = null`.

## New Environment Variables

| Variable | Purpose | Status |
|---|---|---|
| `GITHUB_CLIENT_ID` | GitHub OAuth app client ID | Already exists |
| `GITHUB_CLIENT_SECRET` | GitHub OAuth app client secret | Already exists |
| `GITHUB_REDIRECT_URI` | Callback URL for GitHub connect flow | Already exists |
| `GITHUB_SIGNIN_REDIRECT_URI` | Callback URL for GitHub sign-in flow | **New** |

Existing Google env vars unchanged.

## Files

### Deleted
- `src/Controller/GoogleOAuthController.php`
- `src/Controller/GitHubOAuthController.php`
- `src/Support/GoogleTokenManager.php`
- `src/Support/GoogleTokenManagerInterface.php`
- `src/Support/GitHubTokenManager.php`
- `src/Support/GitHubTokenManagerInterface.php`
- `tests/Unit/Support/GoogleTokenManagerTest.php`
- `tests/Unit/Support/GitHubTokenManagerTest.php`
- `tests/Unit/Controller/GitHubOAuthControllerTest.php`

### New
- `src/Controller/OAuthController.php`
- `src/Support/OAuthTokenManager.php`
- `src/Support/OAuthTokenManagerInterface.php`
- `src/Support/NativeSessionAdapter.php`
- `tests/Unit/Controller/OAuthControllerTest.php`
- `tests/Unit/Support/OAuthTokenManagerTest.php`

### Modified
- `composer.json` (add `waaseyaa/oauth-provider`)
- `src/Provider/AccountServiceProvider.php` (route changes)
- `src/Provider/ChatServiceProvider.php` (wire `OAuthTokenManager`, `ProviderRegistry`, `OAuthStateManager`)
- `src/Provider/ClaudrielServiceProvider.php` (env validation update)
- `src/Service/PublicAccountSignupService.php` (add `createFromOAuth()`)
- `CLAUDE.md` (update gotchas, env vars, route references)

## Testing Strategy

- `OAuthControllerTest`: mock `ProviderRegistry`, `OAuthStateManager`, `EntityTypeManager` to test all four flows (connect/signin x google/github), error paths (denied auth, invalid state, failed exchange), upsert logic
- `OAuthTokenManagerTest`: mock `EntityRepositoryInterface` and `ProviderRegistry` to test refresh path (Google), no-refresh path (GitHub), expired token detection, revoked integration detection, error-status marking
- Package tests built separately per package spec

## Error Handling

- Package exceptions propagate through controller (invalid code, network failure, insufficient scopes)
- Controller catches provider errors and sets flash messages for user-facing flows
- `OAuthTokenManager` marks integration `status=error` on refresh failure (preserved from `GoogleTokenManager`)
- `OAuthTokenManager` checks for `status=revoked` with specific error message (preserved from `GitHubTokenManager`)
- GitHub `refreshToken()` throws `UnsupportedOperationException`, never called (token expiry check gates it)
