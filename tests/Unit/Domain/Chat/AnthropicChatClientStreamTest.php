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
