# Sidecar Removal & Agent Subprocess Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the Docker sidecar with a Python subprocess using `claude-agent-sdk`, keeping the agentic loop while eliminating infrastructure overhead.

**Architecture:** PHP spawns a Python subprocess (`agent/main.py`) via `proc_open()`. Python runs the agentic loop using `claude-agent-sdk`, calling back to PHP internal API endpoints for Google operations. Communication is JSON-lines over stdout; PHP maps these to SSE for the browser.

**Tech Stack:** Python 3.11+ with `claude-agent-sdk` and `httpx`, PHP 8.4+ with `proc_open()`, HMAC-SHA256 for internal API auth.

**Spec:** `docs/superpowers/specs/2026-03-17-sidecar-removal-agent-subprocess-design.md`

---

## File Structure

### New Files

| File | Responsibility |
|---|---|
| `agent/main.py` | Python entrypoint — reads stdin JSON, runs agentic loop, writes JSON-lines to stdout |
| `agent/requirements.txt` | Python dependencies: `claude-agent-sdk`, `httpx` |
| `agent/tools/__init__.py` | Auto-registers all tool modules |
| `agent/tools/gmail_list.py` | Tool: list Gmail messages |
| `agent/tools/gmail_read.py` | Tool: read a specific Gmail message |
| `agent/tools/gmail_send.py` | Tool: send/reply to email |
| `agent/tools/calendar_list.py` | Tool: list calendar events |
| `agent/tools/calendar_create.py` | Tool: create calendar event |
| `agent/util/__init__.py` | Utility package |
| `agent/util/http.py` | HTTP client for calling PHP internal API |
| `src/Domain/Chat/SubprocessChatClient.php` | Spawns Python subprocess, reads JSON-lines, maps to callbacks |
| `src/Domain/Chat/InternalApiTokenGenerator.php` | Generates HMAC tokens for internal API auth |
| `src/Controller/InternalGoogleController.php` | Handles `/api/internal/gmail/*` and `/api/internal/calendar/*` |
| ~~`src/Middleware/InternalApiAuthMiddleware.php`~~ | ~~Removed — auth is inline in `InternalGoogleController::authenticate()`~~ |

### Modified Files

| File | Changes |
|---|---|
| `src/Controller/ChatStreamController.php` | Remove sidecar/anthropic dual-client logic, use SubprocessChatClient |
| `src/Domain/Chat/ChatSystemPromptBuilder.php` | Remove `hasToolAccess` conditional, tools always available |
| `src/Provider/ClaudrielServiceProvider.php` | Add internal API routes, register new services |
| `docker-compose.yml` | Remove sidecar service |
| `deploy.php` | Remove sidecar deploy tasks, add agent venv setup |
| `.env.example` | Remove sidecar vars, add `AGENT_INTERNAL_SECRET` |

### Deleted Files

| File | Reason |
|---|---|
| `docker/sidecar/` (entire directory) | Replaced by `agent/` subprocess |
| `src/Domain/Chat/SidecarChatClient.php` | Replaced by `SubprocessChatClient` |
| `src/Domain/Chat/AnthropicChatClient.php` | No fallback path |

---

## Task 1: HMAC Token Generator

**Files:**
- Create: `src/Domain/Chat/InternalApiTokenGenerator.php`
- Test: `tests/Unit/Domain/Chat/InternalApiTokenGeneratorTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InternalApiTokenGenerator::class)]
final class InternalApiTokenGeneratorTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long!!';

    #[Test]
    public function generate_returns_token_with_three_parts(): void
    {
        $generator = new InternalApiTokenGenerator(self::SECRET);
        $token = $generator->generate('account-123');

        $parts = explode(':', $token);
        $this->assertCount(3, $parts, 'Token must have format account_id:timestamp:signature');
    }

    #[Test]
    public function validate_accepts_valid_token(): void
    {
        $generator = new InternalApiTokenGenerator(self::SECRET);
        $token = $generator->generate('account-123');

        $result = $generator->validate($token);
        $this->assertSame('account-123', $result);
    }

    #[Test]
    public function validate_rejects_expired_token(): void
    {
        $generator = new InternalApiTokenGenerator(self::SECRET, ttlSeconds: 1);
        $token = $generator->generate('account-123');

        // Manually create an expired token (timestamp 600 seconds ago)
        $expiredTimestamp = time() - 600;
        $payload = "account-123:{$expiredTimestamp}";
        $signature = hash_hmac('sha256', $payload, self::SECRET);
        $expiredToken = "{$payload}:{$signature}";

        $this->assertNull($generator->validate($expiredToken));
    }

    #[Test]
    public function validate_rejects_tampered_token(): void
    {
        $generator = new InternalApiTokenGenerator(self::SECRET);
        $token = $generator->generate('account-123');

        // Tamper with the account_id
        $tampered = str_replace('account-123', 'account-456', $token);
        $this->assertNull($generator->validate($tampered));
    }

    #[Test]
    public function validate_rejects_wrong_secret(): void
    {
        $generator1 = new InternalApiTokenGenerator(self::SECRET);
        $generator2 = new InternalApiTokenGenerator('different-secret-also-32-bytes-long!!');

        $token = $generator1->generate('account-123');
        $this->assertNull($generator2->validate($token));
    }

    #[Test]
    public function validate_rejects_malformed_token(): void
    {
        $generator = new InternalApiTokenGenerator(self::SECRET);

        $this->assertNull($generator->validate(''));
        $this->assertNull($generator->validate('just-one-part'));
        $this->assertNull($generator->validate('two:parts'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Domain/Chat/InternalApiTokenGeneratorTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

final class InternalApiTokenGenerator
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds = 300,
    ) {}

    /**
     * Generate a short-lived HMAC token for internal API auth.
     *
     * Format: {account_id}:{timestamp}:{signature}
     */
    public function generate(string $accountId): string
    {
        $timestamp = time();
        $payload = "{$accountId}:{$timestamp}";
        $signature = hash_hmac('sha256', $payload, $this->secret);

        return "{$payload}:{$signature}";
    }

    /**
     * Validate an HMAC token and return the account_id, or null if invalid/expired.
     */
    public function validate(string $token): ?string
    {
        $parts = explode(':', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$accountId, $timestampStr, $signature] = $parts;

        $timestamp = (int) $timestampStr;
        if (time() - $timestamp > $this->ttlSeconds) {
            return null;
        }

        $expectedPayload = "{$accountId}:{$timestampStr}";
        $expectedSignature = hash_hmac('sha256', $expectedPayload, $this->secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return $accountId;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Domain/Chat/InternalApiTokenGeneratorTest.php`
