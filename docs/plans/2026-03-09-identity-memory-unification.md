# Identity & Memory Unification — Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Unify Claudriel as the single identity, memory, and MCP server, retiring the Python claudia-memory daemon.

**Architecture:** Claudriel PHP app owns all memory via existing entities (McEvent, Commitment, Person, etc.). It exposes MCP tools over HTTP (Streamable HTTP transport, JSON-RPC 2.0) so Claude Code can read/write memory. Identity lives in a versioned `CLAUDRIEL.md` file served via API. Skills split into PHP (computational) and prompt templates (behavioral).

**Tech Stack:** PHP 8.3, Waaseyaa framework (entity, entity-storage, routing, foundation), Symfony HttpFoundation, PHPUnit, SQLite

**Design doc:** `docs/plans/2026-03-09-identity-memory-unification-design.md`

---

## Slice 1: MCP Foundation + `memory.briefing`

### Task 1.1: McpToolInterface

**Files:**
- Create: `src/Mcp/McpToolInterface.php`
- Test: `tests/Unit/Mcp/McpToolInterfaceTest.php`

**Step 1: Create the interface**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Mcp;

interface McpToolInterface
{
    public function name(): string;

    public function description(): string;

    /** @return array<string, mixed> JSON Schema for input */
    public function inputSchema(): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed> MCP content response
     */
    public function handle(array $arguments, string $accountId): array;
}
```

**Step 2: Commit**

```bash
git add src/Mcp/McpToolInterface.php
git commit -m "feat(mcp): add McpToolInterface contract"
```

---

### Task 1.2: McpRouter

**Files:**
- Create: `src/Mcp/McpRouter.php`
- Create: `tests/Unit/Mcp/McpRouterTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Mcp;

use Claudriel\Mcp\McpRouter;
use Claudriel\Mcp\McpToolInterface;
use PHPUnit\Framework\TestCase;

final class McpRouterTest extends TestCase
{
    private McpRouter $router;

    protected function setUp(): void
    {
        $this->router = new McpRouter();
    }

    public function testListToolsReturnsEmptyWhenNoToolsRegistered(): void
    {
        $result = $this->router->handleRequest([
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/list',
            'params' => [],
        ]);

        self::assertSame('2.0', $result['jsonrpc']);
        self::assertSame('1', $result['id']);
        self::assertSame([], $result['result']['tools']);
    }

    public function testListToolsReturnsRegisteredTools(): void
    {
        $tool = $this->createMock(McpToolInterface::class);
        $tool->method('name')->willReturn('test.tool');
        $tool->method('description')->willReturn('A test tool');
        $tool->method('inputSchema')->willReturn([
            'type' => 'object',
            'properties' => ['query' => ['type' => 'string']],
        ]);

        $this->router->registerTool($tool);

        $result = $this->router->handleRequest([
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'tools/list',
            'params' => [],
        ]);

        self::assertCount(1, $result['result']['tools']);
        self::assertSame('test.tool', $result['result']['tools'][0]['name']);
        self::assertSame('A test tool', $result['result']['tools'][0]['description']);
    }

    public function testCallToolReturnsResult(): void
    {
        $tool = $this->createMock(McpToolInterface::class);
        $tool->method('name')->willReturn('test.tool');
        $tool->method('handle')->with(['query' => 'hello'], 'acc-1')->willReturn([
            ['type' => 'text', 'text' => 'response'],
        ]);

        $this->router->registerTool($tool);

        $result = $this->router->handleRequest([
            'jsonrpc' => '2.0',
            'id' => '2',
            'method' => 'tools/call',
            'params' => ['name' => 'test.tool', 'arguments' => ['query' => 'hello']],
        ], 'acc-1');

        self::assertSame('2.0', $result['jsonrpc']);
        self::assertSame('2', $result['id']);
        self::assertSame([['type' => 'text', 'text' => 'response']], $result['result']['content']);
    }

    public function testCallUnknownToolReturnsError(): void
    {
        $result = $this->router->handleRequest([
            'jsonrpc' => '2.0',
            'id' => '3',
            'method' => 'tools/call',
            'params' => ['name' => 'nonexistent', 'arguments' => []],
        ], 'acc-1');

        self::assertArrayHasKey('error', $result);
        self::assertSame(-32601, $result['error']['code']);
    }

    public function testInitializeReturnsServerInfo(): void
    {
        $result = $this->router->handleRequest([
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]);

        self::assertSame('2.0', $result['jsonrpc']);
        self::assertSame('Claudriel', $result['result']['serverInfo']['name']);
        self::assertArrayHasKey('tools', $result['result']['capabilities']);
    }

