# Agent Sidecar Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give Claudriel's chat interface access to Gmail and Google Calendar via a Python FastAPI sidecar wrapping the Claude Code SDK.

**Architecture:** PHP sends chat requests to a Python sidecar over HTTP. The sidecar maintains long-lived `ClaudeSDKClient` sessions per chat. Claude Code's authenticated MCP connectors provide Gmail/Calendar access. SSE streams flow: sidecar -> PHP -> browser.

**Tech Stack:** Python 3.11, FastAPI, Uvicorn, claude-code-sdk, Docker

**Spec:** `docs/specs/2025-03-09-agent-sidecar-design.md`

---

## Prerequisite: Verify Claude Code Auth in Docker

Before any implementation, we must confirm that mounting `~/.claude/` into a Docker container gives the Claude Code SDK working authentication.

- [ ] **Step 1: Create a minimal test script**

Create `docker/sidecar/test_auth.py`:
```python
import asyncio
from claude_code_sdk import query, ClaudeCodeOptions

async def main():
    response_parts = []
    async for msg in query(
        prompt="Say 'auth works' and nothing else.",
        options=ClaudeCodeOptions(max_turns=1)
    ):
        if hasattr(msg, 'content'):
            response_parts.append(str(msg.content))
    print("Response:", " ".join(response_parts))

asyncio.run(main())
```

- [ ] **Step 2: Test with Docker**

```bash
docker run --rm \
  -v ${HOME}/.claude:/root/.claude:ro \
  -v $(pwd)/docker/sidecar/test_auth.py:/app/test_auth.py \
  python:3.11-slim \
  bash -c "pip install claude-code-sdk && python /app/test_auth.py"
```

Expected: Prints "Response: auth works" (or similar confirmation).
If this fails, stop and investigate auth token portability before proceeding.

- [ ] **Step 3: Clean up test script**

```bash
rm docker/sidecar/test_auth.py
```

---

## Chunk 1: Sidecar Service

### Task 1: Sidecar Project Structure

**Files:**
- Create: `docker/sidecar/requirements.txt`
- Create: `docker/sidecar/Dockerfile`
- Create: `docker/sidecar/app/__init__.py`

- [ ] **Step 1: Create requirements.txt**

Create `docker/sidecar/requirements.txt`:
```
claude-code-sdk
fastapi
uvicorn[standard]
pytest
```

- [ ] **Step 2: Create Dockerfile**

Create `docker/sidecar/Dockerfile`:
```dockerfile
FROM python:3.11-slim

RUN apt-get update && apt-get install -y --no-install-recommends curl && rm -rf /var/lib/apt/lists/*

WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY app/ ./app/

EXPOSE 8100
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8100"]
```

- [ ] **Step 3: Create empty package**

Create `docker/sidecar/app/__init__.py`:
```python
```

- [ ] **Step 4: Commit**

```bash
git add docker/sidecar/
git commit -m "feat(sidecar): scaffold Python sidecar project structure"
```

---

### Task 2: Session Manager

**Files:**
- Create: `docker/sidecar/app/session_manager.py`
- Create: `docker/sidecar/app/test_session_manager.py`

The session manager owns `ClaudeSDKClient` lifecycle: lazy creation, reuse, timeout cleanup.

- [ ] **Step 1: Write the session manager**

Create `docker/sidecar/app/session_manager.py`:
```python
import asyncio
import time
from dataclasses import dataclass, field


@dataclass
class Session:
    session_id: str
    last_activity: float = field(default_factory=time.time)

    def touch(self) -> None:
        self.last_activity = time.time()

    def is_expired(self, timeout_seconds: float) -> bool:
        return (time.time() - self.last_activity) > timeout_seconds


class SessionManager:
    def __init__(self, timeout_minutes: int = 15):
        self._sessions: dict[str, Session] = {}
        self._timeout_seconds = timeout_minutes * 60
        self._cleanup_task: asyncio.Task | None = None

    def get_or_create(self, session_id: str) -> Session:
        if session_id in self._sessions:
            session = self._sessions[session_id]
            session.touch()
            return session
        session = Session(session_id=session_id)
        self._sessions[session_id] = session
        return session

    def remove(self, session_id: str) -> bool:
        return self._sessions.pop(session_id, None) is not None

    def cleanup_expired(self) -> list[str]:
        expired = [
            sid for sid, session in self._sessions.items()
            if session.is_expired(self._timeout_seconds)
        ]
        for sid in expired:
            del self._sessions[sid]
        return expired

    async def start_cleanup_loop(self, interval_seconds: int = 60) -> None:
        self._cleanup_task = asyncio.create_task(self._cleanup_loop(interval_seconds))

    async def _cleanup_loop(self, interval_seconds: int) -> None:
        while True:
            await asyncio.sleep(interval_seconds)
            self.cleanup_expired()

    async def stop_cleanup_loop(self) -> None:
        if self._cleanup_task:
            self._cleanup_task.cancel()
            try:
                await self._cleanup_task
            except asyncio.CancelledError:
                pass

    @property
    def active_count(self) -> int:
        return len(self._sessions)
```

