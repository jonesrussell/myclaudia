# Agent Subprocess Architecture

Supersedes: `2025-03-09-agent-sidecar-design.md` (archived via PR #191)

## Summary

A Python subprocess that gives Claudriel's chat interface access to Gmail and Google Calendar via Claude's tool-use API. The subprocess communicates with PHP over stdin/stdout using a JSON-lines protocol, and calls back to the PHP backend via internal API endpoints with HMAC authentication.

## Architecture

```
Browser → ChatStreamController (PHP)
           ↓ spawns subprocess
         agent/main.py (Python)
           ↓ Anthropic Messages API (tool-use)
           ↓ tool calls → agent/tools/*.py
           ↓ tools call → PHP internal API (HMAC Bearer)
         InternalGoogleController (PHP)
           ↓ OAuthTokenManager → Google APIs
```

## Contract: PHP → Python (stdin)

PHP writes a single JSON object to the subprocess stdin:

```json
{
  "messages": [{"role": "user", "content": "Check my calendar"}],
  "system": "You are Claudriel...",
  "account_id": "acct-uuid-...",
  "tenant_id": "tenant-uuid-...",
  "api_base": "https://claudriel.northcloud.one",
  "api_token": "acct-uuid:1710000000:hmac-signature",
  "model": "claude-sonnet-4-6"
}
```

## Contract: Python → PHP (stdout JSON-lines)

One JSON object per line. Event types:

| Event | Fields | Purpose |
|-------|--------|---------|
| `message` | `content` | Streamed text token |
| `tool_call` | `tool`, `args` | Agent invoking a tool |
| `tool_result` | `tool`, `result` | Tool execution result |
| `done` | — | Stream complete |
| `error` | `message` | Error message |
| `progress` | `phase`, `summary`, `level` | Rate-limit / model fallback status for the UI |
| `needs_continuation` | `turns_consumed`, `task_type`, `message` | Agent hit turn budget; session may continue |

Payloads must be strict JSON (no `NaN` / `Infinity`). Implementation: `claudriel_agent.emit.emit()` uses `json.dumps(..., allow_nan=False)`.

**Strict event names (optional):** Set `CLAUDRIEL_EMIT_STRICT=1` in the agent environment to raise on unknown `event` strings (catches typos). If unset, unknown events still emit for backward compatibility. The canonical allowlist is `ALLOWED_EMIT_EVENTS` in `agent/claudriel_agent/emit.py`.

## Strict Emit System

The emit layer (`agent/claudriel_agent/emit.py`) enforces protocol safety at the Python boundary.

**Allowed event names** are defined as a `frozenset` in `ALLOWED_EMIT_EVENTS`:

```python
ALLOWED_EMIT_EVENTS = frozenset({
    "message", "done", "error", "tool_call",
    "tool_result", "progress", "needs_continuation",
})
```

**Strict mode:** Set `CLAUDRIEL_EMIT_STRICT=1` (also accepts `true`, `yes`) to raise `ValueError` on any event name not in the allowlist. This catches typos during development. When unset or `0`, unknown events still emit for backward compatibility.

**JSON serialization safety:** `emit()` calls `json.dumps(..., allow_nan=False)` so payloads never contain `NaN` or `Infinity`, which are not valid JSON and would break the PHP consumer. Non-serializable payloads raise `ValueError` immediately rather than producing corrupt output.

## Tool Contract

Every tool module in `agent/claudriel_agent/tools/` must export exactly two symbols:

| Export | Type | Purpose |
|--------|------|---------|
| `TOOL_DEF` | `dict` | Anthropic tool definition (must contain a `name` string key) |
| `execute(api, args)` | callable | Synchronous function; receives `PhpApiClient` + tool input dict, returns a result dict |

**No sibling imports.** Tool modules must not import from other tool modules. Each tool is flat and self-contained. Shared behavior belongs in `emit`, `PhpApiClient`, or utility modules outside `tools/`.

**Contract enforcement.** CI runs tests that validate every `*.py` module in `agent/claudriel_agent/tools/` (excluding `__init__.py`) exports a conforming `TOOL_DEF` + `execute` pair and does not import sibling tool modules.

## Tool Discovery

Tools are loaded dynamically at startup by `agent/claudriel_agent/tools_discovery.py`:

1. `discover_tools()` scans `agent/claudriel_agent/tools/*.py` (sorted, skipping `__init__.py`)
2. Each module is imported; modules missing `TOOL_DEF` or a callable `execute` are silently skipped
3. Duplicate tool names (same `TOOL_DEF["name"]`) raise `ValueError`
4. **Optional allowlist:** Set `CLAUDRIEL_AGENT_TOOLS=gmail_list,calendar_list` (comma-separated) to restrict which tools are loaded. Missing configured tools raise `ValueError`
5. `ToolRegistry` wraps discovery with lazy-load semantics (loads once per instance, no process-wide global cache). Call `registry.reset()` in tests to force re-discovery

## Tools

All tools live in `agent/tools/` and delegate to the PHP backend via `PhpApiClient`:

| Tool | File | Internal API Endpoint |
|------|------|-----------------------|
| `gmail_list` | `gmail_list.py` | `GET /api/internal/gmail/list` |
| `gmail_read` | `gmail_read.py` | `GET /api/internal/gmail/read/{id}` |
| `gmail_send` | `gmail_send.py` | `POST /api/internal/gmail/send` |
| `calendar_list` | `calendar_list.py` | `GET /api/internal/calendar/list` |
| `calendar_create` | `calendar_create.py` | `POST /api/internal/calendar/create` |
| `prospect_list` | `prospect_list.py` | `GET /api/internal/prospects/list` |
| `prospect_update` | `prospect_update.py` | `POST /api/internal/prospects/{uuid}/update` |
| `pipeline_fetch_leads` | `pipeline_fetch_leads.py` | `POST /api/internal/pipeline/fetch-leads` |
| `code_task_create` | `code_task_create.py` | `POST /api/internal/code-tasks/create` |
| `code_task_status` | `code_task_status.py` | `GET /api/internal/code-tasks/{uuid}/status` |

## HMAC Authentication

Internal API endpoints use short-lived HMAC-SHA256 tokens:

- **Generator:** `InternalApiTokenGenerator` (PHP)
- **Format:** `{account_id}:{timestamp}:{signature}`
- **TTL:** 300 seconds
- **Validation:** constant-time comparison via `hash_equals()`
- **Secret:** `AGENT_INTERNAL_SECRET` env var (min 32 bytes, validated at boot)

## HTTP Client (Python)

`agent/util/http.py` provides `PhpApiClient`:

- Uses `api_base` as httpx base URL
- Sets `Authorization: Bearer {api_token}` header
- Sets `X-Account-Id` header
- 30-second timeout

## Key Design Decisions

1. **Python is credential-free.** All Google OAuth tokens are managed by PHP. The Python agent never touches OAuth credentials, scopes, or tokens.
2. **No HTTP server in Python.** The original sidecar design used FastAPI + Uvicorn. The subprocess approach is simpler: stdin/stdout, no port binding, no process management.
3. **Tools call back to PHP.** Rather than giving Python direct Google API access, tools make HTTP requests to the internal API, which handles token refresh and API calls.
4. **The Python agent is an adapter, not a second backend.** It must not own permissions, entity validation, multi-step business workflows, or durable state. Those belong in PHP and internal APIs. Each subprocess run is a clean slate: no global caches or long-lived registries beyond the request.
5. **DRY at the protocol layer, repetition at the tool layer.** Shared behavior belongs in `emit`, `PhpApiClient`, and the Anthropic loop. Individual tools stay flat, explicit, and easy to grep (`TOOL_DEF` + `execute` per file); avoid tool frameworks or shared abstractions that hide HTTP routes.
6. **Contract tests enforce tool shape.** CI runs tests that validate every `agent/claudriel_agent/tools/*.py` module exports a consistent `TOOL_DEF` + synchronous `execute(api, args)` and does not import sibling tool modules.

## Agent Loop Details

The core loop lives in `agent/claudriel_agent/loop.py` with constants in `agent/claudriel_agent/constants.py`.

### Task Type Classification

`classify_task_type()` inspects the first user message for keyword heuristics to select a turn budget:

| Task Type | Keywords | Default Limit |
|-----------|----------|---------------|
| `quick_lookup` | check, what time, calendar, schedule, who is | 5 |
| `email_compose` | send, email, reply, compose, draft | 15 |
| `brief_generation` | brief, summary, morning, digest | 10 |
| `research` | research, find out, look into, analyze | 40 |
| `onboarding` | — (set via API only) | 30 |
| `general` | (fallback) | 25 |

Turn limits are fetched dynamically from `GET /api/internal/session/limits` at session start. The defaults in `DEFAULT_TURN_LIMITS` apply when the API is unreachable.

### Tool Result Truncation

To control token growth in conversation history, tool results are truncated before appending to messages (full results are still emitted to the frontend via `tool_result` events):

| Threshold | Value | Notes |
|-----------|-------|-------|
| `TOOL_RESULT_MAX_CHARS` | 2000 | General cap for all tool results |
| `GMAIL_BODY_MAX_CHARS` | 500 | `gmail_read` body field gets special handling |

### Rate Limit Handling

On `RateLimitError`, the loop retries with exponential backoff:

- **Max retries:** 3 (per model)
- **Initial backoff:** 5 seconds (doubled each attempt)
- **Max backoff:** 60 seconds
- **Retry-After header:** Honored when present (capped at 60s)
- **Progress events:** Emits `progress` with `phase=rate_limit` during waits so the frontend shows status

### Model Fallback Chains

Two fallback strategies handle different failure modes:

**Degradation (rate limit exhausted):** Steps down to a cheaper model after all retries fail.

```
claude-opus-4-6 → claude-sonnet-4-6 → claude-haiku-4-5-20251001 → (give up)
```

**Escalation (API error, not rate limit):** Steps up to a more capable model.

```
claude-haiku-4-5-20251001 → claude-sonnet-4-6 → claude-opus-4-6 → (give up)
```

Both chains emit `progress` events with `phase=fallback` and `level=warning`.

### Continuation

When the loop approaches the turn limit with pending tool calls (`turns_consumed >= turn_limit - 1`), it emits a `needs_continuation` event instead of silently stopping. The PHP side can send a new request with `"continued": true` to grant a fresh turn budget.

## Evaluation Framework

The agent includes a contract testing and LLM-judge evaluation system for CI. Source files live in `agent/claudriel_agent/eval_*.py`.

### Components

| Module | Purpose |
|--------|---------|
| `eval_schema.py` | YAML schema validation for eval files; assertion type allowlist |
| `eval_contracts.py` | Schema contract validator: checks SKILL.md GraphQL field references against PHP `fieldDefinitions` |
| `eval_judge.py` | LLM judge (Claude Haiku) that scores skill responses on a 0-5 rubric |
| `eval_runner.py` | CLI entry point for running evals in deterministic or LLM-judge mode |

### Eval YAML Format

Eval files live at `.claude/skills/<skill>/evals/*.yaml`. Structure:

```yaml
eval_type: basic          # basic | trajectory | multi-turn
subject_model: claude-sonnet-4-6  # optional, defaults to sonnet
tests:
  - name: "descriptive test name"
    operation: create     # the operation being tested
    input: "user prompt text"
    assertions:
      - type: field_extraction
        field: title
      - type: confirmation_shown
      - type: graphql_operation
        operation: mutation
```

**Eval types:** `basic` tests require `name`, `operation`, `input`. Trajectory / multi-turn tests use `turns` with nested input and only require `name`.

**Assertion types** (validated against `VALID_ASSERTION_TYPES`):

`field_extraction`, `direction_detected`, `confirmation_shown`, `graphql_operation`, `table_presented`, `filter_applied`, `resolve_first`, `disambiguation`, `error_surfaced`, `before_after_shown`, `asks_for_field`, `no_conjunction_split`, `echo_back_required`, `offers_alternative`, `no_file_operations`, `secondary_intent_queued`

### Schema Contract Validation

`eval_contracts.py` cross-references GraphQL field names in SKILL.md files against PHP `fieldDefinitions` in service providers. Fields referenced by skills but missing from the schema are violations. This catches drift between the agent skills and the PHP entity layer.

### Usage

```bash
# Deterministic only (CI-safe, no API calls)
python -m claudriel_agent.eval_runner --deterministic

# LLM-judge evaluation (requires ANTHROPIC_API_KEY)
python -m claudriel_agent.eval_runner --llm-judge
python -m claudriel_agent.eval_runner --llm-judge --skill commitment --type basic

# Schema contract validation
python -m claudriel_agent.eval_contracts
python -m claudriel_agent.eval_contracts --skill commitment --json
```

Pass threshold for LLM-judge: 3.0/5.0. Non-zero exit on any failure.

## Dependencies

- `anthropic>=0.40.0` (Claude tool-use API)
- `httpx>=0.27.0` (HTTP client for internal API calls)

## Execution Modes

| Mode | Config | Command |
|------|--------|---------|
| Docker (production) | `AGENT_DOCKER_IMAGE=claudriel-agent` | `docker run` with stdin pipe |
| Venv (development) | `AGENT_VENV`, `AGENT_PATH` | Direct Python execution |
