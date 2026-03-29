# Agency-Agents Claudriel Integration Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire the agency-agents REST API into Claudriel's chat agent as two meta-tools (`list_specialists`, `execute_specialist`) so the agent can discover and invoke 180 specialist personas.

**Architecture:** Two PHP classes implementing `AgentToolInterface` call the agency-agents REST API via `file_get_contents`. Registered in `ChatStreamController::buildAgentTools()`, gated by `AGENCY_AGENTS_URL` env var.

**Tech Stack:** PHP 8.4, Claudriel's `AgentToolInterface`, `file_get_contents` + `stream_context_create`, Pest/PHPUnit

---

## File Structure

```
src/Domain/Chat/Tool/
  SpecialistListTool.php       # list_specialists tool
  SpecialistExecuteTool.php    # execute_specialist tool
src/Controller/
  ChatStreamController.php     # MODIFY: register tools in buildAgentTools()
tests/Unit/Domain/Chat/Tool/
  SpecialistListToolTest.php
  SpecialistExecuteToolTest.php
```

---

### Task 1: SpecialistListTool

**Files:**
- Create: `src/Domain/Chat/Tool/SpecialistListTool.php`
- Create: `tests/Unit/Domain/Chat/Tool/SpecialistListToolTest.php`

- [ ] **Step 1: Write the failing test**

Write `tests/Unit/Domain/Chat/Tool/SpecialistListToolTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Chat\Tool;

use Claudriel\Domain\Chat\Tool\SpecialistListTool;
use PHPUnit\Framework\TestCase;

final class SpecialistListToolTest extends TestCase
{
    public function test_definition_has_required_fields(): void
    {
        $tool = new SpecialistListTool('http://localhost:3100');
        $def = $tool->definition();

        self::assertSame('list_specialists', $def['name']);
        self::assertArrayHasKey('description', $def);
        self::assertArrayHasKey('input_schema', $def);
        self::assertSame('object', $def['input_schema']['type']);
        self::assertArrayHasKey('query', $def['input_schema']['properties']);
        self::assertArrayHasKey('division', $def['input_schema']['properties']);
        self::assertArrayHasKey('limit', $def['input_schema']['properties']);
    }

    public function test_definition_has_no_required_fields(): void
    {
        $tool = new SpecialistListTool('http://localhost:3100');
        $def = $tool->definition();

        self::assertArrayNotHasKey('required', $def['input_schema']);
    }

    public function test_execute_builds_correct_url_with_query(): void
    {
        $lastUrl = null;
        $tool = new SpecialistListTool('http://localhost:3100', function (string $url) use (&$lastUrl) {
            $lastUrl = $url;
            return json_encode([
                'version' => 'v1',
                'agents' => [],
                'total' => 0,
                'limit' => 10,
                'offset' => 0,
            ]);
        });

        $tool->execute(['query' => 'deal strategy', 'division' => 'sales', 'limit' => 5]);

        self::assertStringContainsString('/v1/agents?', $lastUrl);
        self::assertStringContainsString('q=deal+strategy', $lastUrl);
        self::assertStringContainsString('division=sales', $lastUrl);
        self::assertStringContainsString('limit=5', $lastUrl);
    }

    public function test_execute_returns_specialists_array(): void
    {
        $tool = new SpecialistListTool('http://localhost:3100', function () {
            return json_encode([
                'version' => 'v1',
                'agents' => [
                    [
                        'slug' => 'sales-deal-strategist',
                        'name' => 'Deal Strategist',
                        'division' => 'sales',
                        'specialty' => 'MEDDPICC qualification',
                        'whenToUse' => 'Scoring deals',
                        'emoji' => '♟️',
                    ],
                ],
                'total' => 1,
                'limit' => 10,
                'offset' => 0,
            ]);
        });

        $result = $tool->execute(['query' => 'deal']);

        self::assertArrayHasKey('specialists', $result);
        self::assertCount(1, $result['specialists']);
        self::assertSame('sales-deal-strategist', $result['specialists'][0]['slug']);
        self::assertSame('Deal Strategist', $result['specialists'][0]['name']);
    }

    public function test_execute_returns_error_on_failure(): void
    {
        $tool = new SpecialistListTool('http://localhost:3100', function () {
            return false;
        });

        $result = $tool->execute([]);

        self::assertArrayHasKey('error', $result);
    }

    public function test_execute_defaults_limit_to_10(): void
    {
        $lastUrl = null;
        $tool = new SpecialistListTool('http://localhost:3100', function (string $url) use (&$lastUrl) {
            $lastUrl = $url;
            return json_encode(['version' => 'v1', 'agents' => [], 'total' => 0, 'limit' => 10, 'offset' => 0]);
        });

        $tool->execute([]);

        self::assertStringContainsString('limit=10', $lastUrl);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd ~/dev/claudriel && vendor/bin/phpunit tests/Unit/Domain/Chat/Tool/SpecialistListToolTest.php
```

