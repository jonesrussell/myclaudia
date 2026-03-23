# Cross-Repo Sprint Plan: Security + Stabilization + Agent Enhancements

**Date:** 2026-03-23
**Repos:** waaseyaa/framework, jonesrussell/claudriel
**Scope:** Waaseyaa Security Hardening (P0) + Alpha Stabilization + Claudriel v2.1

## Pre-Sprint: Close Already-Resolved Issues

Codebase exploration revealed two issues are already fixed:

| Issue | Status | Evidence |
|---|---|---|
| Waaseyaa #546 (DevAdminAccount guard) | Done | `DevAdminAccount.php:22-27` already throws LogicException if SAPI is not cli-server/cli |
| Waaseyaa #541 (PHP version constraint) | Done | All 46 package composer.json files have `"php": ">=8.4"` |

**Action:** Close both with verification comments before sprint starts.

Potentially resolved (needs verification):
| Issue | Status | Evidence |
|---|---|---|
| Waaseyaa #603 (GH Actions Node.js 24) | Likely done | All workflows use `node-version: '22'` and `setup-node@v4`. Issue asks for Node 24 compat; v4 actions support Node 24. Verify no deprecated action versions remain. |

---

## Phase 1: Waaseyaa Security Hardening (5 remaining issues)

All fixes are surgical, single-file changes in `packages/access/` and `packages/user/`. No new dependencies. No API changes. Can be done as one PR or individually.

### 1.1 — #542 XSS in AuthorizationMiddleware (HIGH)

**File:** `packages/access/src/Middleware/AuthorizationMiddleware.php:99-129`
**Problem:** `$detail` and `$title` embedded in HTML without escaping in `renderHtmlError()`.
**Fix:** Wrap both in `htmlspecialchars($var, ENT_QUOTES, 'UTF-8')`.
**Size:** ~5 lines changed.
**Tests:** Add test that injects `<script>` in detail, assert output is escaped.
**Risk:** None. Pure output encoding.

### 1.2 — #543 Session Cookie Security Flags (HIGH)

**File:** `packages/user/src/Middleware/SessionMiddleware.php:31`
**Problem:** `session_start()` called with no cookie configuration.
**Fix:** Add `ini_set` calls before `session_start()`:
- `session.cookie_httponly = 1`
- `session.cookie_secure = 1`
- `session.cookie_samesite = Strict`
- `session.use_strict_mode = 1`
**Size:** ~6 lines added.
**Tests:** Unit test asserting session cookie params after middleware runs.
**Risk:** `cookie_secure = 1` requires HTTPS. Local dev uses `php -S` over HTTP. Consider making `cookie_secure` conditional on HTTPS presence, or document that local dev must use HTTP-only cookies.
**Gotcha:** Existing sessions will be invalidated when `use_strict_mode` is enabled. This is expected and desirable.

### 1.3 — #544 Open Redirect in Login (MEDIUM)

**File:** `packages/access/src/Middleware/AuthorizationMiddleware.php:59`
**Problem:** `$request->getPathInfo()` used in redirect URL without validation. Attacker can craft `//evil.com` path.
**Fix:** Validate path starts with `/` and does NOT start with `//`. Fall back to `/` if invalid.
**Size:** ~5 lines added.
**Tests:** Test with `//evil.com`, `http://evil.com`, `/valid/path`, `/`.
**Risk:** None. Defensive validation on existing behavior.

### 1.4 — #545 Session Fixation Prevention (MEDIUM)

**File:** `packages/user/src/Middleware/SessionMiddleware.php`
**Problem:** No `session_regenerate_id(true)` on authentication state change. One call exists in `ControllerDispatcher:273` but not in session middleware.
**Fix:** Add `session_regenerate_id(true)` in SessionMiddleware after detecting authenticated user transition (anonymous to authenticated).
**Size:** ~8 lines (need to track previous auth state).
**Tests:** Test that session ID changes after authentication.
**Risk:** Must detect auth transition correctly. Track `$_SESSION['_authenticated_uid']` before/after to detect change.
**Dependency:** Should be done after #543 (cookie flags) since both modify SessionMiddleware.

### 1.5 — #547 Cache-Control on 401/403 (MEDIUM)

**File:** `packages/access/src/Middleware/AuthorizationMiddleware.php:128,52,73,88`
**Problem:** Error responses don't set `Cache-Control: no-store`.
**Fix:** Add `'Cache-Control' => 'no-store'` to all Response constructors in error paths.
**Size:** ~4 lines changed (add header to each Response).
**Tests:** Assert Cache-Control header present on 401 and 403 responses.
**Risk:** None. Additive header.

### 1.6 — #599 JSON_THROW_ON_ERROR Symmetry (MEDIUM)

**Files:**
- `packages/ai-vector/src/OllamaEmbeddingProvider.php:76`
- `packages/ai-vector/src/OpenAiEmbeddingProvider.php:85`
- `packages/telescope/src/TelescopeEntry.php:48`

