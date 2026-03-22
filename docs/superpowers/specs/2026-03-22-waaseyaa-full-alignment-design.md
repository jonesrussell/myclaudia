# Waaseyaa Full Alignment — Design Spec

**Date:** 2026-03-22
**Status:** Approved
**Scope:** Adopt all unused Waaseyaa framework packages into Claudriel, fixing the framework upstream where needed.

## Motivation

Claudriel uses 16 of Waaseyaa's 48 packages. An audit revealed 11 packages that would reduce custom code, add new capabilities, and keep Claudriel aligned as the framework's reference application. Framework bugs and gaps discovered during adoption are fixed upstream in Waaseyaa, not worked around in Claudriel.

## Approach: Dependency-Ordered Waves

Three milestones, each independently deployable, ordered by actual package dependency chains.

```
Wave 1: Infrastructure (no cross-deps)
  telescope, cache, state, queue, mail

Wave 2: Domain Modeling (needs Wave 1 foundation)
  workflows, taxonomy, relationship

Wave 3: AI & External (needs Waves 1+2)
  ai-agent, ai-vector, mcp
```

## Milestone: v3.0 — Waaseyaa Infrastructure Alignment

Packages with zero cross-dependencies. Telescope first for observability during subsequent waves.

### Issue 1: Integrate `waaseyaa/telescope` for request/query/event observability

**Package:** telescope
**What:** Register `TelescopeServiceProvider`. Wire `QueryRecorder`, `CacheRecorder`, `EventRecorder`, `RequestRecorder`. Store entries in `SqliteTelescopeStore`.
**Acceptance criteria:**
- Every HTTP request logs a telescope entry
- Entity queries are recorded with timing
- Event dispatches are recorded
- CLI command to query telescope entries
**Framework work:** Likely minimal. Package appears mature.

### Issue 2: Integrate `waaseyaa/cache` with tag-based invalidation

**Package:** cache
**What:** Register `CacheFactory` with `DatabaseBackend`. Wire `EntityCacheInvalidator` to auto-invalidate on entity save. Use cache in `DayBriefAssembler` and entity lookups.
**Acceptance criteria:**
- Brief assembly uses cache (measurable speedup on repeated calls)
- Entity save invalidates relevant cache tags
- Cache hit/miss visible in telescope
**Framework work:** Verify `EntityCacheInvalidator` works with Claudriel's entity registration pattern (dual-instance `SqlEntityStorage` in `ClaudrielServiceProvider::commands()`).

### Issue 3: Integrate `waaseyaa/state` for key-value persistence

**Package:** state
**What:** Register `SqlState`. Replace hand-rolled state tracking (Gmail sync cursors, any session-level key-value storage) with `StateInterface`.
**Acceptance criteria:**
- Gmail sync cursor stored via `StateInterface::set()`/`get()`
- No custom key-value SQL outside of `SqlState`
**Framework work:** Likely none. Simple package.

### Issue 4: Integrate `waaseyaa/queue` for background job processing