Expected: FAIL — class `SpecialistListTool` not found.

- [ ] **Step 3: Implement SpecialistListTool**

Write `src/Domain/Chat/Tool/SpecialistListTool.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;

final class SpecialistListTool implements AgentToolInterface
{
    /** @var \Closure(string): (string|false) */
    private readonly \Closure $httpGet;

    public function __construct(
        private readonly string $baseUrl,
        ?\Closure $httpGet = null,
    ) {
        $this->httpGet = $httpGet ?? static function (string $url): string|false {
            $context = stream_context_create([
                'http' => [
                    'timeout' => 10,
                    'ignore_errors' => true,
                ],
            ]);

            return @file_get_contents($url, false, $context);
        };
    }

    public function definition(): array
    {
        return [
            'name' => 'list_specialists',
            'description' => 'Search and filter available AI specialists. Returns a list of specialist agents with their expertise and when to use them. Use this to find the right specialist before calling execute_specialist.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => "Search by keyword (e.g., 'deal strategy', 'feedback', 'proposal')",
                    ],
                    'division' => [
                        'type' => 'string',
                        'description' => "Filter by division (e.g., 'sales', 'product', 'engineering', 'marketing')",
                    ],
                    'limit' => [
                        'type' => 'integer',
                        'description' => 'Max results to return (default 10)',
                    ],
                ],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $params = [];
        if (!empty($args['query'])) {
            $params['q'] = $args['query'];
        }
        if (!empty($args['division'])) {
            $params['division'] = $args['division'];
        }
        $params['limit'] = min((int) ($args['limit'] ?? 10), 50);

        $url = rtrim($this->baseUrl, '/') . '/v1/agents?' . http_build_query($params);
        $response = ($this->httpGet)($url);

        if ($response === false) {
            return ['error' => 'Specialist service unavailable'];
        }

        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['agents'])) {
            return ['error' => 'Invalid response from specialist service'];
        }

        $specialists = array_map(static fn(array $agent) => [
            'slug' => $agent['slug'] ?? '',
            'name' => $agent['name'] ?? '',
            'division' => $agent['division'] ?? '',
            'specialty' => $agent['specialty'] ?? '',
            'when_to_use' => $agent['whenToUse'] ?? $agent['when_to_use'] ?? '',
        ], $data['agents']);

        return [
            'specialists' => $specialists,
            'total' => $data['total'] ?? count($specialists),
        ];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd ~/dev/claudriel && vendor/bin/phpunit tests/Unit/Domain/Chat/Tool/SpecialistListToolTest.php
```

Expected: All 6 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Chat/Tool/SpecialistListTool.php tests/Unit/Domain/Chat/Tool/SpecialistListToolTest.php
git commit -m "feat(#642): add SpecialistListTool — list_specialists agent tool"
```

---

### Task 2: SpecialistExecuteTool

**Files:**
- Create: `src/Domain/Chat/Tool/SpecialistExecuteTool.php`
- Create: `tests/Unit/Domain/Chat/Tool/SpecialistExecuteToolTest.php`

- [ ] **Step 1: Write the failing test**

Write `tests/Unit/Domain/Chat/Tool/SpecialistExecuteToolTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Chat\Tool;

use Claudriel\Domain\Chat\Tool\SpecialistExecuteTool;
use PHPUnit\Framework\TestCase;

final class SpecialistExecuteToolTest extends TestCase
{
    public function test_definition_has_required_fields(): void
    {
        $tool = new SpecialistExecuteTool('http://localhost:3100');
        $def = $tool->definition();

        self::assertSame('execute_specialist', $def['name']);
        self::assertArrayHasKey('description', $def);
        self::assertSame(['slug', 'task'], $def['input_schema']['required']);
        self::assertArrayHasKey('slug', $def['input_schema']['properties']);
        self::assertArrayHasKey('task', $def['input_schema']['properties']);
        self::assertArrayHasKey('context', $def['input_schema']['properties']);
    }

