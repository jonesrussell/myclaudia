# GitHub Integration Design Spec

**Date:** 2026-03-21
**Status:** Approved
**Closes:** TBD (issue to be created)

## Goal

Add full GitHub integration to Claudriel: OAuth authentication, internal API endpoints for the agent subprocess, chat tools, and event ingestion into the day brief via polling.

## Relationship to Existing Work

The IssueOrchestrator spec (2026-03-16) describes a higher-level workflow automation layer that binds GitHub Issues to workspaces and Claude Code sidecar execution. This integration provides the **foundation layer** underneath it: OAuth token management, raw API access, event ingestion, and agent tools. The IssueOrchestrator becomes a consumer of this foundation and remains unchanged.

```
GitHub Integration (this spec)
  +-- OAuth + token management
  +-- Internal API endpoints
  +-- Agent chat tools
  +-- Event ingestion -> McEvent -> Day Brief
  |
  +-- IssueOrchestrator (existing spec, consumes the above)
      +-- IssueRun entity + lifecycle
      +-- CodexExecutionPipeline integration
      +-- Chat intent detection
```

## Component 1: GitHub OAuth

### Files

- `src/Controller/GitHubOAuthController.php`
- `src/Support/GitHubTokenManager.php`

### Flow

1. User visits `/github/connect` -> redirect to `github.com/login/oauth/authorize` with scopes `repo`, `notifications`, `read:org`
2. GitHub redirects to `/github/callback` with auth code
3. Controller exchanges code for permanent access token via GitHub's token endpoint
4. Fetches GitHub username via `GET /user` API
5. Upserts `Integration` entity with `provider: 'github'`, `access_token`, scopes, `provider_email` (from GitHub user API), `metadata: { github_username: '...' }`
6. Status set to `active`

### Token Management

GitHub OAuth Apps issue **permanent access tokens** with no expiry and no refresh tokens. `GitHubTokenManager::getValidToken()` simply retrieves the stored access token from the Integration entity. If the token is revoked (detected by a 401 response from the GitHub API), the Integration status is set to `revoked` and the user is prompted to re-authorize.

This is simpler than `GoogleTokenManager` (no refresh logic), but follows the same interface so callers don't need to know the difference.

### Configuration

- `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` in `.env`
- Registered as a GitHub OAuth App (not a GitHub App; permanent tokens are sufficient for this use case)

### Callback Route Gotcha

The `/github/callback` route uses `->allowAll()` (required for OAuth redirects). Per CLAUDE.md, `allowAll()` routes receive `AnonymousUser` for the `$account` parameter. The callback must resolve the authenticated user from the session via `AuthenticatedAccountSessionResolver` (same pattern as `GoogleOAuthController::resolveAccount()`).

### Pattern Reference

Follows `GoogleOAuthController` structure. Same Integration entity, same upsert pattern, same session resolution. Token management is simpler (no refresh).

## Component 2: Internal API Endpoints

### File

- `src/Controller/InternalGithubController.php`

### Authentication

HMAC Bearer token via `InternalApiTokenGenerator` (same as `InternalGoogleController`).

### Endpoints

| Method | Route | Purpose |
|--------|-------|---------|
| `GET` | `/api/internal/github/notifications` | List unread notifications |
| `GET` | `/api/internal/github/issues` | List issues (params: `repo`, `state`, `labels`) |
| `GET` | `/api/internal/github/issue/{owner}/{repo}/{number}` | Read single issue with comments |
| `GET` | `/api/internal/github/pulls` | List PRs (params: `repo`, `state`) |
| `GET` | `/api/internal/github/pull/{owner}/{repo}/{number}` | Read single PR with review status |
| `POST` | `/api/internal/github/issue/{owner}/{repo}` | Create an issue |
| `POST` | `/api/internal/github/comment/{owner}/{repo}/{number}` | Add comment to issue/PR |

### HTTP Client

All endpoints use `file_get_contents` with `stream_context_create` (pre-push hook blocks `curl_exec`). Token obtained via `GitHubTokenManager::getValidToken()`.

### Repo Filtering

If the user's Integration metadata contains `watched_repos` (array of `owner/repo` strings), list endpoints filter to that set. Empty/missing means all accessible repos.

## Component 3: Agent Chat Tools

### Files

New files in `agent/tools/`:

| Tool | Purpose | Endpoint |
|------|---------|----------|
| `github_notifications.py` | List unread notifications | `GET /api/internal/github/notifications` |
| `github_list_issues.py` | List issues for a repo | `GET /api/internal/github/issues` |
| `github_read_issue.py` | Read issue + comments | `GET /api/internal/github/issue/{owner}/{repo}/{number}` |
| `github_list_pulls.py` | List PRs for a repo | `GET /api/internal/github/pulls` |
| `github_read_pull.py` | Read PR + review status | `GET /api/internal/github/pull/{owner}/{repo}/{number}` |
| `github_create_issue.py` | Create a new issue | `POST /api/internal/github/issue/{owner}/{repo}` |
| `github_add_comment.py` | Comment on issue/PR | `POST /api/internal/github/comment/{owner}/{repo}/{number}` |

