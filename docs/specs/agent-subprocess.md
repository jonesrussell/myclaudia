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

## Dependencies

- `anthropic>=0.40.0` (Claude tool-use API)
- `httpx>=0.27.0` (HTTP client for internal API calls)

## Execution Modes

| Mode | Config | Command |
|------|--------|---------|
| Docker (production) | `AGENT_DOCKER_IMAGE=claudriel-agent` | `docker run` with stdin pipe |
| Venv (development) | `AGENT_VENV`, `AGENT_PATH` | Direct Python execution |