    public function test_execute_rejects_missing_slug(): void
    {
        $tool = new SpecialistExecuteTool('http://localhost:3100');
        $result = $tool->execute(['task' => 'do something']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('slug', $result['error']);
    }

    public function test_execute_rejects_missing_task(): void
    {
        $tool = new SpecialistExecuteTool('http://localhost:3100');
        $result = $tool->execute(['slug' => 'some-agent']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('task', $result['error']);
    }

    public function test_execute_posts_to_correct_url(): void
    {
        $lastUrl = null;
        $lastBody = null;
        $tool = new SpecialistExecuteTool('http://localhost:3100', function (string $url, array $body) use (&$lastUrl, &$lastBody) {
            $lastUrl = $url;
            $lastBody = $body;
            return $this->makeSseResponse(['score' => 0.8, 'risks' => ['no champion']]);
        });

        $tool->execute([
            'slug' => 'sales-deal-strategist',
            'task' => 'Qualify Acme Corp',
            'context' => ['arr' => 50000],
        ]);

        self::assertSame('http://localhost:3100/v1/agents/sales-deal-strategist/execute', $lastUrl);
        self::assertSame('Qualify Acme Corp', $lastBody['task']);
        self::assertSame(['arr' => 50000], $lastBody['context']);
    }

    public function test_execute_extracts_summary_from_sse(): void
    {
        $tool = new SpecialistExecuteTool('http://localhost:3100', function () {
            return $this->makeSseResponse(['score' => 0.8, 'risks' => ['no champion']]);
        });

        $result = $tool->execute([
            'slug' => 'sales-deal-strategist',
            'task' => 'Qualify Acme Corp',
        ]);

        self::assertArrayHasKey('result', $result);
        self::assertSame(0.8, $result['result']['score']);
        self::assertSame(['no champion'], $result['result']['risks']);
        self::assertSame('sales-deal-strategist', $result['agent']);
    }

    public function test_execute_returns_error_on_sse_error_event(): void
    {
        $tool = new SpecialistExecuteTool('http://localhost:3100', function () {
            return "event: error\ndata: {\"code\":\"MODEL_ERROR\",\"message\":\"Rate limit\",\"details\":{}}\n\n";
        });

        $result = $tool->execute(['slug' => 'x', 'task' => 'y']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Rate limit', $result['error']);
    }

    public function test_execute_returns_error_on_http_failure(): void
    {
        $tool = new SpecialistExecuteTool('http://localhost:3100', function () {
            return false;
        });

        $result = $tool->execute(['slug' => 'x', 'task' => 'y']);

        self::assertArrayHasKey('error', $result);
    }

    private function makeSseResponse(array $resultData): string
    {
        $summary = json_encode([
            'version' => 'v1',
            'agent' => 'sales-deal-strategist',
            'task' => 'Qualify Acme Corp',
            'result' => $resultData,
            'metadata' => [
                'model' => 'claude-sonnet-4-6',
                'tokens_in' => 100,
                'tokens_out' => 200,
                'duration_ms' => 1000,
                'execution_id' => 'exec_test',
            ],
        ]);

        return "event: token\ndata: Analyzing...\n\nevent: token\ndata: Done.\n\nevent: summary\ndata: {$summary}\n\n";
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd ~/dev/claudriel && vendor/bin/phpunit tests/Unit/Domain/Chat/Tool/SpecialistExecuteToolTest.php
```

Expected: FAIL — class `SpecialistExecuteTool` not found.

- [ ] **Step 3: Implement SpecialistExecuteTool**

Write `src/Domain/Chat/Tool/SpecialistExecuteTool.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;

final class SpecialistExecuteTool implements AgentToolInterface
{
    /** @var \Closure(string, array): (string|false) */
    private readonly \Closure $httpPost;

    public function __construct(
        private readonly string $baseUrl,
        ?\Closure $httpPost = null,
    ) {
        $this->httpPost = $httpPost ?? static function (string $url, array $body): string|false {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\n",
                    'content' => json_encode($body, JSON_THROW_ON_ERROR),
                    'timeout' => 120,
                    'ignore_errors' => true,
                ],
            ]);

            return @file_get_contents($url, false, $context);
        };
    }

    public function definition(): array
    {
        return [
            'name' => 'execute_specialist',
            'description' => 'Execute a specialist agent with a task. The specialist analyzes the task using domain expertise and returns structured findings. Use list_specialists first to find the right slug.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'slug' => [
                        'type' => 'string',
                        'description' => "Specialist slug from list_specialists (e.g., 'sales-deal-strategist')",
                    ],
                    'task' => [
                        'type' => 'string',
                        'description' => 'The task for the specialist to perform',
                    ],
                    'context' => [
                        'type' => 'object',
                        'description' => 'Optional context data the specialist can reference',
                    ],
                ],
                'required' => ['slug', 'task'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $slug = trim($args['slug'] ?? '');
        $task = trim($args['task'] ?? '');

        if ($slug === '') {
            return ['error' => 'Missing required field: slug'];
        }
        if ($task === '') {
            return ['error' => 'Missing required field: task'];
        }

        $url = rtrim($this->baseUrl, '/') . '/v1/agents/' . urlencode($slug) . '/execute';
        $body = ['task' => $task];
        if (!empty($args['context']) && is_array($args['context'])) {
            $body['context'] = $args['context'];
        }

        $response = ($this->httpPost)($url, $body);

        if ($response === false) {
            return ['error' => 'Specialist service unavailable'];
        }

        return $this->parseSseResponse($response);
    }

    private function parseSseResponse(string $raw): array
    {
        $events = preg_split('/\n\n+/', trim($raw));

        // Walk backwards to find the summary or error event
        foreach (array_reverse($events) as $block) {
            $eventType = null;
            $data = null;

            foreach (explode("\n", $block) as $line) {
                if (str_starts_with($line, 'event: ')) {
                    $eventType = substr($line, 7);
                } elseif (str_starts_with($line, 'data: ')) {
                    $data = substr($line, 6);
                }
            }

            if ($eventType === 'summary' && $data !== null) {
                $parsed = json_decode($data, true);
                if (!is_array($parsed)) {
                    return ['error' => 'Invalid summary from specialist'];
                }

                return [
                    'agent' => $parsed['agent'] ?? '',
                    'result' => $parsed['result'] ?? [],
                    'metadata' => $parsed['metadata'] ?? [],
                ];
            }

            if ($eventType === 'error' && $data !== null) {
                $parsed = json_decode($data, true);
                $message = is_array($parsed) ? ($parsed['message'] ?? 'Unknown error') : $data;

                return ['error' => "Specialist error: {$message}"];
            }
        }

        return ['error' => 'No summary received from specialist'];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
cd ~/dev/claudriel && vendor/bin/phpunit tests/Unit/Domain/Chat/Tool/SpecialistExecuteToolTest.php
```

Expected: All 7 tests PASS.

- [ ] **Step 5: Commit**

```bash
git add src/Domain/Chat/Tool/SpecialistExecuteTool.php tests/Unit/Domain/Chat/Tool/SpecialistExecuteToolTest.php
git commit -m "feat(#642): add SpecialistExecuteTool — execute_specialist agent tool"
```

---

### Task 3: Register Tools in ChatStreamController

**Files:**
- Modify: `src/Controller/ChatStreamController.php` (in `buildAgentTools()`)

- [ ] **Step 1: Add the use statements**

At the top of `ChatStreamController.php`, add:

```php
use Claudriel\Domain\Chat\Tool\SpecialistListTool;
use Claudriel\Domain\Chat\Tool\SpecialistExecuteTool;
```

- [ ] **Step 2: Register tools in buildAgentTools()**

At the end of `buildAgentTools()`, before `return $tools;`, add:

```php
// Agency specialists (optional sidecar)
$agencyUrl = getenv('AGENCY_AGENTS_URL');
if ($agencyUrl !== false && $agencyUrl !== '') {
    $tools[] = new SpecialistListTool($agencyUrl);
    $tools[] = new SpecialistExecuteTool($agencyUrl);
}
```

- [ ] **Step 3: Run full test suite to verify no regressions**

```bash
cd ~/dev/claudriel && vendor/bin/phpunit tests/Unit/Domain/Chat/Tool/
```

Expected: All specialist tool tests plus existing tool tests PASS.

- [ ] **Step 4: Commit**

```bash
git add src/Controller/ChatStreamController.php
git commit -m "feat(#642): register specialist tools in ChatStreamController"
```

---

### Task 4: Run All Tests

**Files:**
- None (verification only)

- [ ] **Step 1: Run the full unit test suite**

```bash
cd ~/dev/claudriel && vendor/bin/phpunit tests/Unit/
```

Expected: All tests pass, including the 13 new specialist tool tests.

- [ ] **Step 2: Run PHPStan**

```bash
cd ~/dev/claudriel && vendor/bin/phpstan analyse src/Domain/Chat/Tool/SpecialistListTool.php src/Domain/Chat/Tool/SpecialistExecuteTool.php
```

Expected: No errors.

- [ ] **Step 3: Run Pint on new files**

```bash
cd ~/dev/claudriel && vendor/bin/pint src/Domain/Chat/Tool/SpecialistListTool.php src/Domain/Chat/Tool/SpecialistExecuteTool.php tests/Unit/Domain/Chat/Tool/SpecialistListToolTest.php tests/Unit/Domain/Chat/Tool/SpecialistExecuteToolTest.php
```

- [ ] **Step 4: Commit any formatting fixes**

```bash
git add -u && git diff --cached --quiet || git commit -m "style: format specialist tool files"
```