### Discovery

All tools are picked up automatically by `discover_tools()` in `agent/main.py`. No static registration needed.

### Safety

`github_create_issue` and `github_add_comment` are write operations. Tool descriptions include "Requires user confirmation" to reinforce Claudriel's safety principle (no external actions without approval). The agent's system prompt already enforces this.

### Pattern Reference

Each tool follows the existing pattern: exports `TOOL_DEF` (name, description, input_schema dict) and an `execute(api, args)` function using the shared `api` helper.

## Component 4: Event Ingestion + Day Brief

### Files

- `src/Ingestion/GitHubNotificationNormalizer.php`
- `src/Command/GitHubSyncCommand.php`

### Polling Flow

1. `claudriel:github:sync` runs on cron every 5 minutes
2. Fetches unread notifications via `GitHubTokenManager` -> GitHub Notifications API
3. For each notification, `GitHubNotificationNormalizer::normalize()` produces an `Envelope`
4. `EventHandler::handle()` processes each envelope: deduplicates by content hash, creates `McEvent` with category from `EventCategorizer`

### Event Categories

New `github` source branch in `EventCategorizer::categorize()`. The current method handles `gmail` and `google-calendar` sources with a fallback to `'notification'`. Add a new `if ($source === 'github')` branch:

| GitHub notification reason | McEvent category |
|---------------------------|-----------------|
| `mention` | `github_mention` |
| `review_requested` | `github_review_request` |
| `assign` | `github_assignment` |
| `ci_activity` | `github_ci` |
| `state_change` | `github_activity` |
| Everything else | `github_activity` |

The normalizer sets `source: 'github'` and `type: <notification_reason>` on the Envelope payload, which `EventCategorizer` uses for routing.

### Day Brief Wiring

`DayBriefAssembler` gains a new `github` section in its output. GitHub events appear **exclusively** in the `github` section, not in the existing `notifications` bucket, to avoid double-counting:

```php
'github' => [
    'mentions'        => /* McEvents with category github_mention */,
    'review_requests' => /* category github_review_request */,
    'ci_failures'     => /* category github_ci where payload indicates failure */,
    'activity'        => /* github_activity + github_assignment, capped at 10 */,
],
```

Plus a `github` entry in `counts`. When no GitHub events exist, the `github` key is omitted entirely (no empty section noise).

### Person Linking

GitHub notifications identify users by username, not email. The normalizer populates `from_name` with the GitHub username and sets `from_email` to `null`. This means `EventHandler::upsertPerson()` (which keys on email) will not auto-create Person records from GitHub events.

