# Chat Specification

## Current Runtime

Chat uses `NativeAgentClient` (direct Anthropic Messages API calls with tool use), not the older `AnthropicChatClient` stream loop.

Core files:

- `src/Controller/ChatController.php` — creates sessions/messages (`POST /api/chat/send`)
- `src/Controller/ChatStreamController.php` — streams responses (`GET /stream/chat/{messageId}`)
- `src/Domain/Chat/NativeAgentClient.php` — agent loop, tool execution, retries/fallbacks, continuation signal
- `src/Entity/ChatSession.php`, `src/Entity/ChatMessage.php`, `src/Entity/ChatTokenUsage.php`

## Long-Context Policy (#407, #409)

Conversation history is compacted before each model call:

- max 20 messages per turn
- last 4 messages (2 exchanges) always preserved in full
- older assistant messages truncated to 500 chars with `[truncated]`
- if older messages are dropped, prepend trim marker:
  `[Earlier conversation trimmed — N messages]`
- tool results are truncated to 2000 chars
- `gmail_read` body is truncated to 500 chars

This keeps context bounded while retaining short-term coherence.

**Tradeoffs:** Larger context windows cost more per turn and increase rate-limit surface; compaction prioritizes recency and tool snippets so multi-turn tool work stays usable without sending full thread history on every call.

## Model Selection

Model resolution order in `ChatStreamController`:

1. workspace-level `workspace.anthropic_model` (allowed list only)
2. global `ANTHROPIC_MODEL`
3. hard default `claude-sonnet-4-6`

Allowed models:

- `claude-opus-4-6`
- `claude-sonnet-4-6`
- `claude-haiku-4-5-20251001`

## Continuation + Turn Budget (#310)

`NativeAgentClient` enforces per-task turn limits:

- quick_lookup: 5
- email_compose: 15
- brief_generation: 10
- research: 40
- general: 25
- onboarding: 30

When a turn budget is nearly exhausted and tools are still needed, stream emits:

- `chat-needs-continuation` with `session_uuid`, `turns_consumed`, and prompt text.

The chat UI (`templates/chat.html.twig`) shows Continue/Stop controls and calls:

- `POST /api/internal/session/{id}/continue`

to increment `continued_count` and compute a new budget, respecting a daily ceiling.

## Telemetry (#408)

Per-turn usage from Anthropic `usage` is persisted as `chat_token_usage`:

- `session_uuid`, `turn_number`, `model`
- `input_tokens`, `output_tokens`
- `cache_read_tokens`, `cache_write_tokens`
- `tenant_id`, `workspace_id`, `created_at`

`ChatSession` is also updated during streaming with:

- `turns_consumed`
- `turn_limit_applied`
- `task_type`
- `model`

## SSE Events

- `chat-token` — streamed assistant text chunks
- `chat-progress` — normalized progress updates (phase/summary/level)
- `chat-needs-continuation` — turn-limit continuation request
- `chat-done` — completion marker + full response
- `chat-error` — error payload

## Environment Variables

- `ANTHROPIC_API_KEY` (required for live chat)
- `ANTHROPIC_MODEL` (optional global default)
- `CLAUDRIEL_ROOT` (optional prompt/context root override)