Expected: 6 tests, 6 assertions, all PASS

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Chat/InternalApiTokenGenerator.php tests/Unit/Domain/Chat/InternalApiTokenGeneratorTest.php
git commit -m "feat: add InternalApiTokenGenerator for agent subprocess auth"
```

---

## Task 2: Python Agent Package — HTTP Utility & Main Entrypoint

**Files:**
- Create: `agent/requirements.txt`
- Create: `agent/util/__init__.py`
- Create: `agent/util/http.py`
- Create: `agent/tools/__init__.py`
- Create: `agent/main.py`

- [ ] **Step 1: Create requirements.txt**

```
claude-agent-sdk>=0.1.0
httpx>=0.27.0
```

- [ ] **Step 2: Create util/http.py — thin PHP API client**

```python
"""Thin HTTP client for calling Claudriel's internal PHP API."""

import httpx


class PhpApiClient:
    """Calls PHP internal API endpoints with HMAC auth."""

    def __init__(self, api_base: str, api_token: str, account_id: str) -> None:
        self._client = httpx.Client(
            base_url=api_base.rstrip("/"),
            headers={
                "Authorization": f"Bearer {api_token}",
                "X-Account-Id": account_id,
                "Content-Type": "application/json",
            },
            timeout=30.0,
        )

    def get(self, path: str, params: dict | None = None) -> dict:
        response = self._client.get(path, params=params)
        response.raise_for_status()
        return response.json()

    def post(self, path: str, json_data: dict | None = None) -> dict:
        response = self._client.post(path, json=json_data)
        response.raise_for_status()
        return response.json()

    def close(self) -> None:
        self._client.close()
```

- [ ] **Step 3: Create empty tools/__init__.py**

```python
"""Claudriel agent tools — each module exports a tool for the agentic loop."""
```

- [ ] **Step 4: Create main.py entrypoint**

```python
#!/usr/bin/env python3
"""Claudriel agent entrypoint.

Reads a JSON request from stdin, runs the claude-agent-sdk agentic loop
with registered tools, and writes JSON-lines events to stdout.

Usage:
    echo '{"messages": [...], "system": "...", ...}' | python agent/main.py
"""

import json
import sys

from claude_agent_sdk import Agent, AgentConfig

from tools import gmail_list, gmail_read, gmail_send, calendar_list, calendar_create
from util.http import PhpApiClient


def emit(event: str, **kwargs) -> None:
    """Write a JSON-line event to stdout."""
    line = json.dumps({"event": event, **kwargs}, ensure_ascii=False)
    print(line, flush=True)


def main() -> None:
    try:
        request = json.load(sys.stdin)
    except json.JSONDecodeError as e:
        print(json.dumps({"event": "error", "message": f"Invalid JSON input: {e}"}), flush=True)
        sys.exit(1)

    messages = request.get("messages", [])
    system_prompt = request.get("system", "")
    account_id = request.get("account_id", "")
    tenant_id = request.get("tenant_id", "")
    api_base = request.get("api_base", "http://localhost:8000")
    api_token = request.get("api_token", "")
    model = request.get("model", "claude-sonnet-4-6")

    api = PhpApiClient(api_base, api_token, account_id)

    try:
        # Register tools with API client context
        tools = [
            gmail_list.create_tool(api),
            gmail_read.create_tool(api),
            gmail_send.create_tool(api),
            calendar_list.create_tool(api),
            calendar_create.create_tool(api),
        ]

        config = AgentConfig(
            model=model,
            system_prompt=system_prompt,
            max_turns=25,
            tools=tools,
        )

        agent = Agent(config)

        # Stream the agentic loop
        for event in agent.stream(messages):
            if event.type == "text":
                emit("message", content=event.text)
            elif event.type == "tool_use":
                emit("tool_call", tool=event.tool_name, args=event.tool_input)
            elif event.type == "tool_result":
                emit("tool_result", tool=event.tool_name, result=event.result)

        emit("done")

    except Exception as e:
        print(f"Agent error: {e}", file=sys.stderr)
        emit("error", message=str(e))
        sys.exit(1)
    finally:
        api.close()


if __name__ == "__main__":
    main()
```

> **Note:** The exact `claude-agent-sdk` API (class names, method signatures) may differ from what's shown here. Before implementing, check the current SDK docs with `resolve-library-id` and `query-docs` for `claude-agent-sdk`. The structure and JSON-lines contract remain the same regardless of SDK specifics.

- [ ] **Step 5: Create util/__init__.py**

Empty file.

- [ ] **Step 6: Commit**

```bash
git add agent/
git commit -m "feat: add Python agent package with main entrypoint and HTTP utility"
```

---

## Task 3: Python Tool Implementations

**Files:**
- Create: `agent/tools/gmail_list.py`
- Create: `agent/tools/gmail_read.py`
- Create: `agent/tools/gmail_send.py`
- Create: `agent/tools/calendar_list.py`
- Create: `agent/tools/calendar_create.py`

- [ ] **Step 1: Create gmail_list.py**

```python
"""Tool: List Gmail messages."""