- [ ] **Step 2: Write tests**

Create `docker/sidecar/app/test_session_manager.py`:
```python
import time
from app.session_manager import SessionManager, Session


def test_get_or_create_new_session():
    manager = SessionManager(timeout_minutes=15)
    session = manager.get_or_create("abc-123")
    assert session.session_id == "abc-123"
    assert manager.active_count == 1


def test_get_or_create_reuses_existing():
    manager = SessionManager(timeout_minutes=15)
    s1 = manager.get_or_create("abc-123")
    s2 = manager.get_or_create("abc-123")
    assert s1 is s2
    assert manager.active_count == 1


def test_remove_session():
    manager = SessionManager(timeout_minutes=15)
    manager.get_or_create("abc-123")
    assert manager.remove("abc-123") is True
    assert manager.active_count == 0
    assert manager.remove("abc-123") is False


def test_cleanup_expired():
    manager = SessionManager(timeout_minutes=0)  # 0 min = expire immediately
    session = manager.get_or_create("abc-123")
    session.last_activity = time.time() - 1  # force expired
    expired = manager.cleanup_expired()
    assert expired == ["abc-123"]
    assert manager.active_count == 0


def test_touch_refreshes_activity():
    manager = SessionManager(timeout_minutes=0)
    session = manager.get_or_create("abc-123")
    session.last_activity = time.time() - 100
    session.touch()
    assert not session.is_expired(1)
```

- [ ] **Step 3: Run tests**

```bash
cd docker/sidecar && pip install pytest && python -m pytest app/test_session_manager.py -v
```

Expected: All 5 tests pass.

- [ ] **Step 4: Commit**

```bash
git add docker/sidecar/app/session_manager.py docker/sidecar/app/test_session_manager.py
git commit -m "feat(sidecar): add session manager with timeout cleanup"
```

---

### Task 3: Claude Code SDK Integration

**Files:**
- Create: `docker/sidecar/app/claude_client.py`

This module wraps `claude_code_sdk.query()` and streams results. Tool access is restricted to Gmail and Calendar MCP tools only.

- [ ] **Step 1: Write the Claude client wrapper**

Create `docker/sidecar/app/claude_client.py`:
```python
import os
from collections.abc import AsyncIterator
from dataclasses import dataclass

from claude_code_sdk import query, ClaudeCodeOptions, AssistantMessage, ResultMessage


@dataclass
class TokenEvent:
    text: str


@dataclass
class DoneEvent:
    full_text: str


@dataclass
class ErrorEvent:
    error: str


StreamEvent = TokenEvent | DoneEvent | ErrorEvent

# Restrict Claude to Gmail and Calendar MCP tools only.
# No file system, shell, or code editing access.
ALLOWED_TOOLS = [
    "mcp__claude_ai_Gmail__*",
    "mcp__claude_ai_Google_Calendar__*",
]


async def stream_chat(
    system_prompt: str,
    messages: list[dict[str, str]],
) -> AsyncIterator[StreamEvent]:
    """Send messages to Claude Code SDK and yield streaming events."""
    model = os.environ.get("CLAUDE_MODEL", "claude-sonnet-4-6")

    prompt = _format_messages(messages)

    options = ClaudeCodeOptions(
        system_prompt=system_prompt,
        model=model,
        max_turns=25,
        allowed_tools=ALLOWED_TOOLS,
    )

    full_text = ""

    try:
        async for message in query(prompt=prompt, options=options):
            if isinstance(message, AssistantMessage):
                for block in message.content:
                    if hasattr(block, "text"):
                        full_text += block.text
                        yield TokenEvent(text=block.text)
            elif isinstance(message, ResultMessage):
                for block in message.content:
                    if hasattr(block, "text"):
                        full_text += block.text
                        yield TokenEvent(text=block.text)

        yield DoneEvent(full_text=full_text)

    except Exception as e:
        yield ErrorEvent(error=str(e))


def _format_messages(messages: list[dict[str, str]]) -> str:
    """Format conversation history as a prompt string for the SDK."""
    if not messages:
        return ""

    if len(messages) == 1:
        return messages[0]["content"]

    parts = []
    for msg in messages[:-1]:
        role = "User" if msg["role"] == "user" else "Assistant"
        parts.append(f"{role}: {msg['content']}")

    parts.append(f"\nUser: {messages[-1]['content']}")
    return "\n".join(parts)
```

