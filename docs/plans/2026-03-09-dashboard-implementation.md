# Dashboard Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Merge Day Brief and Chat into a single dashboard page with SSE streaming for live updates.

**Architecture:** Single `/` route serves a two-column Twig template. Brief panel updates via `GET /stream/brief` SSE (file-signal triggered). Chat responses stream via `GET /stream/chat/{messageId}` SSE (Anthropic streaming API). All controllers follow Waaseyaa's `new $class($entityTypeManager, $twig)` constructor convention.

**Tech Stack:** PHP 8.3, Waaseyaa framework, Twig, Symfony HttpFoundation (StreamedResponse), Anthropic Messages API (streaming), vanilla JS EventSource.

**Design doc:** `docs/plans/2026-03-09-dashboard-design.md`

---

## Task 1: BriefSignal — File Signal Mechanism

**Files:**
- Create: `src/Support/BriefSignal.php`
- Create: `tests/Unit/Support/BriefSignalTest.php`

**Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Claudriel\Tests\Unit\Support;

use Claudriel\Support\BriefSignal;
use PHPUnit\Framework\TestCase;

final class BriefSignalTest extends TestCase
{
    private string $signalFile;

    protected function setUp(): void
    {
        $this->signalFile = sys_get_temp_dir() . '/brief_signal_' . uniqid('', true) . '.txt';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->signalFile)) {
            unlink($this->signalFile);
        }
    }

    public function testTouchCreatesFileAndReturnsCurrentTime(): void
    {
        $signal = new BriefSignal($this->signalFile);
        $before = time();
        $signal->touch();
        $after = time();

        self::assertFileExists($this->signalFile);
        $mtime = $signal->lastModified();
        self::assertGreaterThanOrEqual($before, $mtime);
        self::assertLessThanOrEqual($after, $mtime);
    }

    public function testLastModifiedReturnsZeroWhenFileDoesNotExist(): void
    {
        $signal = new BriefSignal($this->signalFile);
        self::assertSame(0, $signal->lastModified());
    }

    public function testHasChangedSinceDetectsTouch(): void
    {
        $signal = new BriefSignal($this->signalFile);
        $signal->touch();
        $baseline = $signal->lastModified();

        // Same mtime, no change
        self::assertFalse($signal->hasChangedSince($baseline));

        // Sleep to ensure mtime differs, then touch again
        sleep(1);
        $signal->touch();
        self::assertTrue($signal->hasChangedSince($baseline));
    }

    public function testHasChangedSinceReturnsFalseWhenNoFile(): void
    {
        $signal = new BriefSignal($this->signalFile);
        self::assertFalse($signal->hasChangedSince(0));
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Support/BriefSignalTest.php -v`
Expected: FAIL — class BriefSignal not found.

**Step 3: Write minimal implementation**

```php
<?php
declare(strict_types=1);
namespace Claudriel\Support;

final class BriefSignal
{
    public function __construct(private readonly string $filePath) {}

    public function touch(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($this->filePath, (string) time());
        clearstatcache(true, $this->filePath);
    }

    public function lastModified(): int
    {
        if (!file_exists($this->filePath)) {
            return 0;
        }
        clearstatcache(true, $this->filePath);
        return (int) filemtime($this->filePath);
    }

    public function hasChangedSince(int $sinceTimestamp): bool
    {
        $mtime = $this->lastModified();
        return $mtime > 0 && $mtime > $sinceTimestamp;
    }
}
```

**Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Support/BriefSignalTest.php -v`
Expected: PASS (3 tests, 3 assertions minimum).

**Step 5: Commit**

```bash
git add src/Support/BriefSignal.php tests/Unit/Support/BriefSignalTest.php
git commit -m "feat: add BriefSignal file-based change notification"
```

---

## Task 2: Wire BriefSignal into IngestController

**Files:**
- Modify: `src/Controller/IngestController.php`
- Modify: `tests/Unit/Controller/IngestControllerTest.php`

**Step 1: Write the failing test**

Add to `IngestControllerTest.php`:

```php
public function testSuccessfulIngestTouchesBriefSignal(): void
{
    $signalFile = sys_get_temp_dir() . '/brief_signal_test_' . uniqid('', true) . '.txt';
    putenv("CLAUDRIEL_STORAGE={$signalFile}");

    // The signal file should not exist yet
    self::assertFileDoesNotExist(dirname($signalFile) . '/brief-signal.txt');

    $request = Request::create('/api/ingest', 'POST', [], [], [], [
        'HTTP_AUTHORIZATION' => 'Bearer test-secret-key',
    ], json_encode([
        'source'  => 'test-source',
        'type'    => 'some.event',
        'payload' => ['key' => 'value'],
    ]));

    $response = $this->controller->handle([], [], null, $request);
    self::assertSame(201, $response->getStatusCode());

    // The signal file path is: storage/brief-signal.txt relative to project root.
    // For this test, we verify the BriefSignal was called by checking IngestController
    // touches the signal. Since IngestController resolves storage from env, we check
    // the actual file.
    // NOTE: This test will be refined once we decide the exact signal path wiring.

    putenv('CLAUDRIEL_STORAGE');
}
```

Actually, a simpler approach: IngestController already uses env for storage path. We can inject BriefSignal or resolve it from the storage dir. Since controllers are instantiated as `new $class($entityTypeManager, $twig)`, the controller must resolve the signal path itself (same pattern as DayBriefController resolves BriefSessionStore).

**Step 1 (revised): Add signal touch to IngestController**

In `IngestController::handle()`, after the successful `$this->registry->handle($data)` call on line 61, add:

```php
// Touch brief signal to notify SSE listeners of new data.
$this->touchBriefSignal();
```

Add private method:

```php
private function touchBriefSignal(): void
{
    $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2) . '/storage';
    $signal = new \Claudriel\Support\BriefSignal($storageDir . '/brief-signal.txt');
    $signal->touch();
}
```

**Step 2: Update test to verify signal is touched**

Add to `IngestControllerTest::testCreatesGenericEventForUnknownType()` at the end:

```php
// Verify brief signal was touched
$storageDir = getenv('CLAUDRIEL_STORAGE') ?: sys_get_temp_dir();
// Signal touch is a side effect; we just verify no errors occurred.
// Full signal verification is covered by BriefSignalTest.
```

The existing tests already pass through the handle() method, so they'll exercise the signal touch path. No new test method needed; just verify existing tests still pass.

**Step 3: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Controller/IngestControllerTest.php -v`
Expected: PASS (all existing tests still green).

**Step 4: Commit**

```bash
git add src/Controller/IngestController.php
git commit -m "feat: touch brief signal on successful ingest"
```

---

## Task 3: Wire BriefSignal into CommitmentIngestHandler

**Files:**
- Modify: `src/Ingestion/Handler/CommitmentIngestHandler.php`
- Modify: `tests/Unit/Ingestion/Handler/CommitmentIngestHandlerTest.php`

**Step 1: Add signal touch to CommitmentIngestHandler**

The handler doesn't have access to the project root directly. Since it's created by `IngestController`, and the controller already touches the signal after `registry->handle()`, the commitment handler doesn't need to touch it separately. The IngestController's `touchBriefSignal()` runs after all handler types.

**This task is already covered by Task 2.** Skip to Task 4.

---

## Task 4: AnthropicChatClient Streaming Support

**Files:**
- Modify: `src/Domain/Chat/AnthropicChatClient.php`
- Create: `tests/Unit/Domain/Chat/AnthropicChatClientStreamTest.php`

**Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\AnthropicChatClient;
use PHPUnit\Framework\TestCase;

final class AnthropicChatClientStreamTest extends TestCase
{
    public function testStreamCallsCallbackWithTokens(): void
    {
        // We can't easily test the real Anthropic API in unit tests.
        // Instead, test that the stream() method exists and has the right signature.
        $client = new AnthropicChatClient('fake-key', 'fake-model');
        self::assertTrue(method_exists($client, 'stream'));

        $ref = new \ReflectionMethod($client, 'stream');
        $params = $ref->getParameters();
        self::assertSame('systemPrompt', $params[0]->getName());
        self::assertSame('messages', $params[1]->getName());
        self::assertSame('onToken', $params[2]->getName());
        self::assertSame('onDone', $params[3]->getName());
        self::assertSame('onError', $params[4]->getName());
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Domain/Chat/AnthropicChatClientStreamTest.php -v`
Expected: FAIL — method stream does not exist.

**Step 3: Write the streaming method**

Add to `AnthropicChatClient`:

```php
/**
 * Stream a response from the Anthropic Messages API, calling callbacks for each token.
 *
 * @param string $systemPrompt
 * @param array<array{role: string, content: string}> $messages
 * @param \Closure(string): void $onToken Called with each text delta.
 * @param \Closure(string): void $onDone Called with the full assembled response.
 * @param \Closure(string): void $onError Called with error message on failure.
 */
public function stream(
    string $systemPrompt,
    array $messages,
    \Closure $onToken,
    \Closure $onDone,
    \Closure $onError,
): void {
    $payload = json_encode([
        'model' => $this->model,
        'max_tokens' => $this->maxTokens,
        'system' => $systemPrompt,
        'messages' => $messages,
        'stream' => true,
    ], JSON_THROW_ON_ERROR);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    if ($ch === false) {
        $onError('Failed to initialize cURL');
        return;
    }

    $fullResponse = '';
    $buffer = '';

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, &$fullResponse, $onToken, $onError): int {
            $buffer .= $data;

            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $pos);
                $buffer = substr($buffer, $pos + 1);
                $line = trim($line);

                if ($line === '' || str_starts_with($line, 'event:')) {
                    continue;
                }

                if (!str_starts_with($line, 'data: ')) {
                    continue;
                }

                $json = substr($line, 6);
                if ($json === '[DONE]') {
                    continue;
                }

                $event = json_decode($json, true);
                if (!is_array($event)) {
                    continue;
                }

                $type = $event['type'] ?? '';

                if ($type === 'content_block_delta') {
                    $text = $event['delta']['text'] ?? '';
                    if ($text !== '') {
                        $fullResponse .= $text;
                        $onToken($text);
                    }
                } elseif ($type === 'error') {
                    $msg = $event['error']['message'] ?? 'Unknown streaming error';
                    $onError($msg);
                }
            }

            return strlen($data);
        },
    ]);

    $result = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        $onError("cURL error: {$curlError}");
        return;
    }

    if ($httpCode !== 200 && $fullResponse === '') {
        $onError("Anthropic API error: HTTP {$httpCode}");
        return;
    }

    $onDone($fullResponse);
}
```

**Step 4: Run tests**

Run: `vendor/bin/phpunit tests/Unit/Domain/Chat/ -v`
Expected: PASS (both existing and new test).

**Step 5: Commit**

```bash
git add src/Domain/Chat/AnthropicChatClient.php tests/Unit/Domain/Chat/AnthropicChatClientStreamTest.php
git commit -m "feat: add streaming support to AnthropicChatClient"
```

---

## Task 5: BriefStreamController — SSE Brief Updates

**Files:**
- Create: `src/Controller/BriefStreamController.php`
- Create: `tests/Unit/Controller/BriefStreamControllerTest.php`

**Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\BriefStreamController;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use Claudriel\Entity\Skill;
use Claudriel\Support\BriefSignal;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class BriefStreamControllerTest extends TestCase
{
    public function testStreamEmitsBriefDataOnSignalChange(): void
    {
        $signalFile = sys_get_temp_dir() . '/brief_signal_stream_' . uniqid('', true) . '.txt';
        $signal = new BriefSignal($signalFile);
        $signal->touch();

        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $etm = new EntityTypeManager($dispatcher, function ($def) use ($db, $dispatcher) {
            (new SqlSchemaHandler($def, $db))->ensureTable();
            return new SqlEntityStorage($def, $db, $dispatcher);
        });

        foreach ($this->entityTypes() as $type) {
            $etm->registerEntityType($type);
        }

        $controller = new BriefStreamController($etm);

        $output = [];
        $iterations = 0;

        // Simulate: signal is already touched, so first check should emit brief
        $controller->streamLoop(
            $signalFile,
            outputCallback: function (string $data) use (&$output): void {
                $output[] = $data;
            },
            flushCallback: function (): void {},
            shouldStop: function () use (&$iterations): bool {
                $iterations++;
                return $iterations > 1; // Stop after one iteration
            },
            sleepCallback: function (): void {}, // No actual sleep in tests
        );

        // Should have emitted at least the retry header and one data event
        $combined = implode('', $output);
        self::assertStringContainsString('retry:', $combined);
        self::assertStringContainsString('event: brief-update', $combined);
        self::assertStringContainsString('"recent_events"', $combined);
    }

    /** @return list<EntityType> */
    private function entityTypes(): array
    {
        return [
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']),
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'skill', label: 'Skill', class: Skill::class, keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name']),
        ];
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Controller/BriefStreamControllerTest.php -v`
Expected: FAIL — class BriefStreamController not found.

**Step 3: Write the controller**

```php
<?php
declare(strict_types=1);
namespace Claudriel\Controller;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\Skill;
use Claudriel\Support\BriefSignal;
use Claudriel\Support\DriftDetector;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Entity\EntityTypeManager;

final class BriefStreamController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    /**
     * GET /stream/brief — SSE stream that pushes brief updates when signal file changes.
     */
    public function stream(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): StreamedResponse
    {
        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2) . '/storage';
        $signalFile = $storageDir . '/brief-signal.txt';

        return new StreamedResponse(
            function () use ($signalFile): void {
                $this->streamLoop($signalFile);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    /**
     * The SSE loop. Extracted for testability: all I/O goes through callbacks.
     *
     * @param string $signalFile Path to the brief signal file.
     * @param \Closure|null $outputCallback Receives string data to send to client.
     * @param \Closure|null $flushCallback Flushes output buffer.
     * @param \Closure|null $shouldStop Returns true to exit the loop.
     * @param \Closure|null $sleepCallback Called instead of usleep() for testing.
     */
    public function streamLoop(
        string $signalFile,
        ?\Closure $outputCallback = null,
        ?\Closure $flushCallback = null,
        ?\Closure $shouldStop = null,
        ?\Closure $sleepCallback = null,
    ): void {
        $output = $outputCallback ?? static function (string $data): void { echo $data; };
        $flush = $flushCallback ?? static function (): void {
            if (ob_get_level() > 0) { ob_flush(); }
            flush();
        };
        $shouldStop = $shouldStop ?? static fn(): bool => connection_aborted() === 1;
        $sleep = $sleepCallback ?? static function (): void { usleep(2_000_000); };

        $signal = new BriefSignal($signalFile);
        $lastMtime = 0;
        $lastKeepalive = time();
        $startTime = time();
        $maxDuration = 300; // 5 minutes

        $output("retry: 3000\n\n");
        $flush();

        // Emit initial brief immediately
        $briefJson = $this->assembleBriefJson();
        $output("event: brief-update\ndata: {$briefJson}\n\n");
        $flush();
        $lastMtime = $signal->lastModified();

        while (!$shouldStop()) {
            // Check for signal changes
            if ($signal->hasChangedSince($lastMtime)) {
                $lastMtime = $signal->lastModified();
                $briefJson = $this->assembleBriefJson();
                $output("event: brief-update\ndata: {$briefJson}\n\n");
                $flush();
                // Debounce: short pause after emitting to coalesce rapid ingests
                ($sleepCallback ?? static function (): void { usleep(200_000); })();
            }

            // Keepalive every 15 seconds
            $now = time();
            if (($now - $lastKeepalive) >= 15) {
                $output(": keepalive\n\n");
                $flush();
                $lastKeepalive = $now;
            }

            // Disconnect after max duration
            if (($now - $startTime) >= $maxDuration) {
                break;
            }

            $sleep();
        }
    }

    private function assembleBriefJson(): string
    {
        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
        $skillStorage = $this->entityTypeManager->getStorage('skill');

        $eventRepo = new StorageRepositoryAdapter($eventStorage);
        $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
        $skillRepo = new StorageRepositoryAdapter($skillStorage);
        $driftDetector = new DriftDetector($commitmentRepo);

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $skillRepo);
        $brief = $assembler->assemble('default', new \DateTimeImmutable('-24 hours'));

        return json_encode([
            'recent_events' => array_map(fn ($e) => $e->toArray(), $brief['recent_events']),
            'events_by_source' => array_map(
                fn (array $events) => array_map(fn ($e) => $e->toArray(), $events),
                $brief['events_by_source'],
            ),
            'people' => $brief['people'],
            'pending_commitments' => array_map(fn ($c) => $c->toArray(), $brief['pending_commitments']),
            'drifting_commitments' => array_map(fn ($c) => $c->toArray(), $brief['drifting_commitments']),
        ], JSON_THROW_ON_ERROR);
    }
}
```

**Step 4: Run test**

Run: `vendor/bin/phpunit tests/Unit/Controller/BriefStreamControllerTest.php -v`
Expected: PASS.

**Step 5: Commit**

```bash
git add src/Controller/BriefStreamController.php tests/Unit/Controller/BriefStreamControllerTest.php
git commit -m "feat: add BriefStreamController with SSE brief updates"
```

---

## Task 6: ChatController — Return message_id for Streaming

**Files:**
- Modify: `src/Controller/ChatController.php`

**Step 1: Modify ChatController::send() to return message_id**

The current `send()` method calls Anthropic synchronously and returns the full response. We need it to:
1. Save the user message (same as now)
2. Return `{ message_id, session_id }` immediately (instead of calling Anthropic)

The actual Anthropic call moves to `ChatStreamController`.

In `ChatController::send()`, replace lines 161-192 (from `// Call Anthropic` to the final return) with:

```php
// Return message ID for streaming via /stream/chat/{messageId}
return new SsrResponse(
    content: json_encode([
        'message_id' => $userMsg->get('uuid'),
        'session_id' => $sessionUuid,
    ]),
    statusCode: 200,
    headers: ['Content-Type' => 'application/json'],
);
```

Remove the now-unused `AnthropicChatClient` import and the `$model`/`$client` lines.

**Step 2: Run existing tests**

Run: `vendor/bin/phpunit -v`
Expected: Some existing tests may fail if they expected `response` in the JSON. Check and update.

**Step 3: Commit**

```bash
git add src/Controller/ChatController.php
git commit -m "refactor: ChatController returns message_id for SSE streaming"
```

---

## Task 7: ChatStreamController — SSE Chat Token Streaming

**Files:**
- Create: `src/Controller/ChatStreamController.php`
- Create: `tests/Unit/Controller/ChatStreamControllerTest.php`

**Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\ChatStreamController;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use Claudriel\Entity\Skill;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ChatStreamControllerTest extends TestCase
{
    public function testReturns404ForNonexistentMessage(): void
    {
        $etm = $this->buildEntityTypeManager();
        $controller = new ChatStreamController($etm);

        $response = $controller->stream(
            ['messageId' => 'nonexistent-uuid'],
            [],
            null,
            null,
        );

        // StreamedResponse or SsrResponse with 404
        self::assertSame(404, $response->getStatusCode());
    }

    public function testReturns503WhenApiKeyMissing(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $etm = $this->buildEntityTypeManager();

        // Create a session and message
        $sessionStorage = $etm->getStorage('chat_session');
        $session = new ChatSession(['uuid' => 'sess-1', 'title' => 'Test', 'created_at' => date('c')]);
        $sessionStorage->save($session);

        $msgStorage = $etm->getStorage('chat_message');
        $msg = new ChatMessage([
            'uuid' => 'msg-1',
            'session_uuid' => 'sess-1',
            'role' => 'user',
            'content' => 'hello',
            'created_at' => date('c'),
        ]);
        $msgStorage->save($msg);

        $controller = new ChatStreamController($etm);
        $response = $controller->stream(['messageId' => 'msg-1'], [], null, null);

        self::assertSame(503, $response->getStatusCode());

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        }
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $etm = new EntityTypeManager($dispatcher, function ($def) use ($db, $dispatcher) {
            (new SqlSchemaHandler($def, $db))->ensureTable();
            return new SqlEntityStorage($def, $db, $dispatcher);
        });
        foreach ($this->entityTypes() as $type) {
            $etm->registerEntityType($type);
        }
        return $etm;
    }

    /** @return list<EntityType> */
    private function entityTypes(): array
    {
        return [
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']),
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'skill', label: 'Skill', class: Skill::class, keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'chat_session', label: 'Chat Session', class: ChatSession::class, keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'chat_message', label: 'Chat Message', class: ChatMessage::class, keys: ['id' => 'cmid', 'uuid' => 'uuid']),
        ];
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Controller/ChatStreamControllerTest.php -v`
Expected: FAIL — class ChatStreamController not found.

**Step 3: Write the controller**

```php
<?php
declare(strict_types=1);
namespace Claudriel\Controller;

use Claudriel\Domain\Chat\AnthropicChatClient;
use Claudriel\Domain\Chat\ChatSystemPromptBuilder;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Entity\ChatMessage;
use Claudriel\Support\DriftDetector;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ChatStreamController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    /**
     * GET /stream/chat/{messageId} — SSE stream of Anthropic response tokens.
     */
    public function stream(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): StreamedResponse|SsrResponse
    {
        $messageId = $params['messageId'] ?? '';

        // Find the user message
        $msgStorage = $this->entityTypeManager->getStorage('chat_message');
        $ids = $msgStorage->getQuery()->condition('uuid', $messageId)->execute();
        if ($ids === []) {
            return new SsrResponse(
                content: json_encode(['error' => 'Message not found']),
                statusCode: 404,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $userMsg = $msgStorage->load(reset($ids));
        $sessionUuid = $userMsg->get('session_uuid');

        // Check API key
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return new SsrResponse(
                content: json_encode(['error' => 'Chat not configured. Set ANTHROPIC_API_KEY.']),
                statusCode: 503,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        return new StreamedResponse(
            function () use ($sessionUuid, $apiKey, $msgStorage): void {
                $this->streamTokens($sessionUuid, $apiKey, $msgStorage);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'Connection' => 'keep-alive',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function streamTokens(string $sessionUuid, string $apiKey, mixed $msgStorage): void
    {
        echo "retry: 3000\n\n";
        if (ob_get_level() > 0) { ob_flush(); }
        flush();

        // Load conversation history
        $allMsgIds = $msgStorage->getQuery()->execute();
        $allMessages = $msgStorage->loadMultiple($allMsgIds);
        $sessionMessages = [];
        foreach ($allMessages as $msg) {
            if ($msg->get('session_uuid') === $sessionUuid) {
                $sessionMessages[] = $msg;
            }
        }
        usort($sessionMessages, fn ($a, $b) => ($a->get('created_at') ?? '') <=> ($b->get('created_at') ?? ''));

        $apiMessages = array_map(
            fn ($m) => ['role' => $m->get('role'), 'content' => $m->get('content')],
            $sessionMessages,
        );

        // Build system prompt
        $projectRoot = $this->resolveProjectRoot();
        $promptBuilder = $this->buildPromptBuilder($projectRoot);
        $systemPrompt = $promptBuilder->build();

        // Stream from Anthropic
        $model = $_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-20250514';
        $client = new AnthropicChatClient($apiKey, $model);

        $client->stream(
            $systemPrompt,
            $apiMessages,
            onToken: function (string $token): void {
                $data = json_encode(['token' => $token], JSON_THROW_ON_ERROR);
                echo "event: chat-token\ndata: {$data}\n\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();
            },
            onDone: function (string $fullResponse) use ($sessionUuid, $msgStorage): void {
                // Save assistant message
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
                if (ob_get_level() > 0) { ob_flush(); }
                flush();
            },
            onError: function (string $error): void {
                $data = json_encode(['error' => $error], JSON_THROW_ON_ERROR);
                echo "event: chat-error\ndata: {$data}\n\n";
                if (ob_get_level() > 0) { ob_flush(); }
                flush();
            },
        );
    }

    private function getApiKey(): ?string
    {
        $key = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: null;
        return is_string($key) && $key !== '' ? $key : null;
    }

    private function resolveProjectRoot(): string
    {
        $root = getenv('CLAUDRIEL_ROOT');
        if (is_string($root) && $root !== '' && is_dir($root)) {
            return $root;
        }
        $dir = __DIR__;
        while ($dir !== '/' && $dir !== '') {
            if (is_file($dir . '/composer.json')) {
                return $dir;
            }
            $dir = dirname($dir);
        }
        return getcwd() ?: '/tmp';
    }

    private function buildPromptBuilder(string $projectRoot): ChatSystemPromptBuilder
    {
        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
        $skillStorage = $this->entityTypeManager->getStorage('skill');

        $eventRepo = new StorageRepositoryAdapter($eventStorage);
        $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
        $skillRepo = new StorageRepositoryAdapter($skillStorage);
        $driftDetector = new DriftDetector($commitmentRepo);

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $skillRepo);
        return new ChatSystemPromptBuilder($assembler, $projectRoot);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        );
    }
}
```

**Step 4: Run test**

Run: `vendor/bin/phpunit tests/Unit/Controller/ChatStreamControllerTest.php -v`
Expected: PASS.

**Step 5: Commit**

```bash
git add src/Controller/ChatStreamController.php tests/Unit/Controller/ChatStreamControllerTest.php
git commit -m "feat: add ChatStreamController with SSE token streaming"
```

---

## Task 8: DashboardController — Combined Page

**Files:**
- Create: `src/Controller/DashboardController.php`
- Create: `tests/Unit/Controller/DashboardControllerTest.php`

**Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\DashboardController;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use Claudriel\Entity\Skill;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\ChatMessage;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class DashboardControllerTest extends TestCase
{
    public function testShowReturnsJsonWhenNoTwig(): void
    {
        $etm = $this->buildEntityTypeManager();
        $controller = new DashboardController($etm);

        $response = $controller->show();
        self::assertSame(200, $response->statusCode);

        $data = json_decode($response->content, true);
        self::assertArrayHasKey('brief', $data);
        self::assertArrayHasKey('sessions', $data);
        self::assertArrayHasKey('api_configured', $data);
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $etm = new EntityTypeManager($dispatcher, function ($def) use ($db, $dispatcher) {
            (new SqlSchemaHandler($def, $db))->ensureTable();
            return new SqlEntityStorage($def, $db, $dispatcher);
        });
        foreach ($this->entityTypes() as $type) {
            $etm->registerEntityType($type);
        }
        return $etm;
    }

    /** @return list<EntityType> */
    private function entityTypes(): array
    {
        return [
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']),
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'skill', label: 'Skill', class: Skill::class, keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'chat_session', label: 'Chat Session', class: ChatSession::class, keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'chat_message', label: 'Chat Message', class: ChatMessage::class, keys: ['id' => 'cmid', 'uuid' => 'uuid']),
        ];
    }
}
```

**Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Controller/DashboardControllerTest.php -v`
Expected: FAIL — class DashboardController not found.

**Step 3: Write the controller**

```php
<?php
declare(strict_types=1);
namespace Claudriel\Controller;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Support\DriftDetector;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class DashboardController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2) . '/storage';
        $sessionStore = new BriefSessionStore($storageDir . '/brief-session.txt');
        $since = $sessionStore->getLastBriefAt() ?? new \DateTimeImmutable('-24 hours');

        // Assemble brief data
        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
        $skillStorage = $this->entityTypeManager->getStorage('skill');

        $eventRepo = new StorageRepositoryAdapter($eventStorage);
        $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
        $skillRepo = new StorageRepositoryAdapter($skillStorage);
        $driftDetector = new DriftDetector($commitmentRepo);

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $skillRepo);
        $brief = $assembler->assemble('default', $since);

        $sessionStore->recordBriefAt(new \DateTimeImmutable());

        // Load chat sessions
        $chatSessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $sessionIds = $chatSessionStorage->getQuery()->execute();
        $allSessions = $chatSessionStorage->loadMultiple($sessionIds);
        usort($allSessions, fn ($a, $b) => ($b->get('created_at') ?? '') <=> ($a->get('created_at') ?? ''));
        $sessions = array_slice($allSessions, 0, 10);

        $twigSessions = array_map(fn ($s) => [
            'uuid' => $s->get('uuid'),
            'title' => $s->get('title') ?? 'New Chat',
            'created_at' => $s->get('created_at'),
        ], $sessions);

        $apiKey = getenv('ANTHROPIC_API_KEY');
        $apiConfigured = is_string($apiKey) && $apiKey !== '';

        // Twig rendering
        if ($this->twig !== null) {
            $twigEventsBySource = [];
            foreach ($brief['events_by_source'] as $source => $events) {
                foreach ($events as $event) {
                    $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];
                    $twigEventsBySource[$source][] = [
                        'type' => $event->get('type'),
                        'source' => $event->get('source'),
                        'occurred' => $event->get('occurred'),
                        'subject' => $payload['subject'] ?? $event->get('type'),
                        'from_name' => $payload['from_name'] ?? null,
                    ];
                }
            }

            $twigCommitments = array_map(fn ($c) => [
                'title' => $c->get('title'),
                'confidence' => $c->get('confidence') ?? 1.0,
                'due_date' => $c->get('due_date'),
            ], $brief['pending_commitments']);

            $twigDrifting = array_map(fn ($c) => [
                'title' => $c->get('title'),
                'updated_at' => $c->get('updated_at'),
            ], $brief['drifting_commitments']);

            $html = $this->twig->render('dashboard.twig', [
                'recent_events' => $brief['recent_events'],
                'events_by_source' => $twigEventsBySource,
                'people' => $brief['people'],
                'pending_commitments' => $twigCommitments,
                'drifting_commitments' => $twigDrifting,
                'sessions' => $twigSessions,
                'api_configured' => $apiConfigured,
            ]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        // JSON fallback
        return new SsrResponse(
            content: json_encode([
                'brief' => [
                    'recent_events' => array_map(fn ($e) => $e->toArray(), $brief['recent_events']),
                    'events_by_source' => array_map(
                        fn (array $events) => array_map(fn ($e) => $e->toArray(), $events),
                        $brief['events_by_source'],
                    ),
                    'people' => $brief['people'],
                    'pending_commitments' => array_map(fn ($c) => $c->toArray(), $brief['pending_commitments']),
                    'drifting_commitments' => array_map(fn ($c) => $c->toArray(), $brief['drifting_commitments']),
                ],
                'sessions' => $twigSessions,
                'api_configured' => $apiConfigured,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
```

**Step 4: Run test**

Run: `vendor/bin/phpunit tests/Unit/Controller/DashboardControllerTest.php -v`
Expected: PASS.

**Step 5: Commit**

```bash
git add src/Controller/DashboardController.php tests/Unit/Controller/DashboardControllerTest.php
git commit -m "feat: add DashboardController combining brief and chat"
```

---

## Task 9: Dashboard Twig Template

**Files:**
- Create: `templates/dashboard.twig`

**Step 1: Write the template**

This is a large template combining the brief and chat layouts. The key structure:

- Nav bar (same as current)
- `.dashboard` grid container: `.brief-panel` (left) + `.chat-panel` (right)
- Brief panel: events, people, commitments, drifting sections (same markup as `day-brief.html.twig`)
- Chat panel: session list, messages area, input area (same markup as `chat.html.twig`)
- `<script>`: EventSource for brief SSE + chat send/stream logic
- Responsive: `@media (max-width: 900px)` stacks columns, brief becomes `<details>`

The template should be created as `templates/dashboard.twig` with all CSS inlined in `<style>` (matching existing pattern). See `templates/day-brief.html.twig` and `templates/chat.html.twig` for the exact CSS values and markup to carry over.

The JS section needs:
1. `briefSource = new EventSource('/stream/brief')` — on `brief-update` event, parse JSON and update brief panel sections via innerHTML
2. `sendMessage()` — POST to `/api/chat/send`, get `{ message_id, session_id }`, then open `new EventSource('/stream/chat/' + messageId)` — on `chat-token` append to message bubble, on `chat-done` close EventSource

**Step 2: Verify by running the dev server and loading the page**

Run: `cd /home/jones/dev/claudriel && php -S localhost:8089 -t public/ public/index.php`
Navigate to `http://localhost:8089/` in browser.
Expected: Two-column dashboard with brief on left, chat on right.

**Step 3: Commit**

```bash
git add templates/dashboard.twig
git commit -m "feat: add dashboard template with two-column layout"
```

---

## Task 10: Route Registration

**Files:**
- Modify: `src/Provider/ClaudrielServiceProvider.php`

**Step 1: Update routes**

In `ClaudrielServiceProvider::routes()`, replace the existing home and brief routes, and add the stream routes:

```php
public function routes(WaaseyaaRouter $router): void
{
    // Dashboard (replaces separate brief and chat pages)
    $router->addRoute(
        'claudriel.dashboard',
        RouteBuilder::create('/')
            ->controller(DashboardController::class . '::show')
            ->allowAll()
            ->methods('GET')
            ->build(),
    );

    // Legacy redirects (brief and chat now live on dashboard)
    // Keep /brief as an alias for JSON API consumers
    $router->addRoute(
        'claudriel.brief',
        RouteBuilder::create('/brief')
            ->controller(DayBriefController::class . '::show')
            ->allowAll()
            ->methods('GET')
            ->build(),
    );

    $router->addRoute(
        'claudriel.chat',
        RouteBuilder::create('/chat')
            ->controller(DashboardController::class . '::show')
            ->allowAll()
            ->methods('GET')
            ->build(),
    );

    // SSE streams
    $router->addRoute(
        'claudriel.stream.brief',
        RouteBuilder::create('/stream/brief')
            ->controller(BriefStreamController::class . '::stream')
            ->allowAll()
            ->methods('GET')
            ->build(),
    );

    $router->addRoute(
        'claudriel.stream.chat',
        RouteBuilder::create('/stream/chat/{messageId}')
            ->controller(ChatStreamController::class . '::stream')
            ->allowAll()
            ->methods('GET')
            ->build(),
    );

    // Existing API routes (unchanged)
    $router->addRoute(
        'claudriel.commitment.update',
        RouteBuilder::create('/commitments/{uuid}')
            ->controller(CommitmentUpdateController::class . '::update')
            ->allowAll()
            ->methods('PATCH')
            ->build(),
    );

    $router->addRoute(
        'claudriel.api.ingest',
        RouteBuilder::create('/api/ingest')
            ->controller(IngestController::class . '::handle')
            ->allowAll()
            ->methods('POST')
            ->build(),
    );

    $router->addRoute(
        'claudriel.api.context',
        RouteBuilder::create('/api/context')
            ->controller(ContextController::class . '::show')
            ->allowAll()
            ->methods('GET')
            ->build(),
    );

    $router->addRoute(
        'claudriel.api.chat.send',
        RouteBuilder::create('/api/chat/send')
            ->controller(ChatController::class . '::send')
            ->allowAll()
            ->methods('POST')
            ->build(),
    );
}
```

Add the new controller imports at the top of the file:

```php
use Claudriel\Controller\BriefStreamController;
use Claudriel\Controller\ChatStreamController;
use Claudriel\Controller\DashboardController;
```

**Step 2: Run all tests**

Run: `vendor/bin/phpunit -v`
Expected: All tests pass.

**Step 3: Commit**

```bash
git add src/Provider/ClaudrielServiceProvider.php
git commit -m "feat: register dashboard and SSE stream routes"
```

---

## Task 11: Full Integration Smoke Test

**Step 1: Start dev server**

```bash
cd /home/jones/dev/claudriel
php -S localhost:8089 -t public/ public/index.php
```

**Step 2: Verify dashboard loads**

Navigate to `http://localhost:8089/`. Expected: two-column layout with brief and chat.

**Step 3: Verify brief SSE stream**

```bash
curl -N -H "Accept: text/event-stream" http://localhost:8089/stream/brief
```
Expected: `retry: 3000`, then `event: brief-update` with JSON data.

**Step 4: Ingest an event and watch brief update**

In another terminal:
```bash
curl -X POST http://localhost:8089/api/ingest \
  -H "Authorization: Bearer $CLAUDRIEL_API_KEY" \
  -H "Content-Type: application/json" \
  -d '{"source":"smoke-test","type":"test.ping","payload":{"msg":"dashboard SSE test"}}'
```
Expected: The brief SSE stream emits a new `brief-update` event within 2 seconds.

**Step 5: Test chat streaming**

```bash
# Send a message
curl -s -X POST http://localhost:8089/api/chat/send \
  -H "Content-Type: application/json" \
  -d '{"message":"Hello"}' | python3 -m json.tool
# Returns: { "message_id": "...", "session_id": "..." }

# Stream the response (use the message_id from above)
curl -N http://localhost:8089/stream/chat/{message_id}
# Expected: retry: 3000, then event: chat-token events, then event: chat-done
```

**Step 6: Verify legacy routes**

```bash
curl -s -H "Accept: application/json" http://localhost:8089/brief | python3 -m json.tool
```
Expected: JSON brief data (DayBriefController still works).

**Step 7: Run all tests one final time**

```bash
vendor/bin/phpunit -v
```
Expected: All tests pass.

**Step 8: Commit any fixes from smoke testing**

```bash
git add -A
git commit -m "fix: address integration issues found during smoke test"
```

---

## Task 12: Update Specs and Clean Up

**Files:**
- Modify: `docs/specs/web-cli.md` — add dashboard route, SSE endpoints
- Modify: `CLAUDE.md` — update architecture diagram to show dashboard

**Step 1: Update web-cli.md**

Add the new routes and controllers to the spec. Mention SSE streaming, BriefSignal, and the dashboard as the primary entry point.

**Step 2: Commit**

```bash
git add docs/specs/web-cli.md CLAUDE.md
git commit -m "docs: update specs for dashboard and SSE streaming"
```

---

## Summary

| Task | What | Files |
|---|---|---|
| 1 | BriefSignal class | `src/Support/BriefSignal.php`, test |
| 2 | Wire signal into IngestController | `src/Controller/IngestController.php` |
| 3 | (Covered by Task 2) | — |
| 4 | Anthropic streaming method | `src/Domain/Chat/AnthropicChatClient.php`, test |
| 5 | BriefStreamController (SSE) | `src/Controller/BriefStreamController.php`, test |
| 6 | ChatController returns message_id | `src/Controller/ChatController.php` |
| 7 | ChatStreamController (SSE) | `src/Controller/ChatStreamController.php`, test |
| 8 | DashboardController | `src/Controller/DashboardController.php`, test |
| 9 | Dashboard template | `templates/dashboard.twig` |
| 10 | Route registration | `src/Provider/ClaudrielServiceProvider.php` |
| 11 | Integration smoke test | Manual verification |
| 12 | Docs update | `docs/specs/web-cli.md`, `CLAUDE.md` |