    public function testUnknownMethodReturnsError(): void
    {
        $result = $this->router->handleRequest([
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'unknown/method',
            'params' => [],
        ]);

        self::assertArrayHasKey('error', $result);
        self::assertSame(-32601, $result['error']['code']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Mcp/McpRouterTest.php`
Expected: FAIL (class McpRouter not found)

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Mcp;

final class McpRouter
{
    /** @var array<string, McpToolInterface> */
    private array $tools = [];

    public function registerTool(McpToolInterface $tool): void
    {
        $this->tools[$tool->name()] = $tool;
    }

    /**
     * @param array<string, mixed> $request JSON-RPC request
     * @return array<string, mixed> JSON-RPC response
     */
    public function handleRequest(array $request, string $accountId = 'default'): array
    {
        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];

        return match ($method) {
            'initialize' => $this->handleInitialize($id, $params),
            'tools/list' => $this->handleToolsList($id),
            'tools/call' => $this->handleToolsCall($id, $params, $accountId),
            default => $this->errorResponse($id, -32601, "Method not found: {$method}"),
        };
    }

    /** @return array<string, mixed> */
    private function handleInitialize(mixed $id, array $params): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [
                    'tools' => ['listChanged' => false],
                ],
                'serverInfo' => [
                    'name' => 'Claudriel',
                    'version' => '0.5.0',
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function handleToolsList(mixed $id): array
    {
        $tools = [];
        foreach ($this->tools as $tool) {
            $tools[] = [
                'name' => $tool->name(),
                'description' => $tool->description(),
                'inputSchema' => $tool->inputSchema(),
            ];
        }

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $tools],
        ];
    }

    /** @return array<string, mixed> */
    private function handleToolsCall(mixed $id, array $params, string $accountId): array
    {
        $toolName = $params['name'] ?? '';
        $arguments = $params['arguments'] ?? [];

        if (!isset($this->tools[$toolName])) {
            return $this->errorResponse($id, -32601, "Tool not found: {$toolName}");
        }

        $content = $this->tools[$toolName]->handle($arguments, $accountId);

        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['content' => $content],
        ];
    }

    /** @return array<string, mixed> */
    private function errorResponse(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Mcp/McpRouterTest.php`
Expected: All 6 tests PASS

**Step 5: Commit**

```bash
git add src/Mcp/McpRouter.php tests/Unit/Mcp/McpRouterTest.php
git commit -m "feat(mcp): add McpRouter with JSON-RPC dispatch"
```

---

### Task 1.3: McpController

**Files:**
- Create: `src/Controller/McpController.php`
- Create: `tests/Unit/Controller/McpControllerTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\McpController;
use Claudriel\Mcp\McpRouter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class McpControllerTest extends TestCase
{
    private McpController $controller;

    protected function setUp(): void
    {
        $_ENV['CLAUDRIEL_API_KEY'] = 'test-mcp-key';
        $this->controller = new McpController(new McpRouter());
    }

    protected function tearDown(): void
    {
        unset($_ENV['CLAUDRIEL_API_KEY']);
    }

    public function testReturns401WithoutBearerToken(): void
    {
        $request = Request::create('/mcp', 'POST', [], [], [], [], json_encode([
            'jsonrpc' => '2.0', 'id' => '1', 'method' => 'tools/list', 'params' => [],
        ]));

        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testReturns401WithInvalidToken(): void
    {
        $request = Request::create('/mcp', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer wrong-key',
        ], json_encode([
            'jsonrpc' => '2.0', 'id' => '1', 'method' => 'tools/list', 'params' => [],
        ]));

        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testHandlesToolsListRequest(): void
    {
        $request = Request::create('/mcp', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-mcp-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'jsonrpc' => '2.0', 'id' => '1', 'method' => 'tools/list', 'params' => [],
        ]));

        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('2.0', $body['jsonrpc']);
        self::assertArrayHasKey('result', $body);
    }

    public function testHandlesInitializeRequest(): void
    {
        $request = Request::create('/mcp', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-mcp-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]));

        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('Claudriel', $body['result']['serverInfo']['name']);
    }

    public function testReturns400ForInvalidJson(): void
    {
        $request = Request::create('/mcp', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-mcp-key',
            'CONTENT_TYPE' => 'application/json',
        ], 'not json');

        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(400, $response->getStatusCode());
    }

    public function testSetsSessionIdHeader(): void
    {
        $request = Request::create('/mcp', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-mcp-key',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode([
            'jsonrpc' => '2.0',
            'id' => '1',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'test', 'version' => '1.0'],
            ],
        ]));

        $response = $this->controller->handle([], [], null, $request);

        self::assertTrue($response->headers->has('Mcp-Session-Id'));
        self::assertNotEmpty($response->headers->get('Mcp-Session-Id'));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Controller/McpControllerTest.php`
Expected: FAIL (class McpController not found)

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Mcp\McpRouter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /mcp — MCP Streamable HTTP endpoint (JSON-RPC 2.0).
 *
 * HttpKernel calls: new $class($entityTypeManager, $twig)
 * then: $instance->handle($params, $query, $account, $httpRequest)
 *
 * We ignore $entityTypeManager/$twig and receive the McpRouter via a static setter
 * or we adapt the constructor to match the controller convention.
 */
final class McpController
{
    private static ?McpRouter $sharedRouter = null;

    public function __construct(
        private readonly mixed $entityTypeManager = null,
        private readonly mixed $twig = null,
    ) {}

    public static function setRouter(McpRouter $router): void
    {
        self::$sharedRouter = $router;
    }

    public function handle(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): JsonResponse
    {
        $token = $this->extractBearerToken($httpRequest);
        if ($token === null || !$this->isValidToken($token)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        if (!$httpRequest instanceof Request) {
            return new JsonResponse(['error' => 'Invalid request'], 400);
        }

        $body = json_decode($httpRequest->getContent(), true);
        if (!is_array($body) || !isset($body['jsonrpc'], $body['method'])) {
            return new JsonResponse(['error' => 'Invalid JSON-RPC request'], 400);
        }

        $router = self::$sharedRouter;
        if ($router === null && $this->entityTypeManager instanceof McpRouter) {
            $router = $this->entityTypeManager;
        }

        if ($router === null) {
            return new JsonResponse([
                'jsonrpc' => '2.0',
                'id' => $body['id'] ?? null,
                'error' => ['code' => -32603, 'message' => 'MCP router not configured'],
            ], 500);
        }

        // For now, use a default account ID. Multi-tenant token-to-account mapping comes in Slice 3.
        $accountId = 'default';
        $result = $router->handleRequest($body, $accountId);

        $response = new JsonResponse($result);

        // Set session ID on initialize responses.
        if (($body['method'] ?? '') === 'initialize') {
            $sessionId = bin2hex(random_bytes(16));
            $response->headers->set('Mcp-Session-Id', $sessionId);
        }

        return $response;
    }

    private function extractBearerToken(mixed $httpRequest): ?string
    {
        if (!$httpRequest instanceof Request) {
            return null;
        }

        $header = $httpRequest->headers->get('Authorization', '');
        if (!is_string($header) || !str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return $token !== '' ? $token : null;
    }

    private function isValidToken(string $token): bool
    {
        $key = $_ENV['CLAUDRIEL_API_KEY']
            ?? getenv('CLAUDRIEL_API_KEY')
            ?: null;

        if ($key === null || $key === '' || $key === false) {
            return false;
        }

        return hash_equals((string) $key, $token);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Controller/McpControllerTest.php`
Expected: All 6 tests PASS

**Step 5: Commit**

```bash
git add src/Controller/McpController.php tests/Unit/Controller/McpControllerTest.php
git commit -m "feat(mcp): add McpController HTTP endpoint"
```

---

### Task 1.4: MemoryBriefingTool

**Files:**
- Create: `src/Mcp/Tool/MemoryBriefingTool.php`
- Create: `tests/Unit/Mcp/Tool/MemoryBriefingToolTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Mcp\Tool;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Mcp\Tool\MemoryBriefingTool;
use PHPUnit\Framework\TestCase;

final class MemoryBriefingToolTest extends TestCase
{
    public function testNameIsMemoryBriefing(): void
    {
        $assembler = $this->createMock(DayBriefAssembler::class);
        $tool = new MemoryBriefingTool($assembler);

        self::assertSame('memory.briefing', $tool->name());
    }

    public function testInputSchemaHasNoRequiredFields(): void
    {
        $assembler = $this->createMock(DayBriefAssembler::class);
        $tool = new MemoryBriefingTool($assembler);

        $schema = $tool->inputSchema();
        self::assertSame('object', $schema['type']);
    }

    public function testHandleReturnsBriefAsTextContent(): void
    {
        $assembler = $this->createMock(DayBriefAssembler::class);
        $assembler->method('assemble')
            ->willReturn([
                'recent_events' => [],
                'events_by_source' => [],
                'people' => [],
                'pending_commitments' => [],
                'drifting_commitments' => [],
                'matched_skills' => [],
            ]);

        $tool = new MemoryBriefingTool($assembler);
        $result = $tool->handle([], 'default');

        self::assertCount(1, $result);
        self::assertSame('text', $result[0]['type']);
        self::assertStringContainsString('Recent events: 0', $result[0]['text']);
        self::assertStringContainsString('Pending commitments: 0', $result[0]['text']);
    }

    public function testHandleIncludesEventAndCommitmentCounts(): void
    {
        $mockEvent = new \stdClass();

        $mockCommitment = $this->createStub(\Waaseyaa\Entity\EntityInterface::class);
        $mockCommitment->method('get')->willReturnMap([
            ['title', 'Follow up with Bob'],
            ['due_date', '2026-03-10'],
        ]);

        $assembler = $this->createMock(DayBriefAssembler::class);
        $assembler->method('assemble')
            ->willReturn([
                'recent_events' => [$mockEvent, $mockEvent],
                'events_by_source' => ['gmail' => [$mockEvent]],
                'people' => ['bob@test.com' => 'Bob'],
                'pending_commitments' => [$mockCommitment],
                'drifting_commitments' => [],
                'matched_skills' => [],
            ]);

        $tool = new MemoryBriefingTool($assembler);
        $result = $tool->handle([], 'default');

        self::assertStringContainsString('Recent events: 2', $result[0]['text']);
        self::assertStringContainsString('Pending commitments: 1', $result[0]['text']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Mcp/Tool/MemoryBriefingToolTest.php`
Expected: FAIL (class MemoryBriefingTool not found)

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Mcp\Tool;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Mcp\McpToolInterface;

final class MemoryBriefingTool implements McpToolInterface
{
    public function __construct(
        private readonly DayBriefAssembler $assembler,
    ) {}

    public function name(): string
    {
        return 'memory.briefing';
    }

    public function description(): string
    {
        return 'Get today\'s brief: recent events, pending commitments, drifting commitments, and matched skills.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'hours' => [
                    'type' => 'integer',
                    'description' => 'Hours of history to include (default: 24)',
                ],
            ],
        ];
    }

    public function handle(array $arguments, string $accountId): array
    {
        $hours = (int) ($arguments['hours'] ?? 24);
        $since = new \DateTimeImmutable("-{$hours} hours");

        $brief = $this->assembler->assemble($accountId, $since);

        return [
            ['type' => 'text', 'text' => $this->formatBrief($brief)],
        ];
    }

    private function formatBrief(array $brief): string
    {
        $lines = ['# Day Brief'];

        $eventCount = count($brief['recent_events']);
        $lines[] = "\nRecent events: {$eventCount}";

        if (!empty($brief['events_by_source'])) {
            foreach ($brief['events_by_source'] as $source => $events) {
                $lines[] = "  {$source}: " . count($events);
            }
        }

        if (!empty($brief['people'])) {
            $names = array_values($brief['people']);
            $lines[] = "\nPeople seen: " . implode(', ', array_slice($names, 0, 10));
        }

        $pendingCount = count($brief['pending_commitments']);
        $lines[] = "\nPending commitments: {$pendingCount}";

        if ($pendingCount > 0) {
            foreach (array_slice($brief['pending_commitments'], 0, 10) as $c) {
                $title = $c->get('title') ?? '(untitled)';
                $due = $c->get('due_date') ?? 'no due date';
                $lines[] = "  - {$title} (due: {$due})";
            }
        }

        $driftingCount = count($brief['drifting_commitments']);
        if ($driftingCount > 0) {
            $lines[] = "\nDrifting commitments (48h+ inactive): {$driftingCount}";
        }

        if (!empty($brief['matched_skills'])) {
            $lines[] = "\nMatched skills: " . count($brief['matched_skills']);
        }

        return implode("\n", $lines);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Mcp/Tool/MemoryBriefingToolTest.php`
Expected: All 4 tests PASS

**Step 5: Commit**

```bash
git add src/Mcp/Tool/MemoryBriefingTool.php tests/Unit/Mcp/Tool/MemoryBriefingToolTest.php
git commit -m "feat(mcp): add memory.briefing tool"
```

---

### Task 1.5: Wire MCP route into ClaudrielServiceProvider

**Files:**
- Modify: `src/Provider/ClaudrielServiceProvider.php`

**Step 1: Add MCP route registration**

Add to `routes()` method, after the existing routes:

```php
// MCP endpoint (Streamable HTTP transport)
$router->addRoute(
    'claudriel.mcp',
    RouteBuilder::create('/mcp')
        ->controller(McpController::class . '::handle')
        ->allowAll()
        ->methods('POST')
        ->build(),
);
```

Add import at top:

```php
use Claudriel\Controller\McpController;
```

**Step 2: Wire McpRouter with tools in commands() method**

Add after the `$assembler` creation (around line 243), before the return:

```php
$mcpRouter = new \Claudriel\Mcp\McpRouter();
$mcpRouter->registerTool(new \Claudriel\Mcp\Tool\MemoryBriefingTool($assembler));
McpController::setRouter($mcpRouter);
```

**Step 3: Run all tests to verify nothing breaks**

Run: `./vendor/bin/phpunit`
Expected: All existing + new tests PASS

**Step 4: Commit**

```bash
git add src/Provider/ClaudrielServiceProvider.php
git commit -m "feat(mcp): wire MCP route and memory.briefing tool into service provider"
```

---

### Task 1.6: Create `.mcp.json` for local dev

**Files:**
- Create: `.mcp.json`

**Step 1: Create the config file**

```json
{
  "mcpServers": {
    "claudriel": {
      "url": "http://localhost:8080/mcp",
      "headers": {
        "Authorization": "Bearer ${CLAUDRIEL_API_KEY}"
      }
    }
  }
}
```

**Step 2: Commit**

```bash
git add .mcp.json
git commit -m "feat(mcp): add .mcp.json for Claude Code MCP integration"
```

---

### Task 1.7: Manual integration test

**No files to create. Verify the full loop works.**

**Step 1: Start the dev server**

Run: `php -S localhost:8080 -t public/` (or however the app serves)

**Step 2: Test tools/list**

```bash
curl -s -X POST http://localhost:8080/mcp \
  -H "Authorization: Bearer $CLAUDRIEL_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":"1","method":"tools/list","params":{}}' | jq .
```

Expected: JSON with `result.tools` containing `memory.briefing`.

**Step 3: Test memory.briefing**

```bash
curl -s -X POST http://localhost:8080/mcp \
  -H "Authorization: Bearer $CLAUDRIEL_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":"2","method":"tools/call","params":{"name":"memory.briefing","arguments":{}}}' | jq .
```

Expected: JSON with `result.content[0].text` containing the brief.

---

## Slice 2: Identity

### Task 2.1: Create CLAUDRIEL.md identity file

**Files:**
- Create: `resources/identity/CLAUDRIEL.md`

**Step 1: Create resources/identity/ directory**

```bash
mkdir -p resources/identity
```

**Step 2: Draft CLAUDRIEL.md**

Migrate content from `.claude/rules/claudia-principles.md` and `.claude/rules/trust-north-star.md` into a single identity document. Key sections:

- Mission statement
- Personality (warm, direct, witty but not servile)
- Trust principles (source attribution, confidence transparency, contradiction surfacing)
- Behavioral rules (safety first, honest about uncertainty, respect for autonomy)
- Output formatting
- What Claudriel never does / always does

The file should be ~500-800 words of prose, not structured config. Use the existing content as the base, rewritten from Claudia's voice to Claudriel's.

**Step 3: Commit**

```bash
git add resources/identity/CLAUDRIEL.md
git commit -m "feat(identity): create CLAUDRIEL.md canonical identity file"
```

---

### Task 2.2: IdentityGetTool

**Files:**
- Create: `src/Mcp/Tool/IdentityGetTool.php`
- Create: `tests/Unit/Mcp/Tool/IdentityGetToolTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Mcp\Tool;

use Claudriel\Mcp\Tool\IdentityGetTool;
use PHPUnit\Framework\TestCase;

final class IdentityGetToolTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/claudriel-test-' . uniqid();
        mkdir($this->tempDir . '/resources/identity', 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . '/resources/identity/*'));
        rmdir($this->tempDir . '/resources/identity');
        rmdir($this->tempDir . '/resources');
        rmdir($this->tempDir);
    }

    public function testNameIsIdentityGet(): void
    {
        $tool = new IdentityGetTool($this->tempDir);
        self::assertSame('identity.get', $tool->name());
    }

    public function testHandleReturnsIdentityFileContent(): void
    {
        file_put_contents(
            $this->tempDir . '/resources/identity/CLAUDRIEL.md',
            '# Claudriel Identity\n\nI am Claudriel.'
        );

        $tool = new IdentityGetTool($this->tempDir);
        $result = $tool->handle([], 'default');

        self::assertCount(1, $result);
        self::assertSame('text', $result[0]['type']);
        self::assertStringContainsString('Claudriel Identity', $result[0]['text']);
    }

    public function testHandleReturnsFallbackWhenFileMissing(): void
    {
        $tool = new IdentityGetTool($this->tempDir);
        $result = $tool->handle([], 'default');

        self::assertCount(1, $result);
        self::assertStringContainsString('not configured', $result[0]['text']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Mcp/Tool/IdentityGetToolTest.php`
Expected: FAIL

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Mcp\Tool;

use Claudriel\Mcp\McpToolInterface;

final class IdentityGetTool implements McpToolInterface
{
    public function __construct(
        private readonly string $projectRoot,
    ) {}

    public function name(): string
    {
        return 'identity.get';
    }

    public function description(): string
    {
        return 'Get Claudriel\'s canonical identity (personality, behavioral rules, trust principles).';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function handle(array $arguments, string $accountId): array
    {
        $path = $this->projectRoot . '/resources/identity/CLAUDRIEL.md';

        if (!is_file($path)) {
            return [['type' => 'text', 'text' => 'Identity file not configured.']];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [['type' => 'text', 'text' => 'Failed to read identity file.']];
        }

        return [['type' => 'text', 'text' => $content]];
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Mcp/Tool/IdentityGetToolTest.php`
Expected: All 3 tests PASS

**Step 5: Commit**

```bash
git add src/Mcp/Tool/IdentityGetTool.php tests/Unit/Mcp/Tool/IdentityGetToolTest.php
git commit -m "feat(mcp): add identity.get tool"
```

---

### Task 2.3: Update ChatSystemPromptBuilder to use CLAUDRIEL.md

**Files:**
- Modify: `src/Domain/Chat/ChatSystemPromptBuilder.php`
- Modify: `tests/Unit/Domain/Chat/ChatSystemPromptBuilderTest.php`

**Step 1: Update the builder**

Change `build()` to read `resources/identity/CLAUDRIEL.md` instead of extracting personality from CLAUDE.md:

```php
public function build(string $tenantId = 'default'): string
{
    $parts = [];

    // Identity from CLAUDRIEL.md (canonical identity file)
    $identity = $this->readFile('resources/identity/CLAUDRIEL.md');
    if ($identity !== null) {
        $parts[] = $identity;
    } else {
        // Fallback: extract from CLAUDE.user.md or CLAUDE.md (legacy)
        $claudeMd = $this->readFile('CLAUDE.user.md') ?? $this->readFile('CLAUDE.md') ?? '';
        if ($claudeMd !== '') {
            $parts[] = "# Personality & Behavior\n\n" . $this->extractPersonality($claudeMd);
        }
    }

    // User context
    $me = $this->readFile('context/me.md');
    if ($me !== null) {
        $parts[] = "# About the User\n\n" . $me;
    }

    // Brief summary
    $brief = $this->assembler->assemble($tenantId, new \DateTimeImmutable('-24 hours'));
    $parts[] = $this->formatBriefContext($brief);

    // Chat instructions
    $parts[] = "# Instructions\n\nYou are Claudriel, an AI personal operations assistant. You are responding via the Claudriel web dashboard. Be warm, concise, and proactive. You have access to the user's commitments, events, and personal context shown above. Help them stay on track.";

    return implode("\n\n---\n\n", array_filter($parts));
}
```

**Step 2: Update tests to verify CLAUDRIEL.md is preferred**

Add a test that creates `resources/identity/CLAUDRIEL.md` in the temp directory and verifies it appears in the prompt output instead of extracted CLAUDE.md personality sections.

**Step 3: Run tests**

Run: `./vendor/bin/phpunit tests/Unit/Domain/Chat/ChatSystemPromptBuilderTest.php`
Expected: All tests PASS

**Step 4: Commit**

```bash
git add src/Domain/Chat/ChatSystemPromptBuilder.php tests/Unit/Domain/Chat/ChatSystemPromptBuilderTest.php
git commit -m "feat(identity): update ChatSystemPromptBuilder to use CLAUDRIEL.md"
```

---

### Task 2.4: Wire IdentityGetTool and register in service provider

**Files:**
- Modify: `src/Provider/ClaudrielServiceProvider.php`

**Step 1: Add IdentityGetTool to MCP router registration**

In the MCP wiring section added in Task 1.5:

```php
$mcpRouter->registerTool(new \Claudriel\Mcp\Tool\IdentityGetTool($this->projectRoot));
```

**Step 2: Add identity API route**

```php
$router->addRoute(
    'claudriel.api.identity',
    RouteBuilder::create('/api/identity')
        ->controller(\Claudriel\Controller\IdentityController::class . '::show')
        ->allowAll()
        ->methods('GET')
        ->build(),
);
```

Note: `IdentityController` is a thin controller that reads and returns `resources/identity/CLAUDRIEL.md`. Create it if the slice requires it, or defer to a future task.

**Step 3: Run all tests**

Run: `./vendor/bin/phpunit`
Expected: All tests PASS

**Step 4: Commit**

```bash
git add src/Provider/ClaudrielServiceProvider.php
git commit -m "feat(identity): wire identity.get tool and /api/identity route"
```

---

### Task 2.5: Deprecate legacy identity rules

**Files:**
- Delete: `.claude/rules/claudia-principles.md`
- Delete: `.claude/rules/trust-north-star.md`
- Modify: `.claude/rules/memory-availability.md` (update references from Claudia to Claudriel)

**Step 1: Remove deprecated files**

```bash
git rm .claude/rules/claudia-principles.md
git rm .claude/rules/trust-north-star.md
```

**Step 2: Update memory-availability.md**

Replace references to "Claudia" with "Claudriel" and update the MCP configuration section to point to the HTTP endpoint instead of the Python daemon.

**Step 3: Commit**

```bash
git add -u .claude/rules/
git commit -m "refactor(identity): deprecate legacy Claudia identity rules, migrate to CLAUDRIEL.md"
```

---

## Slice 3: Memory Read Tools

### Task 3.1: Add account_id to all entities

**Files:**
- Modify: `src/Entity/McEvent.php`
- Modify: `src/Entity/Commitment.php`
- Modify: `src/Entity/Person.php`
- Modify: `src/Entity/ChatSession.php`
- Modify: `src/Entity/ChatMessage.php`
- Modify: `src/Entity/Integration.php`
- Modify: `src/Entity/Skill.php`
- Create: `tests/Unit/Entity/AccountIdTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\McEvent;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\Integration;
use Claudriel\Entity\Skill;
use PHPUnit\Framework\TestCase;

final class AccountIdTest extends TestCase
{
    /**
     * @dataProvider entityProvider
     */
    public function testEntityAcceptsAccountId(string $class): void
    {
        $entity = new $class(['account_id' => 'tenant-1']);
        self::assertSame('tenant-1', $entity->get('account_id'));
    }

    /**
     * @dataProvider entityProvider
     */
    public function testEntityDefaultsAccountIdToDefault(string $class): void
    {
        $entity = new $class();
        self::assertSame('default', $entity->get('account_id'));
    }

    /** @return array<string, array{string}> */
    public static function entityProvider(): array
    {
        return [
            'McEvent' => [McEvent::class],
            'Commitment' => [Commitment::class],
            'Person' => [Person::class],
            'ChatSession' => [ChatSession::class],
            'ChatMessage' => [ChatMessage::class],
            'Integration' => [Integration::class],
            'Skill' => [Skill::class],
        ];
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Entity/AccountIdTest.php`
Expected: FAIL (account_id not set by default)

**Step 3: Add account_id default to each entity constructor**

For each entity class, update the constructor to set a default `account_id`:

```php
public function __construct(array $values = [])
{
    $values += ['account_id' => 'default'];
    parent::__construct($values, 'mc_event', $this->entityKeys);
}
```

Apply this pattern to all 7 entity classes (McEvent, Commitment, Person, ChatSession, ChatMessage, Integration, Skill).

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Entity/AccountIdTest.php`
Expected: All 14 tests PASS

**Step 5: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests PASS (existing tests unaffected since default is 'default')

**Step 6: Commit**

```bash
git add src/Entity/*.php tests/Unit/Entity/AccountIdTest.php
git commit -m "feat(multi-tenant): add account_id field to all entities with 'default' fallback"
```

---

### Task 3.2: MemoryRecallTool (search)

**Files:**
- Create: `src/Mcp/Tool/MemoryRecallTool.php`
- Create: `tests/Unit/Mcp/Tool/MemoryRecallToolTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Mcp\Tool;

use Claudriel\Entity\McEvent;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use Claudriel\Mcp\Tool\MemoryRecallTool;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Symfony\Component\EventDispatcher\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class MemoryRecallToolTest extends TestCase
{
    private MemoryRecallTool $tool;
    private EntityRepository $eventRepo;
    private EntityRepository $commitmentRepo;
    private EntityRepository $personRepo;

    protected function setUp(): void
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($db);

        $eventType = new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']);
        (new SqlSchemaHandler($eventType, $db))->ensureTable();
        $this->eventRepo = new EntityRepository($eventType, new SqlStorageDriver($resolver, 'eid'), $dispatcher);

        $commitmentType = new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']);
        (new SqlSchemaHandler($commitmentType, $db))->ensureTable();
        $this->commitmentRepo = new EntityRepository($commitmentType, new SqlStorageDriver($resolver, 'cid'), $dispatcher);

        $personType = new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']);
        (new SqlSchemaHandler($personType, $db))->ensureTable();
        $this->personRepo = new EntityRepository($personType, new SqlStorageDriver($resolver, 'pid'), $dispatcher);

        $this->tool = new MemoryRecallTool($this->eventRepo, $this->commitmentRepo, $this->personRepo);
    }

    public function testNameIsMemoryRecall(): void
    {
        self::assertSame('memory.recall', $this->tool->name());
    }

    public function testRequiresQueryArgument(): void
    {
        $schema = $this->tool->inputSchema();
        self::assertContains('query', $schema['required']);
    }

    public function testReturnsEmptyWhenNoMatches(): void
    {
        $result = $this->tool->handle(['query' => 'nonexistent'], 'default');
        self::assertCount(1, $result);
        self::assertStringContainsString('No results', $result[0]['text']);
    }

    public function testMatchesEventByPayloadContent(): void
    {
        $event = new McEvent([
            'uuid' => 'e-1',
            'source' => 'gmail',
            'type' => 'email',
            'payload' => json_encode(['subject' => 'Meeting with Bob']),
            'occurred' => '2026-03-09T10:00:00+00:00',
            'account_id' => 'default',
        ]);
        $this->eventRepo->save($event);

        $result = $this->tool->handle(['query' => 'Bob'], 'default');
        self::assertStringContainsString('Bob', $result[0]['text']);
    }

    public function testMatchesCommitmentByTitle(): void
    {
        $commitment = new Commitment([
            'uuid' => 'c-1',
            'title' => 'Send proposal to Alice',
            'status' => 'pending',
            'confidence' => 0.9,
            'account_id' => 'default',
        ]);
        $this->commitmentRepo->save($commitment);

        $result = $this->tool->handle(['query' => 'Alice'], 'default');
        self::assertStringContainsString('Alice', $result[0]['text']);
    }

    public function testMatchesPersonByName(): void
    {
        $person = new Person([
            'uuid' => 'p-1',
            'name' => 'Charlie Davis',
            'email' => 'charlie@example.com',
            'account_id' => 'default',
        ]);
        $this->personRepo->save($person);

        $result = $this->tool->handle(['query' => 'Charlie'], 'default');
        self::assertStringContainsString('Charlie', $result[0]['text']);
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Mcp/Tool/MemoryRecallToolTest.php`
Expected: FAIL

**Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Mcp\Tool;

use Claudriel\Mcp\McpToolInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class MemoryRecallTool implements McpToolInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $eventRepo,
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly EntityRepositoryInterface $personRepo,
    ) {}

    public function name(): string
    {
        return 'memory.recall';
    }

    public function description(): string
    {
        return 'Search memory across events, commitments, and people. Returns matching results.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Search query'],
                'limit' => ['type' => 'integer', 'description' => 'Max results (default: 20)'],
            ],
            'required' => ['query'],
        ];
    }

    public function handle(array $arguments, string $accountId): array
    {
        $query = strtolower($arguments['query'] ?? '');
        $limit = (int) ($arguments['limit'] ?? 20);

        if ($query === '') {
            return [['type' => 'text', 'text' => 'No query provided.']];
        }

        $results = [];

        // Search events
        foreach ($this->eventRepo->findBy([]) as $event) {
            if ($event->get('account_id') !== $accountId) {
                continue;
            }
            $searchable = strtolower(
                ($event->get('source') ?? '') . ' ' .
                ($event->get('type') ?? '') . ' ' .
                ($event->get('payload') ?? '')
            );
            if (str_contains($searchable, $query)) {
                $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];
                $subject = $payload['subject'] ?? $payload['from_name'] ?? $event->get('type') ?? 'Event';
                $results[] = "[Event] {$subject} (source: {$event->get('source')}, {$event->get('occurred')})";
            }
        }

        // Search commitments
        foreach ($this->commitmentRepo->findBy([]) as $commitment) {
            if ($commitment->get('account_id') !== $accountId) {
                continue;
            }
            $searchable = strtolower($commitment->get('title') ?? '');
            if (str_contains($searchable, $query)) {
                $results[] = "[Commitment] {$commitment->get('title')} (status: {$commitment->get('status')})";
            }
        }

        // Search people
        foreach ($this->personRepo->findBy([]) as $person) {
            if ($person->get('account_id') !== $accountId) {
                continue;
            }
            $searchable = strtolower(
                ($person->get('name') ?? '') . ' ' . ($person->get('email') ?? '')
            );
            if (str_contains($searchable, $query)) {
                $results[] = "[Person] {$person->get('name')} ({$person->get('email')})";
            }
        }

        if (empty($results)) {
            return [['type' => 'text', 'text' => "No results found for: {$arguments['query']}"]];
        }

        $results = array_slice($results, 0, $limit);

        return [['type' => 'text', 'text' => implode("\n", $results)]];
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Mcp/Tool/MemoryRecallToolTest.php`
Expected: All 6 tests PASS

**Step 5: Commit**

```bash
git add src/Mcp/Tool/MemoryRecallTool.php tests/Unit/Mcp/Tool/MemoryRecallToolTest.php
git commit -m "feat(mcp): add memory.recall search tool"
```

---

### Task 3.3: MemoryAboutTool (person lookup)

**Files:**
- Create: `src/Mcp/Tool/MemoryAboutTool.php`
- Create: `tests/Unit/Mcp/Tool/MemoryAboutToolTest.php`

**Step 1: Write the failing test**

Test should verify:
- `name()` returns `memory.about`
- Requires `name` argument
- Returns person details + related commitments + related events
- Returns "not found" for unknown person

**Step 2: Run test, verify fail**

**Step 3: Implement** — query Person by name (case-insensitive LIKE), then find commitments and events that reference that person's UUID or email.

**Step 4: Run test, verify pass**

**Step 5: Commit**

```bash
git add src/Mcp/Tool/MemoryAboutTool.php tests/Unit/Mcp/Tool/MemoryAboutToolTest.php
git commit -m "feat(mcp): add memory.about person lookup tool"
```

---

### Task 3.4: MemoryCommitmentsTool

**Files:**
- Create: `src/Mcp/Tool/MemoryCommitmentsTool.php`
- Create: `tests/Unit/Mcp/Tool/MemoryCommitmentsToolTest.php`

**Step 1: Write the failing test**

Test should verify:
- `name()` returns `memory.commitments`
- Optional `status` filter argument (pending, active, done)
- Returns all commitments for account when no filter
- Returns filtered commitments when status provided
- Results include title, status, confidence, due_date

**Step 2-5: Standard TDD cycle + commit**

```bash
git commit -m "feat(mcp): add memory.commitments tool"
```

---

### Task 3.5: MemoryEventsTool

**Files:**
- Create: `src/Mcp/Tool/MemoryEventsTool.php`
- Create: `tests/Unit/Mcp/Tool/MemoryEventsTool.php`

**Step 1: Write the failing test**

Test should verify:
- `name()` returns `memory.events`
- Optional `source` and `hours` filter arguments
- Returns events for account within time window
- Results include source, type, occurred, payload summary

**Step 2-5: Standard TDD cycle + commit**

```bash
git commit -m "feat(mcp): add memory.events tool"
```

---

### Task 3.6: MemoryContextTool

**Files:**
- Create: `src/Mcp/Tool/MemoryContextTool.php`
- Create: `tests/Unit/Mcp/Tool/MemoryContextToolTest.php`

**Step 1: Write the failing test**

Test should verify:
- `name()` returns `memory.context`
- Returns combined context files for the account from `storage/context/{accountId}/`
- Returns fallback message when context directory doesn't exist
- Reads me.md, commitments.md, patterns.md, people.md, brief.md if they exist

**Step 2-5: Standard TDD cycle + commit**

```bash
git commit -m "feat(mcp): add memory.context tool"
```

---

### Task 3.7: Wire all Slice 3 tools into service provider

**Files:**
- Modify: `src/Provider/ClaudrielServiceProvider.php`

**Step 1: Register all new tools in the MCP router**

Add to the MCP wiring section:

```php
$personRepo = new EntityRepository(
    new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
    new SqlStorageDriver($resolver, 'pid'),
    $dispatcher,
);

$mcpRouter->registerTool(new \Claudriel\Mcp\Tool\MemoryRecallTool($eventRepo, $commitmentRepo, $personRepo));
$mcpRouter->registerTool(new \Claudriel\Mcp\Tool\MemoryAboutTool($personRepo, $eventRepo, $commitmentRepo));
$mcpRouter->registerTool(new \Claudriel\Mcp\Tool\MemoryCommitmentsTool($commitmentRepo));
$mcpRouter->registerTool(new \Claudriel\Mcp\Tool\MemoryEventsTool($eventRepo));
$mcpRouter->registerTool(new \Claudriel\Mcp\Tool\MemoryContextTool($this->projectRoot));
```

**Step 2: Run full test suite**

Run: `./vendor/bin/phpunit`
Expected: All tests PASS

**Step 3: Commit**

```bash
git add src/Provider/ClaudrielServiceProvider.php
git commit -m "feat(mcp): wire all memory read tools into service provider"
```

---

## Slice 4: Memory Write Tools

### Task 4.1: MemoryRememberTool

**Files:**
- Create: `src/Mcp/Tool/MemoryRememberTool.php`
- Create: `tests/Unit/Mcp/Tool/MemoryRememberToolTest.php`

**Step 1: Write the failing test**

Test should verify:
- `name()` returns `memory.remember`
- Requires `content` argument
- Creates an McEvent with `source=manual`, `type=memory_note`
- Stores the content in the payload
- Returns the created event's UUID

**Step 2-5: Standard TDD cycle**

Implementation creates a new McEvent via `$eventRepo->save()`:

```php
$event = new McEvent([
    'uuid' => Uuid::v4(),
    'source' => 'manual',
    'type' => $arguments['type'] ?? 'memory_note',
    'payload' => json_encode(['content' => $arguments['content']]),
    'occurred' => (new \DateTimeImmutable())->format('c'),
    'account_id' => $accountId,
]);
$this->eventRepo->save($event);
```

**Step 6: Commit**

```bash
git commit -m "feat(mcp): add memory.remember write tool"
```

---

### Task 4.2: MemoryUpdateTool

**Files:**
- Create: `src/Mcp/Tool/MemoryUpdateTool.php`
- Create: `tests/Unit/Mcp/Tool/MemoryUpdateToolTest.php`

**Step 1: Write the failing test**

Test should verify:
- `name()` returns `memory.update`
- Requires `uuid` and `entity_type` arguments
- Requires `fields` argument (key-value pairs to update)
- Updates the entity and saves
- Returns 404-style error for unknown UUID
- Validates entity_type is one of: commitment, person, mc_event

**Step 2-5: Standard TDD cycle + commit**

```bash
git commit -m "feat(mcp): add memory.update tool"
```

---

### Task 4.3: MemoryDeleteTool

**Files:**
- Create: `src/Mcp/Tool/MemoryDeleteTool.php`
- Create: `tests/Unit/Mcp/Tool/MemoryDeleteToolTest.php`

**Step 1: Write the failing test**

Test should verify:
- `name()` returns `memory.delete`
- Requires `uuid` and `entity_type` arguments
- Deletes the entity
- Returns error for unknown UUID

**Step 2-5: Standard TDD cycle + commit**

```bash
git commit -m "feat(mcp): add memory.delete tool"
```

---

### Task 4.4: MemoryIngestTool

**Files:**
- Create: `src/Mcp/Tool/MemoryIngestTool.php`
- Create: `tests/Unit/Mcp/Tool/MemoryIngestToolTest.php`

**Step 1: Write the failing test**

Test should verify:
- `name()` returns `memory.ingest`
- Requires `source`, `type`, `payload` arguments
- Delegates to `IngestHandlerRegistry`
- Returns the created entity UUID

**Step 2-5: Standard TDD cycle + commit**

```bash
git commit -m "feat(mcp): add memory.ingest tool"
```

---

### Task 4.5: Wire all Slice 4 tools + commit

**Files:**
- Modify: `src/Provider/ClaudrielServiceProvider.php`

Register MemoryRememberTool, MemoryUpdateTool, MemoryDeleteTool, MemoryIngestTool in the MCP router.

```bash
git commit -m "feat(mcp): wire all memory write tools into service provider"
```

---

## Slice 5: Context Generation

### Task 5.1: ContextGenerator

**Files:**
- Create: `src/Context/ContextGenerator.php`
- Create: `tests/Unit/Context/ContextGeneratorTest.php`

**Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Context;

use Claudriel\Context\ContextGenerator;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Symfony\Component\EventDispatcher\EventDispatcher;
use PHPUnit\Framework\TestCase;

final class ContextGeneratorTest extends TestCase
{
    private string $storageDir;
    private ContextGenerator $generator;
    private EntityRepository $commitmentRepo;
    private EntityRepository $personRepo;

    protected function setUp(): void
    {
        $this->storageDir = sys_get_temp_dir() . '/claudriel-ctx-' . uniqid();
        mkdir($this->storageDir, 0777, true);

        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $resolver = new SingleConnectionResolver($db);

        $commitmentType = new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']);
        (new SqlSchemaHandler($commitmentType, $db))->ensureTable();
        $this->commitmentRepo = new EntityRepository($commitmentType, new SqlStorageDriver($resolver, 'cid'), $dispatcher);

        $personType = new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']);
        (new SqlSchemaHandler($personType, $db))->ensureTable();
        $this->personRepo = new EntityRepository($personType, new SqlStorageDriver($resolver, 'pid'), $dispatcher);

        $this->generator = new ContextGenerator(
            $this->storageDir,
            $this->commitmentRepo,
            $this->personRepo,
        );
    }

    protected function tearDown(): void
    {
        // Clean up temp directory
        $files = glob($this->storageDir . '/default/*');
        if ($files) {
            array_map('unlink', $files);
            rmdir($this->storageDir . '/default');
        }
        rmdir($this->storageDir);
    }

    public function testGenerateCommitmentsCreatesFile(): void
    {
        $commitment = new Commitment([
            'uuid' => 'c-1', 'title' => 'Send proposal', 'status' => 'pending',
            'confidence' => 0.9, 'account_id' => 'default',
        ]);
        $this->commitmentRepo->save($commitment);

        $this->generator->generate('default', 'commitments');

        $path = $this->storageDir . '/default/commitments.md';
        self::assertFileExists($path);
        self::assertStringContainsString('Send proposal', file_get_contents($path));
    }

    public function testGeneratePeopleCreatesFile(): void
    {
        $person = new Person([
            'uuid' => 'p-1', 'name' => 'Alice', 'email' => 'alice@test.com',
            'account_id' => 'default',
        ]);
        $this->personRepo->save($person);

        $this->generator->generate('default', 'people');

        $path = $this->storageDir . '/default/people.md';
        self::assertFileExists($path);
        self::assertStringContainsString('Alice', file_get_contents($path));
    }

    public function testGenerateAllCreatesAllFiles(): void
    {
        $this->generator->generateAll('default');

        self::assertDirectoryExists($this->storageDir . '/default');
        self::assertFileExists($this->storageDir . '/default/commitments.md');
        self::assertFileExists($this->storageDir . '/default/people.md');
    }
}
```

**Step 2: Run test to verify it fails**

Run: `./vendor/bin/phpunit tests/Unit/Context/ContextGeneratorTest.php`
Expected: FAIL

**Step 3: Write implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Context;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class ContextGenerator
{
    public function __construct(
        private readonly string $storageDir,
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly EntityRepositoryInterface $personRepo,
    ) {}

    public function generate(string $accountId, string $type): string
    {
        $content = match ($type) {
            'commitments' => $this->generateCommitments($accountId),
            'people' => $this->generatePeople($accountId),
            default => '',
        };

        $dir = $this->storageDir . '/' . $accountId;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $path = $dir . '/' . $type . '.md';
        file_put_contents($path, $content);

        return $content;
    }

    public function generateAll(string $accountId): void
    {
        $this->generate($accountId, 'commitments');
        $this->generate($accountId, 'people');
    }

    private function generateCommitments(string $accountId): string
    {
        $lines = ["# Commitments\n"];

        $commitments = array_filter(
            $this->commitmentRepo->findBy([]),
            fn ($c) => $c->get('account_id') === $accountId,
        );

        if (empty($commitments)) {
            $lines[] = 'No commitments tracked yet.';
            return implode("\n", $lines);
        }

        foreach ($commitments as $c) {
            $title = $c->get('title') ?? '(untitled)';
            $item_status = $c->get('status') ?? 'unknown';
            $due = $c->get('due_date') ?? 'no due date';
            $lines[] = "- **{$title}** (status: {$item_status}, due: {$due})";
        }

        return implode("\n", $lines);
    }

    private function generatePeople(string $accountId): string
    {
        $lines = ["# People\n"];

        $people = array_filter(
            $this->personRepo->findBy([]),
            fn ($p) => $p->get('account_id') === $accountId,
        );

        if (empty($people)) {
            $lines[] = 'No people tracked yet.';
            return implode("\n", $lines);
        }

        foreach ($people as $p) {
            $name = $p->get('name') ?? '(unnamed)';
            $email = $p->get('email') ?? '';
            $lines[] = "- **{$name}**" . ($email ? " ({$email})" : '');
        }

        return implode("\n", $lines);
    }
}
```

**Step 4: Run test to verify it passes**

Run: `./vendor/bin/phpunit tests/Unit/Context/ContextGeneratorTest.php`
Expected: All 3 tests PASS

**Step 5: Commit**

```bash
git add src/Context/ContextGenerator.php tests/Unit/Context/ContextGeneratorTest.php
git commit -m "feat(context): add ContextGenerator for per-account context file generation"
```

---

### Task 5.2: Wire context generation into ingestion

**Files:**
- Modify: `src/Controller/IngestController.php`

After successful ingestion (after `$this->touchBriefSignal()`), trigger context regeneration. This requires the ContextGenerator to be accessible.

**Step 1: Add context generation call**

**Step 2: Run full test suite**

**Step 3: Commit**

```bash
git commit -m "feat(context): trigger context regeneration after ingestion"
```

---

### Task 5.3: Update ChatSystemPromptBuilder to use generated context

**Files:**
- Modify: `src/Domain/Chat/ChatSystemPromptBuilder.php`

Change `readFile('context/me.md')` to read from `storage/context/{tenantId}/me.md`. Add fallback for missing files.

**Step 1-3: Modify, test, commit**

```bash
git commit -m "feat(context): update ChatSystemPromptBuilder to use generated context files"
```

---

## Slice 6: Skill Migration

### Task 6.1: PhpSkillInterface

**Files:**
- Create: `src/Skill/PhpSkillInterface.php`

```php
<?php

declare(strict_types=1);

namespace Claudriel\Skill;

interface PhpSkillInterface
{
    public function name(): string;

    /** @return string ingestion|background|drift|pattern */
    public function category(): string;

    /** @param array<string, mixed> $input */
    public function execute(array $input, string $accountId): array;
}
```

**Commit:**

```bash
git commit -m "feat(skill): add PhpSkillInterface contract"
```

---

### Task 6.2: Promote DriftDetector to PHP skill

**Files:**
- Create: `src/Skill/Drift/DriftDetectorSkill.php`
- Create: `tests/Unit/Skill/Drift/DriftDetectorSkillTest.php`

Wrap the existing `DriftDetector` in a `PhpSkillInterface` implementation. The existing `DriftDetector` class stays as the core logic; the skill is a thin adapter.

**Step 1-5: Standard TDD cycle + commit**

```bash
git commit -m "feat(skill): promote DriftDetector to PHP skill"
```

---

### Task 6.3: Move prompt skills to resources/skills/

**Files:**
- Move: `.claude/skills/*.md` → `resources/skills/`

```bash
mkdir -p resources/skills
# Copy only the behavioral/prompt skills (not the skill-index.json or PHP-logic skills)
cp .claude/skills/morning-brief.md resources/skills/
cp .claude/skills/meeting-prep.md resources/skills/
cp .claude/skills/follow-up-draft.md resources/skills/
cp .claude/skills/capture-meeting.md resources/skills/
cp .claude/skills/memory-manager.md resources/skills/
cp .claude/skills/capability-suggester.md resources/skills/
# ... (identify and copy all behavioral skills)
```

Review each skill file and classify as prompt-type (copy) or PHP-type (skip, already handled or future).

**Commit:**

```bash
git commit -m "feat(skill): migrate prompt-type skills to resources/skills/"
```

---

### Task 6.4: SkillListTool and SkillGetTool

**Files:**
- Create: `src/Mcp/Tool/SkillListTool.php`
- Create: `src/Mcp/Tool/SkillGetTool.php`
- Create: `tests/Unit/Mcp/Tool/SkillListToolTest.php`
- Create: `tests/Unit/Mcp/Tool/SkillGetToolTest.php`

**SkillListTool:** Scans `resources/skills/` directory, returns name + first-line description for each `.md` file.

**SkillGetTool:** Reads a named skill file from `resources/skills/{name}.md` and returns its content.

**Step 1-5: Standard TDD cycle for each tool + commit**

```bash
git commit -m "feat(mcp): add skill.list and skill.get tools"
```

---

### Task 6.5: Update Skill entity schema

**Files:**
- Modify: `src/Entity/Skill.php`

Add defaults for new fields in constructor:

```php
public function __construct(array $values = [])
{
    $values += [
        'account_id' => 'default',
        'runtime' => 'prompt',
        'category' => 'workflow',
        'enabled' => true,
        'config' => '{}',
    ];
    parent::__construct($values, 'skill', $this->entityKeys);
}
```

**Commit:**

```bash
git commit -m "feat(skill): add runtime, category, enabled, config fields to Skill entity"
```

---

### Task 6.6: Add active_skills to ChatSession

**Files:**
- Modify: `src/Entity/ChatSession.php`

Add default for `active_skills` field:

```php
$values += ['account_id' => 'default', 'active_skills' => '[]'];
```

**Commit:**

```bash
git commit -m "feat(chat): add active_skills field to ChatSession entity"
```

---

### Task 6.7: Wire skill tools + commit

**Files:**
- Modify: `src/Provider/ClaudrielServiceProvider.php`

```bash
git commit -m "feat(mcp): wire skill.list and skill.get tools into service provider"
```

---

## Slice 7: Integration Hardening

### Task 7.1: NormalizerRegistry

**Files:**
- Create: `src/Ingestion/Normalizer/NormalizerRegistry.php`
- Create: `src/Ingestion/Normalizer/NormalizerInterface.php`
- Create: `tests/Unit/Ingestion/Normalizer/NormalizerRegistryTest.php`

**NormalizerInterface:**

```php
interface NormalizerInterface
{
    /** @return list<string> Source types this normalizer handles */
    public function supports(): array;

    /** @return array<string, mixed> Normalized envelope */
    public function normalize(array $data): array;
}
```

**NormalizerRegistry:** Maps source types to normalizers. Falls back to pass-through for unknown sources.

**Step 1-5: Standard TDD cycle + commit**

```bash
git commit -m "feat(ingestion): add NormalizerRegistry with source-type dispatch"
```

---

### Task 7.2: ManualEventNormalizer

**Files:**
- Create: `src/Ingestion/Normalizer/ManualEventNormalizer.php`
- Create: `tests/Unit/Ingestion/Normalizer/ManualEventNormalizerTest.php`

Handles `source: "manual"` payloads from `memory.remember` MCP tool.

**Step 1-5: Standard TDD cycle + commit**

```bash
git commit -m "feat(ingestion): add ManualEventNormalizer for MCP memory.remember"
```

---

### Task 7.3: ClaudiaForwardNormalizer

**Files:**
- Create: `src/Ingestion/Normalizer/ClaudiaForwardNormalizer.php`
- Create: `tests/Unit/Ingestion/Normalizer/ClaudiaForwardNormalizerTest.php`

Handles `source: "claudia"` payloads from a local Claudia instance forwarding events.

**Step 1-5: Standard TDD cycle + commit**

```bash
git commit -m "feat(ingestion): add ClaudiaForwardNormalizer for local Claudia bridge"
```

---

### Task 7.4: Update IngestController to use NormalizerRegistry

**Files:**
- Modify: `src/Controller/IngestController.php`

Wire NormalizerRegistry into the controller, dispatch incoming payloads through the appropriate normalizer before handing to the IngestHandlerRegistry.

**Step 1-3: Modify, test, commit**

```bash
git commit -m "refactor(ingestion): wire NormalizerRegistry into IngestController"
```

---

### Task 7.5: Create MCP spec document

**Files:**
- Create: `docs/specs/mcp.md`

Document:
- MCP endpoint (`POST /mcp`)
- Authentication model (Bearer token)
- All registered tools with their input schemas
- JSON-RPC message format
- Session management

**Commit:**

```bash
git commit -m "docs: add MCP server specification"
```

---

### Task 7.6: Update all spec documents

**Files:**
- Modify: `docs/specs/entity.md` (add account_id field)
- Modify: `docs/specs/infrastructure.md` (add MCP, ContextGenerator, skill runtime)
- Modify: `docs/specs/ingestion.md` (add NormalizerRegistry)
- Modify: `docs/specs/chat.md` (update prompt builder, active_skills)

**Commit:**

```bash
git commit -m "docs: update all specs for Identity & Memory Unification"
```

---

### Task 7.7: Cleanup deprecated files

**Files:**
- Delete: `.claude/skills/*.md` (migrated to resources/skills/)
- Modify: `.claude/rules/memory-availability.md` (rewritten for Claudriel MCP)
- Update: `CLAUDE.md` (reference CLAUDRIEL.md for identity)

**Commit:**

```bash
git commit -m "chore: cleanup deprecated Claudia skill files and update references"
```

---

### Task 7.8: Final integration verification

**No files to create.**

**Step 1:** Run full test suite: `./vendor/bin/phpunit`

**Step 2:** Start dev server and verify MCP tools/list returns all 13 tools.

**Step 3:** Verify Claude Code connects via `.mcp.json` and can call `memory.briefing`.

**Step 4:** Verify `POST /api/ingest` with `source: "claudia"` works end-to-end.

**Step 5:** Verify dashboard chat uses CLAUDRIEL.md identity in system prompt.
