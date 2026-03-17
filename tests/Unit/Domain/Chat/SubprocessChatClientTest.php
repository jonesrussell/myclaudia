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
        $script = sys_get_temp_dir().'/mock_agent_'.uniqid().'.php';
        file_put_contents($script, <<<'PHP'
        <?php
        echo json_encode(['event' => 'message', 'content' => 'Hello']) . "\n";
        echo json_encode(['event' => 'message', 'content' => ' world']) . "\n";
        echo json_encode(['event' => 'done']) . "\n";
        PHP);

        $client = new SubprocessChatClient(
            command: [PHP_BINARY, $script],
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
            onToken: function (string $token) use (&$tokens) {
                $tokens[] = $token;
            },
            onDone: function (string $full) use (&$doneResponse) {
                $doneResponse = $full;
            },
            onError: function (string $err) use (&$errors) {
                $errors[] = $err;
            },
        );

        $this->assertSame(['Hello', ' world'], $tokens);
        $this->assertSame('Hello world', $doneResponse);
        $this->assertSame([], $errors);

        unlink($script);
    }

    #[Test]
    public function stream_calls_on_error_for_nonzero_exit(): void
    {
        $script = sys_get_temp_dir().'/mock_agent_fail_'.uniqid().'.php';
        file_put_contents($script, <<<'PHP'
        <?php
        fwrite(STDERR, "Something went wrong\n");
        exit(1);
        PHP);

        $client = new SubprocessChatClient(
            command: [PHP_BINARY, $script],
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
            onError: function (string $err) use (&$errors) {
                $errors[] = $err;
            },
        );

        $this->assertNotEmpty($errors);

        unlink($script);
    }

    #[Test]
    public function stream_calls_on_progress_for_tool_events(): void
    {
        $script = sys_get_temp_dir().'/mock_agent_tools_'.uniqid().'.php';
        file_put_contents($script, <<<'PHP'
        <?php
        echo json_encode(['event' => 'tool_call', 'tool' => 'gmail_list', 'args' => ['query' => 'is:unread']]) . "\n";
        echo json_encode(['event' => 'tool_result', 'tool' => 'gmail_list', 'result' => ['count' => 3]]) . "\n";
        echo json_encode(['event' => 'message', 'content' => 'Found 3 emails']) . "\n";
        echo json_encode(['event' => 'done']) . "\n";
        PHP);

        $client = new SubprocessChatClient(
            command: [PHP_BINARY, $script],
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
            onProgress: function (array $payload) use (&$progressEvents) {
                $progressEvents[] = $payload;
            },
        );

        $this->assertCount(2, $progressEvents);
        $this->assertSame('tool_call', $progressEvents[0]['phase']);
        $this->assertSame('gmail_list', $progressEvents[0]['tool']);
        $this->assertSame('tool_result', $progressEvents[1]['phase']);

        unlink($script);
    }
}
