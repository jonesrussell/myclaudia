<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\AnthropicChatClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AnthropicChatClientTest extends TestCase
{
    #[Test]
    public function constructionSetsDefaults(): void
    {
        $client = new AnthropicChatClient('test-key');

        // The client should instantiate without error.
        // We cannot easily test the API call without mocking cURL,
        // but we verify construction succeeds.
        $this->assertInstanceOf(AnthropicChatClient::class, $client);
    }

    #[Test]
    public function constructionAcceptsCustomModel(): void
    {
        $client = new AnthropicChatClient(
            apiKey: 'test-key',
            model: 'claude-3-haiku-20240307',
            maxTokens: 1024,
        );

        $this->assertInstanceOf(AnthropicChatClient::class, $client);
    }
}