Note: The `allowed_tools` glob patterns must match the actual MCP tool names exposed by Claude Code. If the exact pattern doesn't work, check `claude --help` or test interactively to discover the correct tool name format, then update `ALLOWED_TOOLS`.

- [ ] **Step 2: Commit**

```bash
git add docker/sidecar/app/claude_client.py
git commit -m "feat(sidecar): add Claude Code SDK streaming wrapper"
```

---

### Task 4: FastAPI Application

**Files:**
- Create: `docker/sidecar/app/main.py`
- Create: `docker/sidecar/app/auth.py`

- [ ] **Step 1: Write auth middleware**

Create `docker/sidecar/app/auth.py`:
```python
import os
from fastapi import HTTPException, Security
from fastapi.security import HTTPAuthorizationCredentials, HTTPBearer

security = HTTPBearer()


def verify_api_key(
    credentials: HTTPAuthorizationCredentials = Security(security),
) -> str:
    expected = os.environ.get("CLAUDRIEL_SIDECAR_KEY", "")
    if not expected:
        raise HTTPException(status_code=500, detail="CLAUDRIEL_SIDECAR_KEY not configured")
    if credentials.credentials != expected:
        raise HTTPException(status_code=401, detail="Invalid API key")
    return credentials.credentials
```

- [ ] **Step 2: Write the FastAPI app**

Create `docker/sidecar/app/main.py`:
```python
import asyncio
import json
import os
from contextlib import asynccontextmanager

from fastapi import Depends, FastAPI, HTTPException, Response
from fastapi.responses import StreamingResponse
from pydantic import BaseModel

from app.auth import verify_api_key
from app.claude_client import stream_chat, TokenEvent, DoneEvent, ErrorEvent
from app.session_manager import SessionManager


session_manager: SessionManager | None = None


@asynccontextmanager
async def lifespan(app: FastAPI):
    global session_manager
    timeout = int(os.environ.get("SESSION_TIMEOUT_MINUTES", "15"))
    session_manager = SessionManager(timeout_minutes=timeout)
    await session_manager.start_cleanup_loop()
    yield
    await session_manager.stop_cleanup_loop()


app = FastAPI(title="Claudriel Sidecar", lifespan=lifespan)


class ChatMessage(BaseModel):
    role: str
    content: str


class ChatRequest(BaseModel):
    session_id: str
    system_prompt: str
    messages: list[ChatMessage]


@app.get("/health")
async def health():
    return {"status": "ok", "active_sessions": session_manager.active_count if session_manager else 0}


@app.post("/chat")
async def chat(
    request: ChatRequest,
    _key: str = Depends(verify_api_key),
):
    if not session_manager:
        raise HTTPException(status_code=503, detail="Service not ready")

    session = session_manager.get_or_create(request.session_id)

    async def event_stream():
        try:
            messages = [{"role": m.role, "content": m.content} for m in request.messages]

            async for event in _with_heartbeat(
                stream_chat(system_prompt=request.system_prompt, messages=messages),
                interval=15.0,
            ):
                session.touch()

                if event is None:
                    yield ": heartbeat\n\n"
                elif isinstance(event, TokenEvent):
                    yield _sse("chat-token", {"token": event.text})
                elif isinstance(event, DoneEvent):
                    yield _sse("chat-done", {"done": True, "full_response": event.full_text})
                elif isinstance(event, ErrorEvent):
                    yield _sse("chat-error", {"error": event.error})

        except Exception as e:
            yield _sse("chat-error", {"error": str(e)})

    return StreamingResponse(
        event_stream(),
        media_type="text/event-stream",
        headers={
            "Cache-Control": "no-cache",
            "Connection": "keep-alive",
            "X-Accel-Buffering": "no",
        },
    )


@app.delete("/chat/{session_id}")
async def delete_session(
    session_id: str,
    _key: str = Depends(verify_api_key),
):
    if session_manager and session_manager.remove(session_id):
        return Response(status_code=204)
    raise HTTPException(status_code=404, detail="Session not found")


async def _with_heartbeat(stream, interval: float = 15.0):
    """Wrap an async iterator to yield None as heartbeat when no events arrive within interval."""
    aiter = stream.__aiter__()
    while True:
        try:
            event = await asyncio.wait_for(aiter.__anext__(), timeout=interval)
            yield event
        except asyncio.TimeoutError:
            yield None  # heartbeat signal
        except StopAsyncIteration:
            break


def _sse(event: str, data: dict) -> str:
    return f"event: {event}\ndata: {json.dumps(data)}\n\n"
```