from claude_agent_sdk import Tool


def create_tool(api):
    """Create the gmail_list tool bound to the PHP API client."""

    def gmail_list(query: str = "is:unread", max_results: int = 10) -> dict:
        """List Gmail messages matching a query.

        Args:
            query: Gmail search query (default: "is:unread")
            max_results: Maximum number of messages to return (default: 10)
        """
        return api.get("/api/internal/gmail/list", params={"q": query, "max_results": max_results})

    return Tool.from_function(gmail_list)
```

- [ ] **Step 2: Create gmail_read.py**

```python
"""Tool: Read a specific Gmail message."""

from claude_agent_sdk import Tool


def create_tool(api):
    def gmail_read(message_id: str) -> dict:
        """Read a specific Gmail message by ID.

        Args:
            message_id: The Gmail message ID to read
        """
        return api.get(f"/api/internal/gmail/read/{message_id}")

    return Tool.from_function(gmail_read)
```

- [ ] **Step 3: Create gmail_send.py**

```python
"""Tool: Send or reply to an email."""

from claude_agent_sdk import Tool


def create_tool(api):
    def gmail_send(to: str, subject: str, body: str, reply_to_message_id: str = "") -> dict:
        """Send an email or reply to an existing message.

        Args:
            to: Recipient email address
            subject: Email subject line
            body: Email body text
            reply_to_message_id: If replying, the original message ID (optional)
        """
        payload = {"to": to, "subject": subject, "body": body}
        if reply_to_message_id:
            payload["reply_to_message_id"] = reply_to_message_id
        return api.post("/api/internal/gmail/send", json_data=payload)

    return Tool.from_function(gmail_send)
```

- [ ] **Step 4: Create calendar_list.py**

```python
"""Tool: List upcoming calendar events."""

from claude_agent_sdk import Tool


def create_tool(api):
    def calendar_list(days_ahead: int = 7, max_results: int = 20) -> dict:
        """List upcoming calendar events.

        Args:
            days_ahead: Number of days to look ahead (default: 7)
            max_results: Maximum number of events to return (default: 20)
        """
        return api.get("/api/internal/calendar/list", params={"days_ahead": days_ahead, "max_results": max_results})

    return Tool.from_function(calendar_list)
```

- [ ] **Step 5: Create calendar_create.py**

```python
"""Tool: Create a calendar event."""

from claude_agent_sdk import Tool


def create_tool(api):
    def calendar_create(title: str, start_time: str, end_time: str, description: str = "", attendees: str = "") -> dict:
        """Create a new calendar event.

        Args:
            title: Event title
            start_time: Start time in ISO 8601 format (e.g., "2026-03-18T09:00:00-04:00")
            end_time: End time in ISO 8601 format
            description: Optional event description
            attendees: Comma-separated email addresses of attendees (optional)
        """
        payload = {"title": title, "start_time": start_time, "end_time": end_time}
        if description:
            payload["description"] = description
        if attendees:
            payload["attendees"] = [a.strip() for a in attendees.split(",")]
        return api.post("/api/internal/calendar/create", json_data=payload)

    return Tool.from_function(calendar_create)
```

- [ ] **Step 6: Commit**

```bash
git add agent/tools/
git commit -m "feat: add Gmail and Calendar tool implementations for agent"
```

---

## Task 4: SubprocessChatClient

**Files:**
- Create: `src/Domain/Chat/SubprocessChatClient.php`
- Test: `tests/Unit/Domain/Chat/SubprocessChatClientTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\SubprocessChatClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SubprocessChatClient::class)]
final class SubprocessChatClientTest extends TestCase
{
    #[Test]
    public function stream_emits_tokens_and_done_from_json_lines(): void
    {
        // Use a PHP script that mimics the Python agent's JSON-lines output
        $script = sys_get_temp_dir() . '/mock_agent_' . uniqid() . '.php';
        file_put_contents($script, <<<'PHP'
        <?php
        echo json_encode(['event' => 'message', 'content' => 'Hello']) . "\n";
        echo json_encode(['event' => 'message', 'content' => ' world']) . "\n";
        echo json_encode(['event' => 'done']) . "\n";
        PHP);

        $client = new SubprocessChatClient(
            pythonBinary: PHP_BINARY,
            agentPath: $script,
            timeoutSeconds: 10,
        );

        $tokens = [];
        $doneResponse = null;
        $errors = [];

        $client->stream(
            systemPrompt: 'test',
            messages: [],
            accountId: 'acc-1',
            tenantId: 'tenant-1',
            apiBase: 'http://localhost',
            apiToken: 'token',
            onToken: function (string $token) use (&$tokens) { $tokens[] = $token; },
            onDone: function (string $full) use (&$doneResponse) { $doneResponse = $full; },
            onError: function (string $err) use (&$errors) { $errors[] = $err; },
        );

        $this->assertSame(['Hello', ' world'], $tokens);
        $this->assertSame('Hello world', $doneResponse);
        $this->assertSame([], $errors);

        unlink($script);
    }

    #[Test]
    public function stream_calls_on_error_for_nonzero_exit(): void
    {
        $script = sys_get_temp_dir() . '/mock_agent_fail_' . uniqid() . '.php';
        file_put_contents($script, <<<'PHP'
        <?php
        fwrite(STDERR, "Something went wrong\n");
        exit(1);
        PHP);

        $client = new SubprocessChatClient(
            pythonBinary: PHP_BINARY,
            agentPath: $script,
            timeoutSeconds: 10,
        );

        $errors = [];

        $client->stream(
            systemPrompt: 'test',
            messages: [],
            accountId: 'acc-1',
            tenantId: 'tenant-1',
            apiBase: 'http://localhost',
            apiToken: 'token',
            onToken: function (string $token) {},
            onDone: function (string $full) {},
            onError: function (string $err) use (&$errors) { $errors[] = $err; },
        );

        $this->assertNotEmpty($errors);

        unlink($script);
    }

