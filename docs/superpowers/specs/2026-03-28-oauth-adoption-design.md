# OAuth Provider Adoption Design

**Date:** 2026-03-28
**Status:** Draft
**Issue:** jonesrussell/claudriel#637
**Depends on:** waaseyaa/oauth-provider package (waaseyaa/framework#721)

## Summary

Build the `waaseyaa/oauth-provider` package (per its existing spec), then refactor Claudriel to use it. Replace `GoogleOAuthController` with a unified `OAuthController` that handles both Google and GitHub OAuth via `ProviderRegistry`. Replace `GoogleTokenManager` with a provider-agnostic `OAuthTokenManager`. Add GitHub OAuth as a new capability (both sign-in and connect flows).

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

Replace `GoogleOAuthController` with `OAuthController`. Four route-facing methods, all provider-agnostic:

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
| github | connect | repo, user:email, read:user | `GITHUB_REDIRECT_URI` |
| github | signin | user:email, read:user | `GITHUB_SIGNIN_REDIRECT_URI` |

**Constructor dependencies:**
- `ProviderRegistry`
- `OAuthStateManager`
- `EntityTypeManager`
- `PublicAccountSignupService`

**Key behaviors:**
- State validation via `OAuthStateManager` (replaces manual `$_SESSION` state handling)
- `upsertIntegration()` generalized to accept provider name
- Sign-in callback uses `PublicAccountSignupService::createFromOAuth()` (generalized from `createFromGoogle()`)
- Session regeneration + CSRF regeneration preserved on sign-in
- All OAuth protocol logic (URL building, token exchange, user profile fetch) delegated to package providers

#### OAuthTokenManager

Replace `GoogleTokenManager` with provider-agnostic `OAuthTokenManager`.

**Interface:**
```php
interface OAuthTokenManagerInterface
{
    public function getValidAccessToken(string $accountId, string $provider = 'google'): string;
    public function hasActiveIntegration(string $accountId, string $provider = 'google'): bool;
}
```

**Implementation:**
- Constructor takes `EntityTypeManager` + `ProviderRegistry` (no more `$clientId`/`$clientSecret`)
- `refreshAccessToken()` calls `$this->providerRegistry->get($provider)->refreshToken($refreshToken)`
- Returns `OAuthToken` value object, maps onto integration entity fields
- GitHub tokens don't expire (`expiresAt = null`), so refresh is skipped entirely
- Integration marked `status=error` on refresh failure (preserved behavior)
- `parseHttpStatusCode()` deleted (package handles HTTP errors)

**Consumer impact:**
- `ChatServiceProvider` wires `OAuthTokenManager` instead of `GoogleTokenManager`
- `GoogleApiTrait` and agent tools call `getValidAccessToken($accountId, 'google')` (default param, backward compatible)

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

#### Service Wiring (ClaudrielServiceProvider)

- Build `GoogleOAuthProvider` and `GitHubOAuthProvider` from env vars
- Register both in `ProviderRegistry`
- Register `OAuthStateManager` with `NativeSessionAdapter`
- Register `OAuthController` as singleton (ambiguous constructor types)
- Validate new env vars: `GITHUB_CLIENT_ID`, `GITHUB_CLIENT_SECRET`, `GITHUB_REDIRECT_URI`, `GITHUB_SIGNIN_REDIRECT_URI`

#### PublicAccountSignupService

Generalize `createFromGoogle()` to `createFromOAuth(string $provider, string $email, string $name)`. Provider-specific methods (`createFromGoogle()`, `createFromGitHub()`) delegate to it.

#### Route Changes

Old routes removed:
- `/auth/google/redirect`, `/auth/google/callback`
- `/auth/google/signin`, `/auth/google/signin/callback`

New routes:
- `/oauth/google/connect`, `/oauth/google/connect/callback`
- `/oauth/google/signin`, `/oauth/google/signin/callback`
- `/oauth/github/connect`, `/oauth/github/connect/callback`
- `/oauth/github/signin`, `/oauth/github/signin/callback`

#### Integration Entity

No schema changes. GitHub integrations stored with `provider = 'github'`, `token_expires_at = null`, `refresh_token = null`.

## New Environment Variables

| Variable | Purpose |
|---|---|
| `GITHUB_CLIENT_ID` | GitHub OAuth app client ID |
| `GITHUB_CLIENT_SECRET` | GitHub OAuth app client secret |
| `GITHUB_REDIRECT_URI` | Callback URL for GitHub connect flow |
| `GITHUB_SIGNIN_REDIRECT_URI` | Callback URL for GitHub sign-in flow |

Existing Google env vars unchanged.

## Files

### Deleted
- `src/Controller/GoogleOAuthController.php`
- `src/Support/GoogleTokenManager.php`
- `src/Support/GoogleTokenManagerInterface.php`
- `tests/Unit/Support/GoogleTokenManagerTest.php`

### New
- `src/Controller/OAuthController.php`
- `src/Support/OAuthTokenManager.php`
- `src/Support/OAuthTokenManagerInterface.php`
- `src/Support/NativeSessionAdapter.php`
- `tests/Unit/Controller/OAuthControllerTest.php`
- `tests/Unit/Support/OAuthTokenManagerTest.php`

### Modified
- `composer.json` (add `waaseyaa/oauth-provider`)
- `src/Provider/ClaudrielServiceProvider.php` (provider registration, route changes, env validation)
- `src/Provider/ChatServiceProvider.php` (wire `OAuthTokenManager`)
- `src/Service/PublicAccountSignupService.php` (add `createFromOAuth()`)
- `CLAUDE.md` (update gotchas, env vars, route references)

## Testing Strategy

- `OAuthControllerTest`: mock `ProviderRegistry`, `OAuthStateManager`, `EntityTypeManager` to test all four flows (connect/signin x google/github), error paths (denied auth, invalid state, failed exchange), upsert logic
- `OAuthTokenManagerTest`: mock `ProviderRegistry` to test refresh path (Google), no-refresh path (GitHub), expired token detection, error-status marking
- Package tests built separately per package spec

## Error Handling

- Package exceptions propagate through controller (invalid code, network failure, insufficient scopes)
- Controller catches provider errors and sets flash messages for user-facing flows
- `OAuthTokenManager` marks integration `status=error` on refresh failure (preserved from `GoogleTokenManager`)
- GitHub `refreshToken()` throws `UnsupportedOperationException`, never called (token expiry check gates it)