- [ ] **Step 4: Commit**

```bash
git add docker/sidecar/app/main.py docker/sidecar/app/auth.py
git commit -m "feat(sidecar): add FastAPI app with SSE streaming and heartbeat"
```

---

### Task 5: Docker Compose Integration

**Files:**
- Modify: `docker-compose.yml`

- [ ] **Step 1: Add sidecar service to docker-compose.yml**

Add the sidecar service and a named network. The existing `caddy` and `php` services need the network added too.

After the existing services, add:

```yaml
  sidecar:
    build:
      context: ./docker/sidecar
    environment:
      - CLAUDRIEL_SIDECAR_KEY=${CLAUDRIEL_SIDECAR_KEY}
      - SESSION_TIMEOUT_MINUTES=15
      - CLAUDE_MODEL=${CLAUDE_MODEL:-claude-sonnet-4-6}
    volumes:
      - ${HOME}/.claude:/root/.claude:ro
    expose:
      - "8100"
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8100/health"]
      interval: 10s
      timeout: 5s
      retries: 3
```

Add to the `php` service environment:
```yaml
      - SIDECAR_URL=http://sidecar:8100
      - CLAUDRIEL_SIDECAR_KEY=${CLAUDRIEL_SIDECAR_KEY}
```

Add `depends_on` to the `php` service:
```yaml
    depends_on:
      sidecar:
        condition: service_healthy
```

- [ ] **Step 2: Build and verify sidecar starts**

```bash
docker compose build sidecar
docker compose up sidecar -d
docker compose logs sidecar
```

Expected: Uvicorn starts, health endpoint responds.

- [ ] **Step 3: Test health endpoint**

```bash
docker compose exec sidecar curl -s http://localhost:8100/health
```

Expected: `{"status":"ok","active_sessions":0}`

- [ ] **Step 4: Commit**

```bash
git add docker-compose.yml
git commit -m "feat(docker): add sidecar service with health check"
```

---

## Chunk 2: PHP Integration

### Task 6: SidecarChatClient

**Files:**
- Create: `src/Domain/Chat/SidecarChatClient.php`

This client replaces `AnthropicChatClient` when the sidecar is available. Same interface (`stream` method with callbacks), different transport.

- [ ] **Step 1: Create SidecarChatClient**

