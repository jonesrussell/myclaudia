<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support\Mcp;

use Claudriel\Support\Mcp\McpServiceAccount;
use Claudriel\Support\Mcp\McpToolExecutorAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\McpController;

#[CoversClass(McpToolExecutorAdapter::class)]
final class McpToolExecutorAdapterTest extends TestCase
{
    private McpController $controller;

    protected function setUp(): void
    {
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getDefinitions')->willReturn([]);
        $entityTypeManager->method('hasDefinition')->willReturn(false);

        $this->controller = new McpController(
            entityTypeManager: $entityTypeManager,
            serializer: new ResourceSerializer($entityTypeManager),
            accessHandler: new EntityAccessHandler([]),
            account: new McpServiceAccount,
            embeddingStorage: $this->createMock(EmbeddingStorageInterface::class),
        );
    }

    #[Test]
    public function execute_returns_error_for_unknown_tool(): void
    {
        $adapter = new McpToolExecutorAdapter($this->controller);
        $result = $adapter->execute('nonexistent_tool', []);

        $this->assertTrue($result['isError'] ?? false);
        $this->assertStringContainsString('Unknown tool', $result['content'][0]['text']);
    }

    #[Test]
    public function execute_list_entity_types_returns_content(): void
    {
        $adapter = new McpToolExecutorAdapter($this->controller);
        $result = $adapter->execute('list_entity_types', []);

        $this->assertArrayHasKey('content', $result);
        $this->assertNotEmpty($result['content']);
        $this->assertSame('text', $result['content'][0]['type']);
    }

    #[Test]
    public function execute_search_entities_with_missing_query_returns_error(): void
    {
        $adapter = new McpToolExecutorAdapter($this->controller);
        $result = $adapter->execute('search_entities', []);

        // Should return error since no query provided
        $this->assertArrayHasKey('content', $result);
    }
}