**Package:** queue
**What:** Register `MessageBusQueue` with Symfony Messenger transport. Move commitment extraction pipeline to async jobs. Foundation for Wave 3 vector embedding jobs.
**Acceptance criteria:**
- `CommitmentExtractionStep` runs as a queued job
- Failed jobs captured in `FailedJobRepository`
- Queue worker CLI command functional
**Framework work:** Cross-reference Waaseyaa v1.9 (Production Queue Backend, milestone #30). Transport configuration may need framework work.

### Issue 5: Integrate `waaseyaa/mail` for admin notifications

**Package:** mail
**What:** Register `MailServiceProvider`. Replace `file_get_contents` HTTP calls for admin notification emails with `Mailer::send()`. Configure transport (local dev: `ArrayTransport`, production: SMTP or API transport).
**Acceptance criteria:**
- Admin registration notification (#471) uses `Mailer`
- No `file_get_contents` for email sending
- Transport swappable via config
**Framework work:** Verify `MailServiceProvider` registers cleanly alongside existing providers.

## Milestone: v3.1 — Waaseyaa Domain Modeling

Workflows is the critical path. Taxonomy and relationship can proceed in parallel once workflows lands.

### Issue 1: Make workflow state machine configurable (Waaseyaa)

**Repo:** waaseyaa/waaseyaa
**Package:** workflows
**What:** `EditorialWorkflowStateMachine` hardcodes draft/review/published/archived. Refactor to support configurable state sets and transitions. The editorial workflow becomes one instance of a generic `WorkflowStateMachine`, not the only option.
**Acceptance criteria:**
- `WorkflowStateMachine` accepts custom states and transitions at construction
- `EditorialWorkflowStateMachine` becomes a factory or preset, not a base class
- Existing editorial workflow tests still pass
- New test: custom state machine with non-editorial states
**Waaseyaa milestone:** Validation & Lifecycle Hooks (#40)

### Issue 2: Integrate `waaseyaa/workflows` for commitment lifecycle

**Package:** workflows
**Depends on:** v3.1 Issue 1
**What:** Define commitment workflow with states and transitions:

```
States: pending, active, completed, archived

Transitions:
  activate:  pending → active    (guard: confidence ≥ 0.7)
  complete:  active → completed  (no guard)
  defer:     active → pending    (no guard)
  reopen:    completed → active  (no guard)
  archive:   any → archived      (no guard)
  restore:   archived → pending  (no guard)
```

Replace string-field `status` and PHP conditionals in `DriftDetector`, `CommitmentHandler`. Wire audit trail for all state changes.

**Acceptance criteria:**
- Commitment entity has `workflow_state` field managed by `ContentModerator`
- `CommitmentHandler` confidence gate is a workflow transition guard
- `DriftDetector` queries `workflow_state=active` + `workflow_last_transition.timestamp < 48h`
- All state changes produce audit entries
- Invalid transitions throw (e.g., pending→completed is not allowed)
- Migration path: existing commitments with string `status` normalized to `workflow_state`

**Framework work:** Depends on v3.1 Issue 1.

### Issue 3: Integrate `waaseyaa/taxonomy` for entity categorization

**Package:** taxonomy
**What:** Register `TaxonomyServiceProvider`. Create vocabularies: "topic", "priority", "relationship-type". Apply terms to McEvent, Commitment, Person entities.
**Acceptance criteria:**
- Vocabularies and terms are entity types with CRUD via GraphQL
- Entities can be tagged with terms
- Filter entities by term via `findBy()` or taxonomy query
**Framework work:** Likely minimal. Vocabulary + Term are simple entity types.

### Issue 4: Integrate `waaseyaa/relationship` for entity graph edges

**Package:** relationship
**What:** Register `RelationshipServiceProvider`. Create explicit directed edges: Person↔Commitment, Person↔McEvent, Commitment↔McEvent. Use `RelationshipTraversalService` for "show me everything connected to this person."
**Acceptance criteria:**
- Relationships are first-class entities with CRUD via GraphQL
- "Related entities" query works via `RelationshipTraversalService`
- Existing implicit associations (field-value matching) replaced with explicit edges
- Access policies applied to relationship visibility
**Framework work:** Verify `RelationshipSchemaManager` creates tables via `ensureTable` (Claudriel gotcha: `SqlSchemaHandler::ensureTable()` only creates, never alters — #353).

## Milestone: v3.2 — Waaseyaa AI & External Integration

Three Waaseyaa framework issues gate the agent replacement. MCP server is the capstone.

### Issue 1: Add streaming support to `waaseyaa/ai-agent` (Waaseyaa)

**Repo:** waaseyaa/waaseyaa
**Package:** ai-agent
**What:** Current `AgentExecutor` is synchronous. Add `StreamingAgentInterface` or streaming mode to `AgentExecutor` that yields chunks for SSE delivery.
**Acceptance criteria:**
- Agent can stream partial results
- Audit logging still captures the complete result
- Compatible with `StreamedResponse` pattern
**Waaseyaa milestone:** Alpha Release Stabilization (#33) or new

### Issue 2: Add Anthropic client integration to `waaseyaa/ai-agent` (Waaseyaa)

**Repo:** waaseyaa/waaseyaa
**Package:** ai-agent
**What:** Package has no LLM client. Add `AnthropicProvider` supporting multi-turn conversations, tool use, and prompt caching (`cache_control: {"type": "ephemeral"}`).
**Acceptance criteria:**
- `AnthropicProvider` sends messages to Claude API
- Multi-turn conversation with tool calls works
- Prompt caching configured (system prompt + last tool definition)
- Rate limit awareness (Tier 1: 30K input tokens/min)
**Waaseyaa milestone:** Alpha Release Stabilization (#33) or new

### Issue 3: Add custom tool registration to `waaseyaa/ai-agent` (Waaseyaa)

**Repo:** waaseyaa/waaseyaa
**Package:** ai-agent
**What:** Current `McpToolExecutor` only generates entity CRUD tools. Add `ToolRegistryInterface` for registering custom tools (Gmail, Calendar, GitHub) alongside auto-generated entity tools.
**Acceptance criteria:**
- Custom tools registered via `ToolRegistryInterface::register()`
- Auto-generated entity tools and custom tools coexist
- Tool discovery returns both types
**Waaseyaa milestone:** Alpha Release Stabilization (#33) or new

### Issue 4: Replace Docker agent subprocess with native `waaseyaa/ai-agent`

**Package:** ai-agent
**Depends on:** v3.2 Issues 1-3
**What:** Implement `ClaudrielAgent` using `AgentInterface`. Register Gmail, Calendar, GitHub as custom tools. Wire `ChatStreamController` to use `AgentExecutor` with streaming. Remove Docker subprocess, `SubprocessChatClient`, HMAC internal API auth, and `agent/` Python directory.
**Acceptance criteria:**
- Chat works via native PHP agent (no Docker)
- SSE streaming to frontend preserved
- Gmail/Calendar/GitHub tools functional
- Audit log captures all agent actions
- `agent/` directory removed
- `SubprocessChatClient` removed
- HMAC internal API routes removed
- Prompt caching active (verified via token telemetry)
**Framework work:** Depends on v3.2 Issues 1-3.

### Issue 5: Integrate `waaseyaa/ai-vector` for semantic search

**Package:** ai-vector
**Depends on:** v3.0 queue integration
**What:** Register `EntityEmbeddingListener` to auto-embed entities on save. Configure `SqliteEmbeddingStorage`. Expose similarity search via `SearchController`.
**Acceptance criteria:**
- Commitments, McEvents, Persons auto-embedded on save
- Embedding jobs processed via queue (async)
- Similarity search endpoint: "find entities similar to [text]"
- Cosine similarity with configurable threshold
**Framework work:** Verify `SqliteEmbeddingStorage` handles Claudriel's entity volume. May need embedding provider config (OpenAI vs Ollama).

### Issue 6: Expose Claudriel as MCP server via `waaseyaa/mcp`

**Package:** mcp
**Depends on:** ai-agent + ai-vector + workflows all integrated
**What:** Register `McpRouteProvider`. Expose entity CRUD via `EntityTools`, relationship queries via `TraversalTools`, workflow transitions via `EditorialTools`. Configure `BearerTokenAuth`.
**Acceptance criteria:**
- External Claude Code sessions can query Claudriel entities
- Relationship traversal available as MCP tools
- Workflow transitions available as MCP tools
- Semantic search available as MCP tool
- Bearer token authentication enforced
**Framework work:** This is the capstone. Most framework work should be done by this point.

## Cross-Repo Coordination

### Waaseyaa issues to create

| Issue | Waaseyaa milestone | Blocks |
|-------|-------------------|--------|
| Configurable workflow state machine | Validation & Lifecycle Hooks (#40) | v3.1 #1-2 |
| Streaming support in ai-agent | Alpha Release Stabilization (#33) | v3.2 #1, #4 |
| Anthropic provider in ai-agent | Alpha Release Stabilization (#33) | v3.2 #2, #4 |
| Custom tool registry in ai-agent | Alpha Release Stabilization (#33) | v3.2 #3, #4 |

**Label:** `claudriel-driven` on all Waaseyaa issues.
**Cross-reference:** Each Claudriel issue that depends on framework work includes "Blocked by waaseyaa/waaseyaa#NNN" in description.

### Release flow

1. Fix in Waaseyaa → run tests → commit, push
2. Tag: `git tag v0.1.0-alpha.N && git push origin v0.1.0-alpha.N`
3. Wait for "Split Monorepo" GitHub Action
4. In Claudriel: `composer update 'waaseyaa/*'`
5. Commit composer.json + composer.lock, continue

### Existing Claudriel milestones that benefit

- **v2.2** (Memory & Graph): relationship + ai-vector provide the foundation
- **v2.4** (Observability): telescope provides production observability
- **v2.9** (Scheduled Tasks): queue provides the job processing layer
- **v2.1 / Agent Reliability**: ai-agent replacement changes the architecture these build on
