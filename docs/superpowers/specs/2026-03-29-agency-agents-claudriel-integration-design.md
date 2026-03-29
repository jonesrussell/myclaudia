# Agency-Agents Claudriel Integration — Design Spec

**Date:** 2026-03-29
**Status:** Draft
**Author:** Russell Jones + Claude

## Context

The agency-agents API (`jonesrussell/agency-agents`, branch `feat/api-layer`) exposes 180 specialist AI agent prompts via REST and MCP. Claudriel needs to consume these specialists through its existing chat agent so users can invoke specialized reasoning (sales strategy, proposal writing, discovery coaching, executive summaries) from the chat interface.

**Goal:** Wire the agency-agents REST API into Claudriel's agent as two meta-tools, following existing `AgentToolInterface` patterns.

## Architecture

```
User → Chat UI → ChatStreamController → NativeAgentClient
                                              ↓
                         AgentToolInterface implementations
                              ↓                    ↓
                    SpecialistListTool    SpecialistExecuteTool
                              ↓                    ↓
                    GET /v1/agents        POST /v1/agents/:slug/execute
                              ↓                    ↓
                         agency-agents API (sidecar, localhost:3100)
                              ↓                    ↓
                         Prompt Catalog      Claude API (specialist)
```

The agency-agents API runs as a sidecar service. Claudriel's agent discovers specialists via `list_specialists`, then invokes them via `execute_specialist`. The agent reasons about which specialist to use based on the task at hand.

## Tool Definitions

### list_specialists

```json
{
  "name": "list_specialists",
  "description": "Search and filter available AI specialists. Returns a list of specialist agents with their expertise and when to use them. Use this to find the right specialist for a task before calling execute_specialist.",
  "input_schema": {
    "type": "object",
    "properties": {
      "query": {
        "type": "string",
        "description": "Search by keyword (e.g., 'deal strategy', 'feedback', 'proposal')"
      },
      "division": {
        "type": "string",
        "description": "Filter by division (e.g., 'sales', 'product', 'engineering', 'marketing')"
      },
      "limit": {
        "type": "integer",
        "description": "Max results to return (default 10)"
      }
    }
  }
}
```

**execute() flow:**
1. Build URL: `{AGENCY_AGENTS_URL}/v1/agents?q={query}&division={division}&limit={limit}`
2. `file_get_contents()` with stream context (established Claudriel HTTP pattern)
3. Decode JSON response
4. Return array of `{slug, name, division, specialty, when_to_use}` from `agents` field

### execute_specialist

```json
{
  "name": "execute_specialist",
  "description": "Execute a specialist agent with a task. The specialist will analyze the task using its domain expertise and return structured findings. First use list_specialists to find the right specialist slug.",
  "input_schema": {
    "type": "object",
    "properties": {
      "slug": {
        "type": "string",
        "description": "Specialist slug from list_specialists (e.g., 'sales-deal-strategist')"
      },
      "task": {
        "type": "string",
        "description": "The task for the specialist to perform"
      },
      "context": {
        "type": "object",
        "description": "Optional context data the specialist can reference"
      }
    },
    "required": ["slug", "task"]
  }
}
```

**execute() flow:**
1. POST to `{AGENCY_AGENTS_URL}/v1/agents/{slug}/execute` with JSON body `{task, context}`
2. Read SSE stream response using `file_get_contents()` with stream context
3. Parse SSE events line by line
4. Extract the final `summary` event data (JSON)
5. Return the structured result from the summary

**SSE parsing:** The response is `text/event-stream`. Read the full body (the stream completes when the specialist finishes), split by double newline, find the last event with `event: summary`, parse its `data:` line as JSON.

**Error handling:**
- If the API returns a non-200 status or an SSE `error` event, return `['error' => $message]`
- If the specialist times out or the API is unreachable, return `['error' => 'Specialist service unavailable']`

## Registration

In `ChatStreamController::buildAgentTools()`, add after existing tool groups:

```php
// Agency specialists (optional sidecar)
$agencyUrl = getenv('AGENCY_AGENTS_URL');
if ($agencyUrl) {
    $tools[] = new SpecialistListTool($agencyUrl);
    $tools[] = new SpecialistExecuteTool($agencyUrl);
}
```

This follows the existing pattern of gating tool groups by env var availability (same as Google tools gated by OAuth token availability).

## Configuration

| Env var | Required | Default | Purpose |
|---------|----------|---------|---------|
| `AGENCY_AGENTS_URL` | No | (none) | Base URL of agency-agents API. If not set, specialist tools are not registered. |

No changes to `config.yaml` or database. Purely env-var driven, matching Claudriel's existing configuration patterns.

## Files

| Action | Path | Purpose |
|--------|------|---------|
| Create | `src/Domain/Chat/Tool/SpecialistListTool.php` | list_specialists tool |
| Create | `src/Domain/Chat/Tool/SpecialistExecuteTool.php` | execute_specialist tool |
| Modify | `src/Controller/ChatStreamController.php` | Register tools in buildAgentTools() |
| Create | `tests/Unit/Domain/Chat/Tool/SpecialistListToolTest.php` | Unit tests |
| Create | `tests/Unit/Domain/Chat/Tool/SpecialistExecuteToolTest.php` | Unit tests |

## Out of Scope

- Auth between Claudriel and agency-agents (both local, same machine)
- Caching specialist results
- Persistent execution history
- Admin UI for specialist management
- Pipeline domain auto-invocation (specialists called on-demand by agent, not automated)

## Verification

1. Start agency-agents API: `cd ~/dev/agency-agents/api && PROMPTS_DIR=.. npm run dev`
2. Set `AGENCY_AGENTS_URL=http://localhost:3100` in Claudriel's `.env`
3. Start Claudriel dev server
4. In chat: "Find me a specialist for qualifying sales deals"
   - Agent should call `list_specialists(query: "deal qualification")`
   - Return should include `sales-deal-strategist`
5. In chat: "Use the deal strategist to qualify Acme Corp: $50K ARR, no champion, budget unclear"
   - Agent should call `execute_specialist(slug: "sales-deal-strategist", task: "...")`
   - Return should include structured qualification analysis
6. Unit tests pass: `vendor/bin/phpunit tests/Unit/Domain/Chat/Tool/Specialist*`