Create `src/Domain/Chat/SidecarChatClient.php`:
```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

use Closure;

class SidecarChatClient
{
    public function __construct(
        private string $sidecarUrl,
        private string $sidecarKey,
    ) {}

    /**
     * Stream a chat response from the sidecar service.
     *
     * Matches AnthropicChatClient::stream() signature for drop-in use.
     */
    public function stream(
        string $systemPrompt,
        array $messages,
        Closure $onToken,
        Closure $onDone,
        Closure $onError,
        ?string $sessionId = null,
    ): void {
        $payload = json_encode([
            'session_id' => $sessionId ?? 'default',
            'system_prompt' => $systemPrompt,
            'messages' => $messages,
        ]);

        $ch = curl_init($this->sidecarUrl . '/chat');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->sidecarKey,
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($onToken, $onDone, $onError) {
                $this->handleSseChunk($data, $onToken, $onDone, $onError);
                return strlen($data);
            },
        ]);

        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false || $httpCode >= 400) {
            $onError($curlError ?: "Sidecar returned HTTP $httpCode");
        }
    }

    public function isAvailable(): bool
    {
        $ch = curl_init($this->sidecarUrl . '/health');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_CONNECTTIMEOUT => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Buffer for SSE data that may arrive split across curl write callbacks.
     */
    private string $sseBuffer = '';
    private ?string $currentEventType = null;

    private function handleSseChunk(
        string $data,
        Closure $onToken,
        Closure $onDone,
        Closure $onError,
    ): void {
        $this->sseBuffer .= $data;

        // Process complete lines (terminated by \n)
        while (($pos = strpos($this->sseBuffer, "\n")) !== false) {
            $line = substr($this->sseBuffer, 0, $pos);
            $this->sseBuffer = substr($this->sseBuffer, $pos + 1);
            $line = trim($line);

            if ($line === '') {
                // Empty line = end of event, dispatch if we have type + data
                $this->currentEventType = null;
                continue;
            }

            if (str_starts_with($line, ':')) {
                continue; // Heartbeat comment
            }

            if (str_starts_with($line, 'event: ')) {
                $this->currentEventType = substr($line, 7);
                continue;
            }

            if (str_starts_with($line, 'data: ') && $this->currentEventType !== null) {
                $payload = json_decode(substr($line, 6), true);
                if ($payload === null) {
                    continue;
                }

                match ($this->currentEventType) {
                    'chat-token' => $onToken($payload['token'] ?? ''),
                    'chat-done' => $onDone($payload['full_response'] ?? ''),
                    'chat-error' => $onError($payload['error'] ?? 'Unknown error'),
                    default => null,
                };
            }
        }
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Domain/Chat/SidecarChatClient.php
git commit -m "feat(chat): add SidecarChatClient for sidecar SSE consumption"
```

---

### Task 7: Wire SidecarChatClient into ChatStreamController

**Files:**
- Modify: `src/Controller/ChatStreamController.php`

The current controller creates `AnthropicChatClient` inline inside `streamTokens()` (line 102):
```php
$client = new AnthropicChatClient($apiKey, $model);
```

There is no DI container registration for chat clients. The pattern in this codebase is inline instantiation. We follow the same pattern for the sidecar client.

- [ ] **Step 1: Add SidecarChatClient use statement**

At the top of `ChatStreamController.php`, add:
```php
use Claudriel\Domain\Chat\SidecarChatClient;
```

- [ ] **Step 2: Replace the client creation in `streamTokens()`**

In `ChatStreamController::streamTokens()`, replace line 101-102:
```php
$model = $_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-20250514';
$client = new AnthropicChatClient($apiKey, $model);
```