    #[Test]
    public function stream_calls_on_progress_for_tool_events(): void
    {
        $script = sys_get_temp_dir() . '/mock_agent_tools_' . uniqid() . '.php';
        file_put_contents($script, <<<'PHP'
        <?php
        echo json_encode(['event' => 'tool_call', 'tool' => 'gmail_list', 'args' => ['query' => 'is:unread']]) . "\n";
        echo json_encode(['event' => 'tool_result', 'tool' => 'gmail_list', 'result' => ['count' => 3]]) . "\n";
        echo json_encode(['event' => 'message', 'content' => 'Found 3 emails']) . "\n";
        echo json_encode(['event' => 'done']) . "\n";
        PHP);

        $client = new SubprocessChatClient(
            pythonBinary: PHP_BINARY,
            agentPath: $script,
            timeoutSeconds: 10,
        );

        $progressEvents = [];

        $client->stream(
            systemPrompt: 'test',
            messages: [],
            accountId: 'acc-1',
            tenantId: 'tenant-1',
            apiBase: 'http://localhost',
            apiToken: 'token',
            onToken: function (string $token) {},
            onDone: function (string $full) {},
            onError: function (string $err) {},
            onProgress: function (array $payload) use (&$progressEvents) { $progressEvents[] = $payload; },
        );

        $this->assertCount(2, $progressEvents);
        $this->assertSame('tool_call', $progressEvents[0]['phase']);
        $this->assertSame('gmail_list', $progressEvents[0]['tool']);
        $this->assertSame('tool_result', $progressEvents[1]['phase']);

        unlink($script);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Domain/Chat/SubprocessChatClientTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write SubprocessChatClient implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

use Closure;

final class SubprocessChatClient
{
    public function __construct(
        private readonly string $pythonBinary,
        private readonly string $agentPath,
        private readonly int $timeoutSeconds = 120,
    ) {}

    /**
     * Run the Python agent subprocess and stream results via callbacks.
     *
     * @param  Closure(string): void  $onToken
     * @param  Closure(string): void  $onDone
     * @param  Closure(string): void  $onError
     * @param  Closure(array): void|null  $onProgress
     */
    public function stream(
        string $systemPrompt,
        array $messages,
        string $accountId,
        string $tenantId,
        string $apiBase,
        string $apiToken,
        Closure $onToken,
        Closure $onDone,
        Closure $onError,
        ?Closure $onProgress = null,
        ?string $model = null,
    ): void {
        $request = json_encode([
            'messages' => $messages,
            'system' => $systemPrompt,
            'account_id' => $accountId,
            'tenant_id' => $tenantId,
            'api_base' => $apiBase,
            'api_token' => $apiToken,
            'model' => $model ?? ($_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-6'),
        ], JSON_THROW_ON_ERROR);

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'r'],  // stdout
            2 => ['pipe', 'r'],  // stderr
        ];

        $process = proc_open(
            [$this->pythonBinary, $this->agentPath],
            $descriptors,
            $pipes,
        );

        if (! is_resource($process)) {
            $onError('Failed to start agent subprocess');

            return;
        }

        // Write request to stdin and close
        fwrite($pipes[0], $request);
        fclose($pipes[0]);

        // Read stdout line by line with timeout
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $fullResponse = '';
        $startTime = time();
        $receivedDone = false;

        while (! $receivedDone) {
            if (time() - $startTime > $this->timeoutSeconds) {
                proc_terminate($process);
                $onError('Agent subprocess timed out');

                break;
            }

            if (connection_aborted()) {
                proc_terminate($process);

                break;
            }

            // Block until stdout has data or 10ms elapses
            $read = [$pipes[1]];
            $write = $except = [];
            if (stream_select($read, $write, $except, 0, 10_000) === 0) {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }

                continue;
            }

            $line = fgets($pipes[1]);
            if ($line === false) {
                $status = proc_get_status($process);
                if (! $status['running']) {
                    break;
                }

                continue;
            }

            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $event = json_decode($line, true);
            if (! is_array($event)) {
                continue;
            }

            $eventType = $event['event'] ?? '';

            match ($eventType) {
                'message' => (function () use ($event, $onToken, &$fullResponse) {
                    $content = $event['content'] ?? '';
                    $fullResponse .= $content;
                    $onToken($content);
                })(),
                'tool_call' => $onProgress !== null ? $onProgress([
                    'phase' => 'tool_call',
                    'tool' => $event['tool'] ?? '',
                    'summary' => 'Using ' . ($event['tool'] ?? 'tool'),
                    'level' => 'info',
                ]) : null,
                'tool_result' => $onProgress !== null ? $onProgress([
                    'phase' => 'tool_result',
                    'tool' => $event['tool'] ?? '',
                    'summary' => 'Received result from ' . ($event['tool'] ?? 'tool'),
                    'level' => 'info',
                ]) : null,
                'error' => $onError($event['message'] ?? 'Unknown agent error'),
                'done' => $receivedDone = true,
                default => null,
            };
        }

        // Capture stderr
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($stderr !== '' && $stderr !== false) {
            error_log('[Agent stderr] ' . $stderr);
        }

        if ($exitCode !== 0 && ! $receivedDone) {
            $onError('Agent subprocess exited with code ' . $exitCode);

            return;
        }

        if ($receivedDone) {
            $onDone($fullResponse);
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit tests/Unit/Domain/Chat/SubprocessChatClientTest.php`
Expected: 3 tests, all PASS

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Chat/SubprocessChatClient.php tests/Unit/Domain/Chat/SubprocessChatClientTest.php
git commit -m "feat: add SubprocessChatClient for Python agent communication"
```

---

## Task 5: Internal Google API Controller

**Files:**
- Create: `src/Controller/InternalGoogleController.php`

Auth is handled inline in the controller's `authenticate()` method (no separate middleware — keeps it simple and avoids dead code).

- [ ] **Step 1: Create InternalGoogleController**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Middleware\InternalApiAuthMiddleware;
use Claudriel\Support\GoogleTokenManagerInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalGoogleController
{
    public function __construct(
        private readonly GoogleTokenManagerInterface $tokenManager,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
    ) {}

    public function gmailList(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $q = $query['q'] ?? 'is:unread';
        $maxResults = min((int) ($query['max_results'] ?? 10), 50);

        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages?'
            . http_build_query(['q' => $q, 'maxResults' => $maxResults]);

        $response = $this->googleApiGet($url, $accessToken);

        return $this->jsonResponse($response);
    }

    public function gmailRead(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $messageId = $params['id'] ?? '';
        if ($messageId === '') {
            return $this->jsonError('Message ID required', 400);
        }

        $url = "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}?format=full";
        $response = $this->googleApiGet($url, $accessToken);

        return $this->jsonResponse($response);
    }

    public function gmailSend(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $to = $body['to'] ?? '';
        $subject = $body['subject'] ?? '';
        $bodyText = $body['body'] ?? '';

        if ($to === '' || $subject === '') {
            return $this->jsonError('to and subject are required', 400);
        }

        $rawMessage = "To: {$to}\r\nSubject: {$subject}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$bodyText}";
        $encoded = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';
        $response = $this->googleApiPost($url, $accessToken, ['raw' => $encoded]);

        return $this->jsonResponse($response);
    }

    public function calendarList(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $daysAhead = min((int) ($query['days_ahead'] ?? 7), 30);
        $maxResults = min((int) ($query['max_results'] ?? 20), 100);

        $timeMin = (new \DateTimeImmutable)->format(\DateTimeInterface::RFC3339);
        $timeMax = (new \DateTimeImmutable("+{$daysAhead} days"))->format(\DateTimeInterface::RFC3339);

        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events?'
            . http_build_query([
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
                'maxResults' => $maxResults,
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
            ]);

        $response = $this->googleApiGet($url, $accessToken);

        return $this->jsonResponse($response);
    }

    public function calendarCreate(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $eventData = [
            'summary' => $body['title'] ?? '',
            'start' => ['dateTime' => $body['start_time'] ?? ''],
            'end' => ['dateTime' => $body['end_time'] ?? ''],
        ];

        if (! empty($body['description'])) {
            $eventData['description'] = $body['description'];
        }

        if (! empty($body['attendees'])) {
            $eventData['attendees'] = array_map(
                static fn (string $email) => ['email' => $email],
                $body['attendees'],
            );
        }

        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
        $response = $this->googleApiPost($url, $accessToken, $eventData);

        return $this->jsonResponse($response);
    }

    private function authenticate(mixed $httpRequest): ?string
    {
        $auth = '';
        if ($httpRequest instanceof \Symfony\Component\HttpFoundation\Request) {
            $auth = $httpRequest->headers->get('Authorization', '');
        }

        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return $this->apiTokenGenerator->validate(substr($auth, 7));
    }

    private function googleApiGet(string $url, string $accessToken): array
    {
        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: Bearer {$accessToken}\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['error' => 'Google API request failed'];
        }

        return json_decode($response, true) ?? ['error' => 'Invalid Google API response'];
    }

    private function googleApiPost(string $url, string $accessToken, array $data): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$accessToken}\r\nContent-Type: application/json\r\n",
                'content' => json_encode($data, JSON_THROW_ON_ERROR),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['error' => 'Google API request failed'];
        }