Instead, person linking works via an explicit `github_username` field in Person's `_data` JSON blob. When normalizing, if a `Person` entity exists with a matching `github_username`, the McEvent's `person_id` is set to link them. This field is set manually or during GitHub OAuth (the authenticated user's own Person record gets their `github_username` populated automatically).

This avoids expensive GitHub Users API calls to resolve usernames to emails.

## Service Provider Wiring

### New registrations in `ClaudrielServiceProvider`

- `GitHubTokenManager` singleton (depends on `EntityTypeManager`, `DatabaseInterface`)
- `InternalGithubController` singleton (depends on `GitHubTokenManager`, `InternalApiTokenGenerator`)
- `GitHubOAuthController` singleton (depends on `EntityTypeManager`, `DatabaseInterface`)

### New routes

| Method | Path | Controller | Access |
|--------|------|------------|--------|
| `GET` | `/github/connect` | `GitHubOAuthController::connect` | Authenticated |
| `GET` | `/github/callback` | `GitHubOAuthController::callback` | Public (OAuth redirect) |
| `GET` | `/api/internal/github/notifications` | `InternalGithubController` | HMAC |
| `GET` | `/api/internal/github/issues` | `InternalGithubController` | HMAC |
| `GET` | `/api/internal/github/issue/{owner}/{repo}/{number}` | `InternalGithubController` | HMAC |
| `GET` | `/api/internal/github/pulls` | `InternalGithubController` | HMAC |
| `GET` | `/api/internal/github/pull/{owner}/{repo}/{number}` | `InternalGithubController` | HMAC |
| `POST` | `/api/internal/github/issue/{owner}/{repo}` | `InternalGithubController` | HMAC |
| `POST` | `/api/internal/github/comment/{owner}/{repo}/{number}` | `InternalGithubController` | HMAC |

### New command

`GitHubSyncCommand` registered via `commands()` method following existing pattern.

## Configuration

### Environment variables

| Variable | Purpose |
|----------|---------|
| `GITHUB_CLIENT_ID` | OAuth App client ID |
| `GITHUB_CLIENT_SECRET` | OAuth App client secret |

### Ansible vault (production/staging)

- `vault_claudriel_github_client_id`
- `vault_claudriel_github_client_secret`

Separate OAuth App registrations for staging (`claudriel.northcloud.one`) and production (`claudriel.ai`) callback URLs.

## Test Plan

### OAuth

- `testConnectRedirectsToGitHub` — verify redirect URL, scopes, state parameter
- `testCallbackExchangesCodeForToken` — mock HTTP, verify Integration entity created
- `testCallbackUpdatesExistingIntegration` — re-auth updates tokens, not duplicates
- `testCallbackWithInvalidStateRejects` — CSRF protection

### Token management

- `testGetValidTokenReturnsStoredToken` — active integration returns access token
- `testGetValidTokenThrowsWhenNoIntegration` — no GitHub integration configured
- `testGetValidTokenSetsRevokedOnUnauthorized` — 401 from GitHub API marks integration as revoked
- `testGetValidTokenThrowsWhenRevoked` — revoked integration requires re-authorization

### Internal API

- `testListNotificationsRequiresHmac` — 401 without token
- `testListNotificationsReturnsFormatted` — mock GitHub API response
- `testListIssuesFiltersbyRepo` — repo parameter applied
- `testCreateIssueForwardsPayload` — verify POST to GitHub API
- `testRepoFilteringRespectsWatchedRepos` — Integration metadata filtering

### Agent tools

- `testToolDefSchema` — each tool has valid TOOL_DEF
- `testExecutorCallsCorrectEndpoint` — mock API, verify URL and params

### Event ingestion

- `testNormalizerProducesEnvelope` — GitHub notification -> Envelope fields
- `testDeduplicationByContentHash` — same notification twice, one McEvent
- `testCategoryMapping` — each notification reason maps to correct category
- `testPersonLinking` — notification from known GitHub user links to Person entity

### Day brief

- `testBriefIncludesGitHubSection` — with GitHub McEvents, brief output has github key
- `testBriefCountsIncludeGitHub` — counts object has github entry
- `testBriefWithoutGitHubEventsOmitsSection` — no noise when no events
- `testGitHubEventsNotInNotificationsBucket` — no double-counting

### Error handling

- `testGitHubApiRateLimitHandled` — 403 with `X-RateLimit-Remaining: 0` logged, sync retries next cycle
- `testSyncCommandIdempotent` — two overlapping syncs don't create duplicate McEvents (content hash dedup)
- `testSyncCommandWithRevokedTokenSkips` — logs warning, does not crash

## Implementation Order

Service provider wiring (singletons, routes, command registration) happens incrementally alongside each step, not as a separate final step. Each component is testable as soon as it's built.

1. `GitHubTokenManager` + `GitHubOAuthController` + service provider wiring + routes + tests
2. `InternalGithubController` + routes + tests
3. Agent tools (`agent/tools/github_*.py`) + tests
4. `EventCategorizer` GitHub branch + `GitHubNotificationNormalizer` + `GitHubSyncCommand` + tests
5. `DayBriefAssembler` GitHub section + tests
6. Deploy config (env vars, Ansible vault, cron setup)

### Cron Configuration

`claudriel:github:sync` is registered as a system crontab entry managed by Ansible, following the same pattern as any future scheduled commands. Add to the Ansible playbook:

```
*/5 * * * * deployer cd /home/deployer/claudriel-prod/current && php bin/console claudriel:github:sync >> /home/deployer/claudriel-prod/shared/logs/github-sync.log 2>&1
```

### IssueOrchestrator Compatibility Note

The existing IssueOrchestrator spec uses `Waaseyaa\GitHub\GitHubClient` (a Waaseyaa package with PAT auth). This integration uses `GitHubTokenManager` with OAuth tokens. Both coexist intentionally: `GitHubClient` is a reusable Waaseyaa package for any app, while `GitHubTokenManager` provides Claudriel-specific per-user OAuth tokens. When the IssueOrchestrator is implemented, it should use `GitHubTokenManager` to get the token and pass it to `GitHubClient`, replacing the PAT.

## Files Created

```
src/Controller/GitHubOAuthController.php
src/Controller/InternalGithubController.php
src/Support/GitHubTokenManager.php
src/Ingestion/GitHubNotificationNormalizer.php
src/Command/GitHubSyncCommand.php
agent/tools/github_notifications.py
agent/tools/github_list_issues.py
agent/tools/github_read_issue.py
agent/tools/github_list_pulls.py
agent/tools/github_read_pull.py
agent/tools/github_create_issue.py
agent/tools/github_add_comment.py
```

## Files Modified

```
src/Provider/ClaudrielServiceProvider.php  (new singletons, routes, command)
src/Ingestion/EventCategorizer.php         (new GitHub categories)
src/Domain/DayBrief/Assembler/DayBriefAssembler.php  (new github section)
```