With sidecar-first logic:
```php
// Try sidecar first (provides Gmail/Calendar via Claude Code MCP)
$sidecarUrl = $_ENV['SIDECAR_URL'] ?? getenv('SIDECAR_URL') ?: '';
$sidecarKey = $_ENV['CLAUDRIEL_SIDECAR_KEY'] ?? getenv('CLAUDRIEL_SIDECAR_KEY') ?: '';
$useSidecar = false;
$sidecarClient = null;

if ($sidecarUrl !== '' && $sidecarKey !== '') {
    $sidecarClient = new SidecarChatClient($sidecarUrl, $sidecarKey);
    $useSidecar = $sidecarClient->isAvailable();
}

if ($useSidecar) {
    $sidecarClient->stream(
        $systemPrompt,
        $apiMessages,
        onToken: function (string $token): void {
            $data = json_encode(['token' => $token], JSON_THROW_ON_ERROR);
            echo "event: chat-token\ndata: {$data}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        },
        onDone: function (string $fullResponse) use ($sessionUuid, $msgStorage): void {
            $assistantMsg = new ChatMessage([
                'uuid' => $this->generateUuid(),
                'session_uuid' => $sessionUuid,
                'role' => 'assistant',
                'content' => $fullResponse,
                'created_at' => (new \DateTimeImmutable())->format('c'),
            ]);
            $msgStorage->save($assistantMsg);

            $data = json_encode(['done' => true, 'full_response' => $fullResponse], JSON_THROW_ON_ERROR);
            echo "event: chat-done\ndata: {$data}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        },
        onError: function (string $error): void {
            $data = json_encode(['error' => $error], JSON_THROW_ON_ERROR);
            echo "event: chat-error\ndata: {$data}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        },
        sessionId: $sessionUuid,
    );
} else {
    // Fallback: direct Anthropic API (no Gmail/Calendar)
    $model = $_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-20250514';
    $client = new AnthropicChatClient($apiKey, $model);

    $client->stream(
        $systemPrompt,
        $apiMessages,
        onToken: function (string $token): void {
            $data = json_encode(['token' => $token], JSON_THROW_ON_ERROR);
            echo "event: chat-token\ndata: {$data}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        },
        onDone: function (string $fullResponse) use ($sessionUuid, $msgStorage): void {
            $assistantMsg = new ChatMessage([
                'uuid' => $this->generateUuid(),
                'session_uuid' => $sessionUuid,
                'role' => 'assistant',
                'content' => $fullResponse,
                'created_at' => (new \DateTimeImmutable())->format('c'),
            ]);
            $msgStorage->save($assistantMsg);

            $data = json_encode(['done' => true, 'full_response' => $fullResponse], JSON_THROW_ON_ERROR);
            echo "event: chat-done\ndata: {$data}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        },
        onError: function (string $error): void {
            $data = json_encode(['error' => $error], JSON_THROW_ON_ERROR);
            echo "event: chat-error\ndata: {$data}\n\n";
            if (ob_get_level() > 0) {
                ob_flush();
            }
            flush();
        },
    );
}
```

Note: Yes, the callback closures are duplicated. This is intentional. Extracting them into a helper would add abstraction for a two-path branch. If a third client path is ever needed, refactor then.

- [ ] **Step 3: Commit**

```bash
git add src/Controller/ChatStreamController.php
git commit -m "feat(chat): wire sidecar client with fallback to direct API"
```

---

### Task 8: End-to-End Test

- [ ] **Step 1: Start all services**

```bash
docker compose up -d --build
```

- [ ] **Step 2: Verify sidecar is healthy**

```bash
docker compose exec php curl -s http://sidecar:8100/health
```

Expected: `{"status":"ok","active_sessions":0}`

- [ ] **Step 3: Test chat via Claudriel UI**

Open `http://localhost:9889` in browser. Send a message like "Hello, what can you help me with?"
Expected: Streaming response appears, no errors in console.

- [ ] **Step 4: Test Gmail access**

Send: "Check my email"
Expected: Claude accesses Gmail via MCP and returns email summary.

- [ ] **Step 5: Test Calendar access**

Send: "What's on my calendar today?"
Expected: Claude accesses Google Calendar via MCP and returns schedule.

- [ ] **Step 6: Test fallback (stop sidecar)**

```bash
docker compose stop sidecar
```

Send a message in chat.
Expected: Chat still works via direct Anthropic API (no Gmail/Calendar, but text chat functions).

- [ ] **Step 7: Restart sidecar**

```bash
docker compose start sidecar
```

- [ ] **Step 8: Commit any fixes from testing**

```bash
git add -A
git commit -m "fix(sidecar): adjustments from end-to-end testing"
```

---

## Task Summary

| Task | Description | Files |
|------|-------------|-------|
| Prereq | Verify Claude Code auth in Docker | test script (temporary) |
| 1 | Sidecar project structure | Dockerfile, requirements.txt |
| 2 | Session manager | session_manager.py + tests |
| 3 | Claude Code SDK wrapper | claude_client.py |
| 4 | FastAPI application | main.py, auth.py |
| 5 | Docker Compose integration | docker-compose.yml |
| 6 | SidecarChatClient (PHP) | SidecarChatClient.php |
| 7 | Wire into service provider | ClaudrielServiceProvider.php, ChatStreamController.php |
| 8 | End-to-end testing | — |
