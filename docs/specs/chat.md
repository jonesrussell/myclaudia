# Chat Specification

## File Map

| File | Purpose |
|------|---------|
| `src/Domain/Chat/AnthropicChatClient.php` | Handles non-streaming (`complete()`) and SSE streaming (`stream()`) to Anthropic API |
| `src/Domain/Chat/ChatSystemPromptBuilder.php` | Assembles system prompt from personality, user context, and brief data |
| `src/Entity/ChatSession.php` | Conversation thread entity (uuid, title) |
| `src/Entity/ChatMessage.php` | Individual message entity (session_uuid, role, content) |
| `src/Controller/ChatController.php` | `GET /chat` (UI) and `POST /api/chat/send` (message creation) |
| `src/Controller/ChatStreamController.php` | `GET /stream/chat/{messageId}` (SSE streaming response) |
| `src/Controller/StorageRepositoryAdapter.php` | Bridges EntityTypeManager storage to EntityRepositoryInterface |

## Interface Signatures

```php
// AnthropicChatClient
public function __construct(string $apiKey, string $model = 'claude-sonnet-4-20250514')
public function complete(string $systemPrompt, array $messages): string
public function stream(string $systemPrompt, array $messages, callable $onToken): void

// ChatSystemPromptBuilder
public function __construct(string $rootPath, DayBriefAssembler $briefAssembler)
public function build(string $tenantId, ?object $activeWorkspace = null): string
// $activeWorkspace: optional Workspace entity; when provided, build() prepends workspace context to the prompt

// ChatController
public function send(ServerRequestInterface $request): ResponseInterface

// ChatStreamController
public function stream(ServerRequestInterface $request, array $params): void
```

## Data Flow

```
User sends message:
  POST /api/chat/send
    → ChatController::send()
    → creates/loads ChatSession
    → saves user ChatMessage (role='user')
    → returns JSON { session_uuid, message_id }

Client opens SSE stream:
  GET /stream/chat/{messageId}
    → ChatStreamController::stream()
    → loads ChatSession + all ChatMessages for conversation history
    → ChatSystemPromptBuilder::build($tenantId)
        → reads CLAUDE.md personality
        → reads context/me.md user context
        → calls DayBriefAssembler::assemble() for current brief
        → concatenates into system prompt
    → AnthropicChatClient::stream($systemPrompt, $messages, $onToken)
        → POST to Anthropic API with stream: true
        → parses SSE chunks from API
        → calls $onToken(string) per content_block_delta
    → emits SSE events: chat-token, chat-done, chat-error
    → saves assistant ChatMessage (role='assistant')
```

## SSE Event Format

```
event: chat-token
data: {"token": "partial text"}

event: chat-done
data: {"message": "full assistant response"}

event: chat-error
data: {"error": "error description"}
```

Uses output buffering (`ob_flush()` + `flush()`) for real-time delivery. Sets headers: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `Connection: keep-alive`.

## System Prompt Assembly

`ChatSystemPromptBuilder::build()` concatenates three sources:

1. **Personality**: reads `CLAUDE.md` from `$rootPath` (the `CLAUDRIEL_ROOT` env var or project root)
2. **User context**: reads `context/me.md` from `$rootPath`
3. **Daily brief**: calls `DayBriefAssembler::assemble()` and formats result as text summary

If any file is missing, that section is silently omitted.

## Entity Keys

```php
// ChatSession
entityTypeId: 'chat_session'
entityKeys: [id => csid, uuid, label => title]

// ChatMessage
entityTypeId: 'chat_message'
entityKeys: [id => cmid, uuid]
```

## Routes

| Method | Path | Controller | Purpose |
|--------|------|-----------|---------|
| `GET` | `/chat` | `DashboardController::show` | Chat UI (redirects to dashboard) |
| `POST` | `/api/chat/send` | `ChatController::send` | Create message |
| `GET` | `/stream/chat/{messageId}` | `ChatStreamController::stream` | SSE stream |

## Environment Variables

| Variable | Required | Default | Purpose |
|----------|----------|---------|---------|
| `ANTHROPIC_API_KEY` | Yes | — | API authentication |
| `ANTHROPIC_MODEL` | No | `claude-sonnet-4-20250514` | Model selection |
| `CLAUDRIEL_ROOT` | No | project root | Base path for personality/context files |

## Dependencies

```php
ChatStreamController(
    EntityTypeManager $etm,    // for loading sessions and messages
    DayBriefAssembler $brief   // passed to ChatSystemPromptBuilder
)

ChatController(
    EntityTypeManager $etm     // for saving sessions and messages
)

AnthropicChatClient(
    string $apiKey,
    string $model
)

ChatSystemPromptBuilder(
    string $rootPath,
    DayBriefAssembler $briefAssembler
)
```

## Critical Notes

- `StorageRepositoryAdapter` wraps `EntityTypeManager` to provide `EntityRepositoryInterface` for chat entities
- Streaming uses PHP output buffering, not a dedicated SSE library
- The system prompt includes the full daily brief data, making each chat response context-aware
- `ChatMessage` has no `label` key (unlike most other entities), only `id` and `uuid`
