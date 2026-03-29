# Agency-Agents API Layer — Design Spec

**Date:** 2026-03-29
**Status:** Draft
**Author:** Russell Jones + Claude

## Context

[msitarzewski/agency-agents](https://github.com/msitarzewski/agency-agents) is a collection of 100+ specialized AI agent prompts (markdown files) organized into divisions: Engineering, Design, Marketing, Sales, Product, Project Management, Support, Specialized, Game Dev, Academic. Each prompt defines a domain-expert persona with personality, process, and deliverables.

Claudriel is an AI personal operations system that already has operational tools (Gmail, Calendar, commitments, pipeline, daily briefs, workspaces). It lacks specialized reasoning: sales methodology, proposal writing, discovery coaching, feedback synthesis, executive summaries.

**Goal:** Fork agency-agents and add an API layer that indexes the prompts, executes specialists via Claude API, and exposes results through REST and MCP. Submit upstream as a PR.

## Architecture Overview

```
agency-agents/
  academic/          # existing prompt markdown files
  design/            # ...
  engineering/       # ...
  marketing/         # ...
  ...
  api/               # NEW: TypeScript/Node API layer
    src/
      index.ts           # entry point
      catalog/           # prompt indexer
      execution/         # agent runner (Claude API)
      rest/              # Express/Hono REST endpoints
      mcp/               # MCP server wrapper
      observability/     # structured logging + metrics
    package.json
    tsconfig.json
```

The existing prompt files remain untouched. The `api/` directory is additive.

## Component Design

### 1. Prompt Catalog

Scans the repository's markdown files at startup and builds a searchable in-memory index.

**Index entry shape:**

```typescript
interface AgentEntry {
  slug: string;          // stable identifier, derived from filename
  name: string;          // human-readable name from markdown heading
  division: string;      // folder name (metadata only, not a routing key)
  specialty: string;     // extracted from prompt metadata
  whenToUse: string;     // extracted from prompt metadata
  promptPath: string;    // relative path to markdown file
  promptContent: string; // raw markdown content
}
```

**Invariants:**
- Index is **read-only at runtime**. No mutation after startup.
- `slug` is the **stable identifier** (e.g., `sales-deal-strategist`). Derived from `{division}-{filename-stem}`.
- `division` and `specialty` are **metadata for filtering**, not routing keys. Folder reorganization does not break slugs.
- Index rebuilds on server restart (not hot-reloaded).

### 2. REST API

Framework: Hono (lightweight, fast, good TypeScript support).

All endpoints are prefixed with `/v1/`.

#### `GET /v1/agents`

List available specialists with optional filtering.

**Query params:**
- `division` — filter by division (e.g., `sales`, `product`)
- `q` — full-text search across name, specialty, whenToUse
- `limit`, `offset` — pagination

**Response:**
```json
{
  "version": "v1",
  "agents": [
    {
      "slug": "sales-deal-strategist",
      "name": "Deal Strategist",
      "division": "sales",
      "specialty": "MEDDPICC qualification, competitive positioning, win planning",
      "when_to_use": "Scoring deals, exposing pipeline risk, building win strategies"
    }
  ],
  "total": 112,
  "limit": 20,
  "offset": 0
}
```

#### `GET /v1/agents/:slug`

Get full agent metadata including raw prompt.

**Response:**
```json
{
  "version": "v1",
  "agent": {
    "slug": "sales-deal-strategist",
    "name": "Deal Strategist",
    "division": "sales",
    "specialty": "...",
    "when_to_use": "...",
    "prompt": "# Deal Strategist\n\nYou are..."
  }
}
```

#### `POST /v1/agents/:slug/execute`

Execute a specialist agent with a task. Returns an SSE stream.

**Request body:**
```json
{
  "task": "Qualify this prospect: Acme Corp, $50K ARR, no champion identified yet.",
  "context": {},
  "model_override": null
}
```

- `task` (required): The work for the specialist to do.
- `context` (optional): Arbitrary key-value context the specialist can reference.
- `model_override` (optional): Override the default model for this execution.

**Response:** SSE stream (see Output Contract below).

#### `GET /v1/health`

**Response:**
```json
{
  "status": "ok",
  "agents_indexed": 112,
  "uptime_seconds": 3600
}
```

### 3. Output Contract (SSE)

The stream is ephemeral. The summary is the contract.

#### Event types

**`token`** — incremental text output from the specialist:
```
event: token
data: "Analyzing the deal structure..."
```

**`log`** — metadata events (timing, progress):
```
event: log
data: {"type": "started", "agent": "sales-deal-strategist", "model": "claude-sonnet-4-6"}
```

**`error`** — execution failure:
```
event: error
data: {"code": "MODEL_ERROR", "message": "Rate limit exceeded", "details": {"retry_after_ms": 5000}}
```

**`summary`** — final structured result (always the last event on success):
```
event: summary
data: {
  "version": "v1",
  "agent": "sales-deal-strategist",
  "task": "Qualify this prospect...",
  "result": {
    "analysis": "...",
    "recommendations": ["..."],
    "score": 0.65
  },
  "metadata": {
    "model": "claude-sonnet-4-6",
    "tokens_in": 1834,
    "tokens_out": 2456,
    "duration_ms": 8123,
    "execution_id": "exec_abc123"
  }
}
```

**Key design decision:** The `result` field shape is **not** per-agent-typed in v1. The specialist's system prompt instructs it to produce structured output, but the API does not enforce a schema per agent type. This keeps the system flexible for 100+ prompts without requiring schema governance. Schema-per-agent is a v2 concern.

To get structured results, the execution engine appends an instruction to every specialist prompt:

> After completing the task, end your response with a JSON block wrapped in `<result>...</result>` tags containing your key findings in a structured format.

The execution engine extracts this block for the `summary.result` field.

### 4. Error Model

Unified across REST, SSE, and MCP.

```typescript
interface ApiError {
  code: string;
  message: string;
  details: Record<string, unknown>;
}
```

**Error codes:**

| Code | HTTP | Meaning |
|------|------|---------|
| `AGENT_NOT_FOUND` | 404 | No agent with the given slug |
| `INVALID_REQUEST` | 400 | Malformed request body |
| `MODEL_ERROR` | 502 | Claude API returned an error |
| `RATE_LIMITED` | 429 | Too many requests |
| `EXECUTION_TIMEOUT` | 504 | Agent execution exceeded time limit |
| `INTERNAL_ERROR` | 500 | Unexpected server error |

REST returns these in an `{"error": {...}}` envelope.
SSE emits them as `error` events.
MCP tools return them in a consistent shape with `isError: true`.

### 5. Model Selection and Execution Policy

Centrally controlled, not per-call (except optional override).

**Configuration (`api/config.yaml` or environment):**

```yaml
execution:
  default_model: "claude-sonnet-4-6"
  max_tokens: 4096
  temperature: 0.7
  timeout_ms: 60000

  # Per-agent overrides (optional)
  agent_overrides:
    sales-proposal-strategist:
      max_tokens: 8192
      temperature: 0.5
```

**Execution flow:**
1. Load agent prompt from catalog
2. Compose system prompt (agent markdown) + user message (task + context)
3. Call Claude API with model/limits from config (or override)
4. Stream tokens as SSE events
5. Extract `<result>` block from completion
6. Emit `summary` event

### 6. MCP Server

Thin wrapper over the REST layer. Returns summary only (no streaming).

**Tools:**

#### `list_agents`
```json
{
  "name": "list_agents",
  "description": "List available specialist agents with optional filtering",
  "input_schema": {
    "type": "object",
    "properties": {
      "division": { "type": "string", "description": "Filter by division" },
      "query": { "type": "string", "description": "Search agents by keyword" }
    }
  }
}
```

#### `execute_agent`
```json
{
  "name": "execute_agent",
  "description": "Execute a specialist agent with a task and return structured results",
  "input_schema": {
    "type": "object",
    "properties": {
      "agent": { "type": "string", "description": "Agent slug (e.g., sales-deal-strategist)" },
      "task": { "type": "string", "description": "The task for the specialist" },
      "context": { "type": "object", "description": "Optional context for the specialist" }
    },
    "required": ["agent", "task"]
  }
}
```

Returns the `summary` JSON directly. No streaming through MCP.

#### `get_agent_prompt`
```json
{
  "name": "get_agent_prompt",
  "description": "Get the raw prompt for an agent (for DIY callers)",
  "input_schema": {
    "type": "object",
    "properties": {
      "agent": { "type": "string", "description": "Agent slug" }
    },
    "required": ["agent"]
  }
}
```

### 7. Observability

Structured JSON logs per execution.

```json
{
  "level": "info",
  "event": "execution_complete",
  "agent": "sales-deal-strategist",
  "execution_id": "exec_abc123",
  "model": "claude-sonnet-4-6",
  "tokens_in": 1834,
  "tokens_out": 2456,
  "duration_ms": 8123,
  "status": "success",
  "timestamp": "2026-03-29T12:00:00Z"
}
```

Error logs include the error code and message. No sensitive data (task content, results) in logs by default. Optional `LOG_LEVEL=debug` includes task summaries.

Metric hook: exports a `MetricsEmitter` interface that can be wired to Prometheus, StatsD, or similar. v1 ships with a console emitter only.

## Claudriel Integration

Claudriel connects to the agency-agents MCP server as a client. No Waaseyaa package needed for v1: just add the MCP server config to Claudriel's agent subprocess or Claude Code settings.

**Usage examples in Claudriel:**

- Pipeline: "Use the deal-strategist to qualify this prospect" calls `execute_agent`
- Daily brief: "Use the executive-summary-generator to summarize today's events" calls `execute_agent`
- Email: "Use the discovery-coach to prepare questions for this meeting" calls `execute_agent`

The agent subprocess already supports MCP tool calls. Adding the agency-agents MCP server is a configuration change, not a code change.

## Deployment

- **Dev:** `npm run dev` in `api/` directory
- **Production:** Docker container or Node process, configurable via environment variables
- **Required env:** `ANTHROPIC_API_KEY`
- **Optional env:** `PORT`, `LOG_LEVEL`, `CONFIG_PATH`

## Out of Scope (v1)

- Per-specialist output schemas (result field is flexible)
- Authentication / multi-tenant
- Prompt customization / overrides at runtime
- Hot-reloading of prompt index
- Rate limiting (rely on Claude API's own limits)
- Persistent execution history

## Verification

1. **Catalog:** Start server, `GET /v1/agents` returns 100+ agents with correct metadata
2. **Execution:** `POST /v1/agents/sales-deal-strategist/execute` streams tokens and returns a valid summary
3. **MCP:** Connect Claude Code to the MCP server, invoke `execute_agent`, get structured results
4. **Error handling:** Request a non-existent agent, get `AGENT_NOT_FOUND` error in correct envelope
5. **Claudriel integration:** Agent subprocess invokes a specialist via MCP and uses the result in conversation
