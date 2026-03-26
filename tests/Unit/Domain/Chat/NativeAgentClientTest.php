<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Domain\Chat\NativeAgentClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NativeAgentClient::class)]
final class NativeAgentClientTest extends TestCase
{
    #[Test]
    public function stream_emits_tokens_and_done_for_simple_text_response(): void
    {
        $client = $this->createMockClient([
            $this->buildApiResponse([
                ['type' => 'text', 'text' => 'Hello world'],
            ]),
        ]);

        $tokens = [];
        $doneResponse = null;
        $errors = [];

        $client->stream(
            systemPrompt: 'test',
            messages: [['role' => 'user', 'content' => 'hi']],
            accountId: 'acc-1',
            tenantId: 'tenant-1',
            apiBase: '',
            apiToken: '',
            onToken: function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            },
            onDone: function (string $full) use (&$doneResponse): void {
                $doneResponse = $full;
            },
            onError: function (string $err) use (&$errors): void {
                $errors[] = $err;
            },
        );

        self::assertSame(['Hello world'], $tokens);
        self::assertSame('Hello world', $doneResponse);
        self::assertSame([], $errors);
    }

    #[Test]
    public function stream_executes_tool_calls_and_continues(): void
    {
        $toolExecuted = false;

        $tool = new class($toolExecuted) implements AgentToolInterface
        {
            private bool $executed;

            public function __construct(bool &$executed)
            {
                $this->executed = &$executed;
            }

            public function definition(): array
            {
                return [
                    'name' => 'test_tool',
                    'description' => 'A test tool',
                    'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
                ];
            }

            public function execute(array $args): array
            {
                $this->executed = true;

                return ['result' => 'success'];
            }
        };

        $client = $this->createMockClient([
            // First response: tool call
            $this->buildApiResponse([
                ['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'test_tool', 'input' => new \stdClass],
            ]),
            // Second response: text after tool result
            $this->buildApiResponse([
                ['type' => 'text', 'text' => 'Tool executed successfully'],
            ]),
        ], [$tool]);

        $tokens = [];
        $progressEvents = [];
        $doneResponse = null;

        $client->stream(
            systemPrompt: 'test',
            messages: [['role' => 'user', 'content' => 'run tool']],
            accountId: 'acc-1',
            tenantId: 'tenant-1',
            apiBase: '',
            apiToken: '',
            onToken: function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            },
            onDone: function (string $full) use (&$doneResponse): void {
                $doneResponse = $full;
            },
            onError: function (string $err): void {},
            onProgress: function (array $payload) use (&$progressEvents): void {
                $progressEvents[] = $payload;
            },
        );

        self::assertTrue($toolExecuted, 'Tool should have been executed');
        self::assertSame(['Tool executed successfully'], $tokens);
        self::assertSame('Tool executed successfully', $doneResponse);
        self::assertCount(2, $progressEvents);
        self::assertSame('tool_call', $progressEvents[0]['phase']);
        self::assertSame('tool_result', $progressEvents[1]['phase']);
    }

    #[Test]
    public function stream_calls_tool_result_callback_when_tool_executes(): void
    {
        $tool = new class implements AgentToolInterface
        {
            public function definition(): array
            {
                return [
                    'name' => 'test_tool',
                    'description' => 'A test tool',
                    'input_schema' => ['type' => 'object', 'properties' => new \stdClass],
                ];
            }

            public function execute(array $args): array
            {
                return ['items' => [['uuid' => 'abc-123']]];
            }
        };

        $calls = [];
        $client = $this->createMockClient(
            [
                $this->buildApiResponse([
                    ['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'test_tool', 'input' => new \stdClass],
                ]),
                $this->buildApiResponse([
                    ['type' => 'text', 'text' => 'done'],
                ]),
            ],
            [$tool],
            function (string $toolName, mixed $result, string $tenantId) use (&$calls): void {
                $calls[] = [$toolName, $result, $tenantId];
            },
        );

        $client->stream(
            systemPrompt: 'test',
            messages: [['role' => 'user', 'content' => 'run tool']],
            accountId: 'acc-1',
            tenantId: 'tenant-1',
            apiBase: '',
            apiToken: '',
            onToken: function (string $token): void {},
            onDone: function (string $full): void {},
            onError: function (string $err): void {},
        );

        self::assertCount(1, $calls);
        self::assertSame('test_tool', $calls[0][0]);
        self::assertSame('tenant-1', $calls[0][2]);
    }

    #[Test]
    public function stream_handles_unknown_tool_gracefully(): void
    {
        $client = $this->createMockClient([
            $this->buildApiResponse([
                ['type' => 'tool_use', 'id' => 'tool-1', 'name' => 'nonexistent_tool', 'input' => new \stdClass],
            ]),
            $this->buildApiResponse([
                ['type' => 'text', 'text' => 'Tool not found'],
            ]),
        ]);

        $doneResponse = null;
        $errors = [];

        $client->stream(
            systemPrompt: 'test',
            messages: [['role' => 'user', 'content' => 'run unknown']],
            accountId: 'acc-1',
            tenantId: 'tenant-1',
            apiBase: '',
            apiToken: '',
            onToken: function (string $token): void {},
            onDone: function (string $full) use (&$doneResponse): void {
                $doneResponse = $full;
            },
            onError: function (string $err) use (&$errors): void {
                $errors[] = $err;
            },
        );

        self::assertSame('Tool not found', $doneResponse);
        self::assertSame([], $errors);
    }

    #[Test]
    public function stream_calls_on_error_when_api_fails(): void
    {
        // Return null for all API calls (simulating network failure)
        $client = $this->createMockClient([]);

        $errors = [];
        $doneResponse = null;

        $client->stream(
            systemPrompt: 'test',
            messages: [['role' => 'user', 'content' => 'hi']],
            accountId: 'acc-1',
            tenantId: 'tenant-1',
            apiBase: '',
            apiToken: '',
            onToken: function (string $token): void {},
            onDone: function (string $full) use (&$doneResponse): void {
                $doneResponse = $full;
            },
            onError: function (string $err) use (&$errors): void {
                $errors[] = $err;
            },
        );

        self::assertNotEmpty($errors);
        self::assertStringContainsString('Failed to get API response', $errors[0]);
    }

    /**
     * @param  list<string>  $responses  JSON response bodies to return in sequence
     * @param  list<AgentToolInterface>  $tools
     */
    private function createMockClient(array $responses, array $tools = [], ?\Closure $onToolResult = null): NativeAgentClient
    {
        return new class('fake-api-key', $tools, 'claude-sonnet-4-6', $responses, $onToolResult) extends NativeAgentClient
        {
            /** @var list<string> */
            private array $mockResponses;

            private int $callIndex = 0;

            /**
             * @param  list<AgentToolInterface>  $tools
             * @param  list<string>  $responses
             */
            public function __construct(string $apiKey, array $tools, string $model, array $responses, ?\Closure $onToolResult)
            {
                parent::__construct($apiKey, $tools, $model, $onToolResult);
                $this->mockResponses = $responses;
            }

            protected function httpPost(string $url, array $payload, array $headers): ?string
            {
                if ($this->callIndex >= count($this->mockResponses)) {
                    return null;
                }

                return $this->mockResponses[$this->callIndex++];
            }
        };
    }

    private function buildApiResponse(array $content, string $stopReason = 'end_turn'): string
    {
        return json_encode([
            'id' => 'msg-test-'.uniqid(),
            'type' => 'message',
            'role' => 'assistant',
            'content' => $content,
            'model' => 'claude-sonnet-4-6',
            'stop_reason' => $stopReason,
            'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
        ], JSON_THROW_ON_ERROR);
    }
}