        return json_decode($response, true) ?? ['error' => 'Invalid Google API response'];
    }

    private function getRequestBody(mixed $httpRequest): ?array
    {
        if (! $httpRequest instanceof \Symfony\Component\HttpFoundation\Request) {
            return null;
        }

        $content = $httpRequest->getContent();
        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private function jsonResponse(array $data): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function jsonError(string $message, int $statusCode): SsrResponse
    {
        return new SsrResponse(
            content: json_encode(['error' => $message], JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add src/Controller/InternalGoogleController.php
git commit -m "feat: add internal Google API controller with inline HMAC auth"
```

---

## Task 6: Register Internal API Routes

**Files:**
- Modify: `src/Provider/ClaudrielServiceProvider.php`

- [ ] **Step 1: Add internal API route registrations**

In the `boot()` method of `ClaudrielServiceProvider`, after the existing `claudriel.api.ingest` route, add:

```php
// Internal API routes (agent subprocess → PHP)
$internalGmailListRoute = RouteBuilder::create('/api/internal/gmail/list')
    ->controller(InternalGoogleController::class.'::gmailList')
    ->allowAll()
    ->methods('GET')
    ->build();
$internalGmailListRoute->setOption('_csrf', false);
$router->addRoute('claudriel.internal.gmail.list', $internalGmailListRoute);

$internalGmailReadRoute = RouteBuilder::create('/api/internal/gmail/read/{id}')
    ->controller(InternalGoogleController::class.'::gmailRead')
    ->allowAll()
    ->methods('GET')
    ->build();
$internalGmailReadRoute->setOption('_csrf', false);
$router->addRoute('claudriel.internal.gmail.read', $internalGmailReadRoute);

$internalGmailSendRoute = RouteBuilder::create('/api/internal/gmail/send')
    ->controller(InternalGoogleController::class.'::gmailSend')
    ->allowAll()
    ->methods('POST')
    ->build();
$internalGmailSendRoute->setOption('_csrf', false);
$router->addRoute('claudriel.internal.gmail.send', $internalGmailSendRoute);

$internalCalendarListRoute = RouteBuilder::create('/api/internal/calendar/list')
    ->controller(InternalGoogleController::class.'::calendarList')
    ->allowAll()
    ->methods('GET')
    ->build();
$internalCalendarListRoute->setOption('_csrf', false);
$router->addRoute('claudriel.internal.calendar.list', $internalCalendarListRoute);

$internalCalendarCreateRoute = RouteBuilder::create('/api/internal/calendar/create')
    ->controller(InternalGoogleController::class.'::calendarCreate')
    ->allowAll()
    ->methods('POST')
    ->build();
$internalCalendarCreateRoute->setOption('_csrf', false);
$router->addRoute('claudriel.internal.calendar.create', $internalCalendarCreateRoute);
```

Add the use statement at the top of the file:

```php
use Claudriel\Controller\InternalGoogleController;
```

- [ ] **Step 2: Register InternalApiTokenGenerator and InternalGoogleController in `register()`**

In the `register()` method, add service registrations:

```php
$this->container->singleton(InternalApiTokenGenerator::class, function () {
    $secret = $_ENV['AGENT_INTERNAL_SECRET'] ?? getenv('AGENT_INTERNAL_SECRET') ?: '';
    return new InternalApiTokenGenerator($secret);
});

$this->container->singleton(InternalGoogleController::class, function () {
    return new InternalGoogleController(
        $this->container->get(GoogleTokenManagerInterface::class),
        $this->container->get(InternalApiTokenGenerator::class),
    );
});
```

- [ ] **Step 3: Commit**

```bash
git add src/Provider/ClaudrielServiceProvider.php
git commit -m "feat: register internal Google API routes and services"
```

---

## Task 7: Rewire ChatStreamController

**Files:**
- Modify: `src/Controller/ChatStreamController.php`
- Modify: `src/Domain/Chat/ChatSystemPromptBuilder.php`

- [ ] **Step 1: Simplify ChatSystemPromptBuilder — remove hasToolAccess conditional**

In `ChatSystemPromptBuilder.php`, change the `build()` method signature to remove `hasToolAccess`:

```php
public function build(string $tenantId = 'default', ?string $activeWorkspace = null, ?TimeSnapshot $snapshot = null): string
```

Remove the `$hasToolAccess` parameter from the call on line 55:

```php
$parts[] = $this->buildInstructions();
```

Rewrite `buildInstructions()` to always include tool capabilities (remove the `if (! $hasToolAccess)` branch entirely):

```php
private function buildInstructions(): string
{
    $ingestUrl = getenv('CLAUDRIEL_INGEST_URL') ?: 'http://caddy/api/ingest';
    $apiKey = $_ENV['CLAUDRIEL_API_KEY'] ?? getenv('CLAUDRIEL_API_KEY') ?: '';

    return <<<INSTRUCTIONS
# Instructions

You are Claudriel, an AI personal operations assistant. You are responding via the Claudriel web dashboard. Be warm, concise, and proactive. You have access to the user's commitments, events, and personal context shown above. Help them stay on track.

When the user asks about creating, updating, listing, or using a "workspace", interpret that as a Claudriel workspace unless they explicitly say otherwise. Do not drift into generic interpretations like git worktrees, project folders, Notion workspaces, or dev environments unless the user clearly asks for one of those.

If the user asks to create a workspace and key details are missing, ask only for the missing Claudriel workspace details, starting with the workspace name and then an optional description or repo link if relevant. If enough information is already present, respond as though you can create the Claudriel workspace directly.

When the user asks for a "worktree", interpret that as a git worktree by default, not a Claudriel workspace. If details are missing, ask only for the missing git worktree details rather than asking the user to choose between unrelated meanings.

For schedule changes involving recurring events, default to changing only the single occurrence the user mentioned unless they explicitly say to modify or delete the whole series. If series-wide intent is unclear, do not assume it.

## Capabilities

You have access to Gmail and Google Calendar tools. You can check email, read messages, send replies, list calendar events, and create new events. When the user asks you to check email or calendar, use your tools directly.

## Data Ingestion

When you find relevant items via Gmail or Calendar, ingest them into the Claudriel system using your tools. For each relevant item, post it to the ingestion endpoint:

POST {$ingestUrl}
Authorization: Bearer {$apiKey}
Content-Type: application/json

### Ingestion payload formats:

**Email events** (source: "gmail", type: "message.received"):
{"source":"gmail","type":"message.received","payload":{"subject":"<subject>","from_email":"<email>","from_name":"<name>","body":"<snippet or summary>"}}

**Calendar events** (source: "google-calendar", type: "calendar.event"):
{"source":"google-calendar","type":"calendar.event","payload":{"event_id":"<stable event id>","calendar_id":"<calendar id>","title":"<event title>","start_time":"2026-03-13T09:00:00-04:00","end_time":"2026-03-13T10:00:00-04:00","from_name":"<organizer>","from_email":"<organizer email>","body":"<description or location>"}}

**Commitment detection** (source: "claude-agent", type: "commitment.detected"):
{"source":"claude-agent","type":"commitment.detected","payload":{"title":"<what was committed to>","confidence":0.8,"due_date":"2026-03-15","person_email":"<who>","person_name":"<who>"}}

Always ingest data silently (don't show raw API output to the user), then summarize what you found in a friendly way. After ingesting, the Day Brief on the dashboard will update automatically.
INSTRUCTIONS;
}
```

- [ ] **Step 2: Rewrite ChatStreamController::streamTokens() and its call site**

Replace the `streamTokens()` method. Remove all sidecar/anthropic dual-client logic. Replace with SubprocessChatClient.

**Also update the call site** in the existing `stream()` method's `StreamedResponse` closure (around line 110) to pass `$account` through to `streamTokens()`:

```php
$this->streamTokens($sessionUuid, $apiKey, $msgStorage, $tenantId, $workspaceUuid, $snapshot, $account);
```

New `streamTokens()` implementation:

```php
private function streamTokens(string $sessionUuid, string $apiKey, mixed $msgStorage, string $tenantId, ?string $workspaceUuid = null, ?TimeSnapshot $snapshot = null, mixed $account = null): void
{
    $snapshot ??= (new TemporalContextFactory($this->entityTypeManager))->snapshotForInteraction(
        scopeKey: 'chat-stream:'.$sessionUuid,
        tenantId: $tenantId,
        workspaceUuid: $workspaceUuid,
    );
    echo "retry: 3000\n\n";
    if (ob_get_level() > 0) {
        ob_flush();
    }
    flush();

    // Load conversation history
    $allMsgIds = $msgStorage->getQuery()->execute();
    $allMessages = $msgStorage->loadMultiple($allMsgIds);
    $sessionMessages = [];
    foreach ($allMessages as $msg) {
        if ($msg->get('session_uuid') === $sessionUuid && $this->resolveMessageTenantId($msg) === $tenantId) {
            $sessionMessages[] = $msg;
        }
    }
    usort($sessionMessages, fn ($a, $b) => ($a->get('created_at') ?? '') <=> ($b->get('created_at') ?? ''));

    $apiMessages = array_map(
        fn ($m) => ['role' => $m->get('role'), 'content' => $m->get('content')],
        $sessionMessages,
    );

    // Build system prompt (tools always available)
    $projectRoot = $this->resolveProjectRoot();
    $promptBuilder = $this->buildPromptBuilder($projectRoot);
    $activeWorkspace = $workspaceUuid !== null ? $this->findWorkspaceByUuid($workspaceUuid, $tenantId)?->get('name') : null;
    $systemPrompt = $promptBuilder->build($tenantId, activeWorkspace: is_string($activeWorkspace) ? $activeWorkspace : null, snapshot: $snapshot);

    $onToken = function (string $token): void {
        $this->emitSseEvent('chat-token', ['token' => $token]);
    };

    $onDone = function (string $fullResponse) use ($sessionUuid, $msgStorage, $tenantId, $workspaceUuid, $snapshot): void {
        $assistantMsg = new ChatMessage([
            'uuid' => $this->generateUuid(),
            'session_uuid' => $sessionUuid,
            'role' => 'assistant',
            'content' => $fullResponse,
            'created_at' => $snapshot->utc()->format('c'),
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceUuid,
        ]);
        $msgStorage->save($assistantMsg);

        $this->emitSseEvent('chat-done', ['done' => true, 'full_response' => $fullResponse]);
    };

    $onError = function (string $error): void {
        $this->emitSseEvent('chat-error', ['error' => $error]);
    };

    $onProgress = function (array $payload): void {
        $normalized = $this->normalizeProgressPayload($payload);
        if ($normalized === null) {
            return;
        }

        $this->emitSseEvent('chat-progress', $normalized);
    };

    // Generate internal API token for agent subprocess.
    // accountId is the user's entity ID (for Google token lookup).
    // tenantId is the tenant scope (for data isolation).
    // The HMAC token encodes accountId so GoogleTokenManager can look up the right OAuth token.
    $accountId = is_object($account) && method_exists($account, 'id') ? (string) $account->id() : $tenantId;
    $secret = $_ENV['AGENT_INTERNAL_SECRET'] ?? getenv('AGENT_INTERNAL_SECRET') ?: '';
    $tokenGenerator = new InternalApiTokenGenerator($secret);
    $apiToken = $tokenGenerator->generate($accountId);

    $agentPath = $_ENV['AGENT_PATH'] ?? getenv('AGENT_PATH') ?: $projectRoot.'/agent/main.py';
    $pythonBinary = $_ENV['AGENT_VENV'] ?? getenv('AGENT_VENV') ?: $projectRoot.'/agent/.venv';
    $pythonBinary .= '/bin/python';

    $apiBase = $_ENV['CLAUDRIEL_API_URL'] ?? getenv('CLAUDRIEL_API_URL') ?: 'http://localhost:8088';

    $this->emitSseEvent('chat-progress', [
        'phase' => 'prepare',
        'summary' => 'Starting agent',
        'level' => 'info',
    ]);

    $client = $this->createSubprocessClient($pythonBinary, $agentPath);
    $client->stream(
        systemPrompt: $systemPrompt,
        messages: $apiMessages,
        accountId: $accountId,
        tenantId: $tenantId,
        apiBase: $apiBase,
        apiToken: $apiToken,
        onToken: $onToken,
        onDone: $onDone,
        onError: $onError,
        onProgress: $onProgress,
    );
}
```

- [ ] **Step 3: Update constructor and factory methods**

Replace constructor:

```php
public function __construct(
    private readonly EntityTypeManager $entityTypeManager,
    private readonly mixed $subprocessClientFactory = null,
    private readonly ?IssueOrchestrator $orchestrator = null,
) {}
```

Replace `createSidecarClient()` and `createAnthropicClient()` with:

```php
private function createSubprocessClient(string $pythonBinary, string $agentPath): SubprocessChatClient
{
    if (is_callable($this->subprocessClientFactory)) {
        return ($this->subprocessClientFactory)($pythonBinary, $agentPath);
    }

    return new SubprocessChatClient($pythonBinary, $agentPath);
}
```

Update imports — remove `SidecarChatClient` and `AnthropicChatClient`, add:

```php
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\Chat\SubprocessChatClient;
```

Remove the `use Claudriel\Domain\Chat\SidecarChatClient;` and `use Claudriel\Domain\Chat\AnthropicChatClient;` imports.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/ChatStreamController.php src/Domain/Chat/ChatSystemPromptBuilder.php
git commit -m "refactor: rewire ChatStreamController to use SubprocessChatClient"
```

---

## Task 8: Remove Sidecar Infrastructure

**Files:**
- Delete: `docker/sidecar/` (entire directory)
- Delete: `src/Domain/Chat/SidecarChatClient.php`
- Delete: `src/Domain/Chat/AnthropicChatClient.php`
- Modify: `docker-compose.yml`
- Modify: `deploy.php`
- Modify: `.env.example`

- [ ] **Step 1: Delete sidecar files**

```bash
rm -rf docker/sidecar/
rm src/Domain/Chat/SidecarChatClient.php
rm src/Domain/Chat/AnthropicChatClient.php
```

- [ ] **Step 2: Update docker-compose.yml**

Remove the entire `sidecar` service block and the `depends_on: sidecar` from the `php` service. Remove sidecar env vars from `php` service. The resulting file:

```yaml
services:
  caddy:
    image: caddy:2-alpine
    ports:
      - "${PORT:-9889}:80"
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      - ./public:/srv/public:ro
    depends_on:
      - php

  php:
    build: docker/php
    volumes:
      - ./public:/srv/public:ro
      - ./src:/srv/src:ro
      - ./templates:/srv/templates:ro
      - ./config:/srv/config:ro
      - ./vendor:/srv/vendor:ro
      - ./agent:/srv/agent:ro
      - ${CLAUDRIEL_DATA:-/home/jones/claudriel}:/srv/data
      - /home/jones/dev/waaseyaa/packages:/home/jones/dev/waaseyaa/packages:ro
    environment:
      - APP_ENV=dev
      - CLAUDRIEL_API_KEY=${CLAUDRIEL_API_KEY}
      - ANTHROPIC_API_KEY=${ANTHROPIC_API_KEY}
      - ANTHROPIC_MODEL=${ANTHROPIC_MODEL:-claude-sonnet-4-6}
      - AGENT_INTERNAL_SECRET=${AGENT_INTERNAL_SECRET}
      - AGENT_VENV=/srv/agent/.venv
      - AGENT_PATH=/srv/agent/main.py
```

- [ ] **Step 3: Update deploy.php**

Remove `deploy:sidecar_dir` task, `sidecar:deploy` task, and sidecar health check from `deploy:validate`. Remove them from the deploy flow array. Add agent venv setup task:

```php
desc('Set up Python agent virtualenv');
task('agent:setup', function (): void {
    run('cd {{release_path}} && python3.11 -m venv agent/.venv 2>/dev/null || python3 -m venv agent/.venv');
    run('{{release_path}}/agent/.venv/bin/pip install -q -r {{release_path}}/agent/requirements.txt');
});
```

Update the deploy flow to replace sidecar tasks:

```php
task('deploy', [
    'deploy:info',
    'deploy:setup',
    'deploy:lock',
    'deploy:release',
    'deploy:upload',
    'deploy:runtime_dirs',
    'deploy:shared',
    'deploy:writable',
    'deploy:copy_caddyfile',
    'deploy:symlink',
    'deploy:clear_cache',
    'agent:setup',
    'caddy:reload',
    'php-fpm:reload',
    'deploy:validate',
    'deploy:unlock',
    'deploy:cleanup',
]);
```

Update `deploy:validate` to remove sidecar health check — keep only the public endpoint validation.

- [ ] **Step 4: Update .env.example**

Remove sidecar variables, add agent variables:

```
# Claudriel Configuration
# Copy to .env and fill in values

# API authentication key for ingestion endpoint
CLAUDRIEL_API_KEY=change-me-to-a-random-string

# API base URL (default for local development)
CLAUDRIEL_API_URL=http://localhost:8088

# Anthropic API key (for chat interface)
ANTHROPIC_API_KEY=sk-ant-...

# Anthropic API model ID
ANTHROPIC_MODEL=claude-sonnet-4-6

# Agent subprocess internal API auth (min 32 random bytes)
AGENT_INTERNAL_SECRET=change-me-to-a-random-string-at-least-32-bytes

# GitHub Actions verification variables
GITHUB_REPOSITORY=jonesrussell/claudriel
GITHUB_TOKEN=

# Google OAuth (Gmail, Calendar, etc.)
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=https://claudriel.northcloud.one/auth/google/callback

# Optional deploy/runtime environment
CLAUDRIEL_ENV=production
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "refactor: remove Docker sidecar, update deploy and config for agent subprocess"
```

---

## Task 9: Verify & Smoke Test

- [ ] **Step 1: Run all existing tests to verify nothing is broken**

Run: `cd /home/fsd42/dev/claudriel && ./vendor/bin/phpunit`
Expected: All tests pass (some tests referencing SidecarChatClient or AnthropicChatClient may need updating)

- [ ] **Step 2: Fix any broken tests**

Search for test files referencing removed classes and update them:

```bash
grep -r "SidecarChatClient\|AnthropicChatClient" tests/
```

Update or remove any tests that reference the deleted classes.

- [ ] **Step 3: Set up agent virtualenv locally**

```bash
cd /home/fsd42/dev/claudriel
python3.11 -m venv agent/.venv
agent/.venv/bin/pip install -r agent/requirements.txt
```

- [ ] **Step 4: Test Python agent in isolation**

```bash
echo '{"messages":[{"role":"user","content":"Hello"}],"system":"test","account_id":"1","tenant_id":"default","api_base":"http://localhost:8088","api_token":"test"}' | agent/.venv/bin/python agent/main.py
```

Expected: JSON-lines output with message events and a done event.

- [ ] **Step 5: Verify docker-compose is valid**

```bash
cd /home/fsd42/dev/claudriel && docker compose config --quiet
```

Expected: No errors

- [ ] **Step 6: Add agent/.venv to .gitignore**

```bash
echo 'agent/.venv/' >> .gitignore
```

- [ ] **Step 7: Final commit**

```bash
git add -A
git commit -m "chore: verify tests, add .gitignore for agent venv"
```
