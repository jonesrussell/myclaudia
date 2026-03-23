<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support\Mcp;

use Claudriel\Support\Mcp\McpServiceAccount;
use Claudriel\Support\Mcp\McpToolRegistryAdapter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Access\EntityAccessHandler;
use Waaseyaa\AI\Vector\EmbeddingStorageInterface;
use Waaseyaa\Api\ResourceSerializer;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Mcp\McpController;

#[CoversClass(McpToolRegistryAdapter::class)]
final class McpToolRegistryAdapterTest extends TestCase
{
    private McpController $controller;

    protected function setUp(): void
    {
        $entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getDefinitions')->willReturn([]);

        $this->controller = new McpController(
            entityTypeManager: $entityTypeManager,
            serializer: new ResourceSerializer($entityTypeManager),
            accessHandler: new EntityAccessHandler([]),
            account: new McpServiceAccount,
            embeddingStorage: $this->createMock(EmbeddingStorageInterface::class),
        );
    }

    #[Test]
    public function get_tools_returns_all_tool_definitions(): void
    {
        $adapter = new McpToolRegistryAdapter($this->controller);
        $tools = $adapter->getTools();

        $this->assertNotEmpty($tools);

        $names = array_map(fn ($t) => $t->name, $tools);
        $this->assertContains('search_entities', $names);
        $this->assertContains('get_entity', $names);
        $this->assertContains('list_entity_types', $names);
        $this->assertContains('editorial_transition', $names);
        $this->assertContains('traverse_relationships', $names);
    }

    #[Test]
    public function get_tool_returns_matching_tool(): void
    {
        $adapter = new McpToolRegistryAdapter($this->controller);
        $tool = $adapter->getTool('get_entity');

        $this->assertNotNull($tool);
        $this->assertSame('get_entity', $tool->name);
    }

    #[Test]
    public function get_tool_returns_null_for_unknown_tool(): void
    {
        $adapter = new McpToolRegistryAdapter($this->controller);
        $tool = $adapter->getTool('nonexistent');

        $this->assertNull($tool);
    }

    #[Test]
    public function tool_definitions_include_input_schemas(): void
    {
        $adapter = new McpToolRegistryAdapter($this->controller);

        $searchTool = $adapter->getTool('search_entities');
        $this->assertNotNull($searchTool);
        $this->assertSame('object', $searchTool->inputSchema['type']);
        $this->assertContains('query', $searchTool->inputSchema['required']);

        $getTool = $adapter->getTool('get_entity');
        $this->assertNotNull($getTool);
        $this->assertContains('entity_type', $getTool->inputSchema['required']);
        $this->assertContains('id', $getTool->inputSchema['required']);
    }
}