**Problem:** `json_decode()` without `JSON_THROW_ON_ERROR` paired with `json_encode(..., JSON_THROW_ON_ERROR)`.
**Fix:** Add `JSON_THROW_ON_ERROR` flag to all three `json_decode()` calls.
**Size:** 3 one-line changes.
**Tests:** Existing tests should still pass. Consider adding test with malformed JSON input.
**Risk:** Code that previously silently returned null on bad JSON will now throw. This is the correct behavior, but verify no callers catch null returns.

### Phase 1 Sequencing

```
#542 (XSS) ──────────────────┐
#543 (cookie flags) ──┐      │
                      ├──→ #545 (session fixation, depends on #543)
#544 (open redirect) ─┤      │
#547 (cache-control) ─┤      │
#599 (json_decode) ───┘      │
                              └──→ PR: "Security Hardening P0"
```

**Recommended:** Single PR with all 5 fixes (6 including #599). All touch `packages/access/` and `packages/user/` with no cross-dependencies except #545 depending on #543.

---

## Phase 2: Waaseyaa Alpha Stabilization (selective)

This milestone has 10 open issues. For sprint focus, split into two tiers:

### Tier A: Quick wins (do in sprint)

#### 2.1 — #614 Skeleton missing phpunit.xml.dist

**Directory:** `skeleton/`
**Fix:** Add `phpunit.xml.dist` with standard PHPUnit configuration targeting `tests/` directory, using waaseyaa/testing bootstrap.
**Size:** ~30 lines (new file).
**Pattern:** Copy from any existing package's phpunit.xml.dist and adapt.

#### 2.2 — #615 Skeleton missing autoload-dev and require-dev

**File:** `skeleton/composer.json`
**Fix:** Add `autoload-dev` section (`Tests\\` namespace) and `require-dev` section (`waaseyaa/testing`, `phpunit/phpunit`).
**Size:** ~10 lines added to composer.json.

#### 2.3 — #616 Document ServiceProvider DI methods

**Fix:** Add documentation to skeleton's CLAUDE.md covering `singleton()`, `resolve()`, `bind()`, `EntityRepositoryInterface` naming conventions, and key framework namespaces.
**Size:** ~50-80 lines of documentation.

#### 2.4 — #540 Classify orphan packages

**Problem:** 6 packages not assigned to any layer in CLAUDE.md: `admin`, `admin-surface`, `auth`, `billing`, `deployer`, `github`.
**Fix:** Assign each to the correct layer in CLAUDE.md's architecture table. Based on exploration:
- `admin`, `admin-surface` → Layer 6 (Interfaces)
- `auth` → Layer 1 (Foundation)
- `billing` → Layer 4 (Application Services) or Layer 5 (Integration)
- `deployer` → Layer 7 (Tooling/DevOps)
- `github` → Layer 5 (Integration)
**Size:** Documentation update only.

### Tier B: Substantial work (design in sprint, implement next)

#### 2.5 — #535-539 Entity types (Workspace, Project, Repo, Milestone, Junctions)

**Current state:** None exist in the framework. Claudriel has its own Workspace entity.
**Scope:** 5 new entity classes extending ContentEntityBase + junction entities.
**Complexity:** Medium-high. Need to decide:
- Which package do these live in? New `workspace` package? Or in `entity`?
- How do junction entities work? No existing pattern in the framework.
- What fields does each entity need at the framework level vs. app level?
- How does Claudriel's existing Workspace entity relate to the framework one?

**Recommendation:** Design only in this sprint. Create a spec document covering entity fields, package placement, junction pattern, and migration path for Claudriel's existing Workspace. Implement in next sprint.

---

## Phase 3: Claudriel v2.1 Core Agent Enhancements

10 open issues. Exploration revealed significant partial completion. Split into tiers:

### Tier A: Nearly done (finish in sprint)

#### 3.1 — #407 Context guardrails + compaction policy

**Current state:** Substantially implemented in `ChatStreamController.php:595-650`.
- 20-message cap, 500-char truncation, last 4 messages preserved in full
- Tool results capped at 2000 chars, Gmail body at 500 chars
**Remaining work:**
- Make limits configurable per workspace (ties to #406)
- Add tests for edge cases (empty history, all-tool-result conversations)
- Document the compaction strategy
**Size:** Small. Mostly testing and docs.

#### 3.2 — #409 Long-context model usage docs

**Current state:** Partial docs exist in roadmap specs and implementation plans.
**Remaining work:** Consolidate into a single guide covering model selection criteria, cost implications, continuation workflow, and context window management.
**Size:** Documentation only. ~100-150 lines.

### Tier B: Backend done, frontend needed

#### 3.3 — #310 Continue button UI + turn limit settings

**Current state:** Backend FULLY implemented.
- `NativeAgentClient` emits `needs_continuation` events
- `ChatSession` tracks `turns_consumed`, `continued_count`
- Default turn limits per task type hardcoded
**Remaining work:**
- Frontend: handle `needs_continuation` SSE event in chat UI
- Frontend: render "Continue?" button
- Account settings page: turn limits per task type, daily ceiling
- Turn usage display in chat
**Dependency:** Requires a chat page in the Nuxt admin SPA. This may not exist yet.
**Size:** Medium. Frontend component work.

### Tier C: New implementation needed

#### 3.4 — #406 Workspace-level model selection

**Current state:** Model selection is env-var hardcoded: `$_ENV['ANTHROPIC_MODEL'] ?? 'claude-sonnet-4-6'`
**Implementation:**
- Add `model` field to Workspace entity + fieldDefinitions
- Update GraphQL schema (workspace type gets `model` field)
- Wire `ChatStreamController` to read workspace model and pass to agent client
- Admin UI: model selector on workspace settings
**Size:** Medium. Entity field + controller wiring + frontend.
**Note:** `SqlSchemaHandler::ensureTable()` won't add the column. Need manual ALTER TABLE or table recreation on existing installs.

#### 3.5 — #408 Token telemetry + fallback behavior

**Current state:** No telemetry exists. `NativeAgentClient` receives API responses with `usage` field but discards it.
**Implementation:**
- Parse `usage` from Anthropic API response in `NativeAgentClient`
- Persist per-turn token counts on ChatSession or new TokenUsage entity
- Expose via GraphQL for admin dashboard
- Add model fallback chain (if primary model rate-limited, fall back to secondary)
**Size:** Medium-large. New service + entity field/entity + API integration.
**Dependency:** Benefits from #406 (workspace model selection) being done first.

### Tier D: Frontend polish (independent track)

#### 3.6 — #328-332 Frontend editorial polish

**Current state:** Detailed design plan exists at `docs/superpowers/plans/2026-03-19-frontend-editorial-polish.md`.
**Scope:** 5 issues covering audit views, governance views, AI views, Telescope components, schema form widgets.
**Approach:** Follow the existing plan. Design tokens first (CSS variables), then cascade to components.
**Size:** Large but well-defined. Pure frontend CSS/template work.
**Independence:** Can be done in parallel with all other work. No backend dependencies.

### Phase 3 Sequencing

```
#407 (guardrails, nearly done) ─────────────────────────┐
#409 (docs) ────────────────────────────────────────────┤
                                                         ├──→ v2.1 complete
#406 (model selection) ──→ #408 (telemetry, depends on 406) ──┤
#310 (continue button, frontend) ───────────────────────┤
#328-332 (frontend polish, parallel track) ─────────────┘
```

---

## Sprint Sequence (Full)

```
Pre-Sprint
  Close #546 (DevAdminAccount, already done)
  Close #541 (PHP version, already done)
  Verify #603 (GH Actions, likely done)

Phase 1: Waaseyaa Security Hardening ──────────────────── ~1 session
  #542 XSS (5 lines)
  #543 Cookie flags (6 lines)
  #544 Open redirect (5 lines)
  #545 Session fixation (8 lines, after #543)
  #547 Cache-Control (4 lines)
  #599 json_decode (3 lines)
  → Single PR, all fixes

Phase 2A: Waaseyaa Alpha Quick Wins ──────────────────── ~1 session
  #614 phpunit.xml.dist (new file)
  #615 autoload-dev (composer.json edit)
  #616 DI documentation (CLAUDE.md update)
  #540 Classify orphan packages (CLAUDE.md update)
  → Single PR

Phase 2B: Entity Type Design (planning only) ─────────── ~1 session
  #535-539 Design spec for Workspace/Project/Repo/Milestone/Junctions
  → Spec document, no code

Phase 3A: Claudriel Agent Quick Wins ─────────────────── ~1 session
  #407 Context guardrails (tests + docs, mostly done)
  #409 Long-context docs (documentation)
  → PR per issue or combined

Phase 3B: Claudriel Agent Infrastructure ─────────────── ~2 sessions
  #406 Workspace model selection (entity + controller + frontend)
  #408 Token telemetry (new service + persistence)
  #310 Continue button (frontend, backend done)
  → PR per issue

Phase 3C: Claudriel Frontend Polish ──────────────────── ~2 sessions (parallel)
  #328-332 Editorial design cascade
  → Can run in parallel with Phase 3B
```

---

## Decision Points Before Implementation

These questions need answers before code is written:

1. **Session cookie_secure (#543):** Should `cookie_secure` be conditional on HTTPS, or always on (breaking local HTTP dev)?
2. **Entity type package (#535-539):** Do Workspace/Project/Repo live in a new `workspace` package, in `entity`, or split across existing packages?
3. **Junction entity pattern (#539):** No precedent exists. What's the pattern? Separate entity type per junction, or generic junction entity with type field?
4. **Claudriel Workspace migration (#406/#535):** When Waaseyaa gets a framework Workspace entity, how does Claudriel's existing Workspace entity relate? Extend? Replace? Coexist?
5. **Token telemetry storage (#408):** New entity type, or additional fields on ChatSession?

---

## Success Criteria

Sprint is complete when:
- [ ] All Waaseyaa Security Hardening (P0) issues are closed
- [ ] Waaseyaa Alpha Stabilization quick wins (#540, #614-616) are closed
- [ ] Entity type design spec is written and committed
- [ ] Claudriel #407 and #409 are closed
- [ ] Claudriel #406, #408, #310 have PRs open or merged
- [ ] Zero open security vulnerabilities in production
