<?php

declare(strict_types=1);

namespace Claudriel\Support\Mcp;

use Waaseyaa\AI\Schema\Mcp\McpToolDefinition;
use Waaseyaa\Mcp\Bridge\ToolRegistryInterface;
use Waaseyaa\Mcp\McpController;

/**
 * Adapts McpController's manifest into the ToolRegistryInterface
 * expected by McpEndpoint.
 */
final class McpToolRegistryAdapter implements ToolRegistryInterface
{
    /** @var McpToolDefinition[]|null */
    private ?array $toolCache = null;

    public function __construct(
        private readonly McpController $controller,
    ) {}

    /** @return McpToolDefinition[] */
    public function getTools(): array
    {
        if ($this->toolCache !== null) {
            return $this->toolCache;
        }

        $manifest = $this->controller->manifest();
        $tools = [];

        foreach ($manifest['tools'] as $tool) {
            $tools[] = new McpToolDefinition(
                name: $tool['name'],
                description: $tool['description'],
                inputSchema: $this->inputSchemaForTool($tool['name']),
            );
        }

        return $this->toolCache = $tools;
    }

    public function getTool(string $name): ?McpToolDefinition
    {
        foreach ($this->getTools() as $tool) {
            if ($tool->name === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function inputSchemaForTool(string $toolName): array
    {
        return match ($toolName) {
            'search_entities', 'search_teachings' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Search query text'],
                    'entity_type' => ['type' => 'string', 'description' => 'Filter by entity type ID'],
                    'limit' => ['type' => 'integer', 'description' => 'Max results (default 10)'],
                ],
                'required' => ['query'],
            ],
            'ai_discover' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string', 'description' => 'Discovery query'],
                    'entity_type' => ['type' => 'string', 'description' => 'Filter by entity type'],
                    'depth' => ['type' => 'integer', 'description' => 'Graph traversal depth'],
                ],
                'required' => ['query'],
            ],
            'get_entity' => [
                'type' => 'object',
                'properties' => [
                    'entity_type' => ['type' => 'string', 'description' => 'Entity type ID'],
                    'id' => ['type' => ['string', 'integer'], 'description' => 'Entity ID'],
                ],
                'required' => ['entity_type', 'id'],
            ],
            'list_entity_types' => [
                'type' => 'object',
                'properties' => new \stdClass,
            ],
            'traverse_relationships' => [
                'type' => 'object',
                'properties' => [
                    'entity_type' => ['type' => 'string', 'description' => 'Source entity type'],
                    'entity_id' => ['type' => ['string', 'integer'], 'description' => 'Source entity ID'],
                    'direction' => ['type' => 'string', 'enum' => ['outbound', 'inbound', 'both'], 'description' => 'Traversal direction'],
                    'relationship_types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Filter by relationship type'],
                ],
                'required' => ['entity_type', 'entity_id'],
            ],
            'get_related_entities' => [
                'type' => 'object',
                'properties' => [
                    'entity_type' => ['type' => 'string', 'description' => 'Source entity type'],
                    'entity_id' => ['type' => ['string', 'integer'], 'description' => 'Source entity ID'],
                    'direction' => ['type' => 'string', 'enum' => ['outbound', 'inbound', 'both']],
                    'include_edges' => ['type' => 'boolean', 'description' => 'Include edge payloads'],
                ],
                'required' => ['entity_type', 'entity_id'],
            ],
            'get_knowledge_graph' => [
                'type' => 'object',
                'properties' => [
                    'entity_type' => ['type' => 'string', 'description' => 'Entity type'],
                    'entity_id' => ['type' => ['string', 'integer'], 'description' => 'Entity ID'],
                ],
                'required' => ['entity_type', 'entity_id'],
            ],
            'editorial_transition' => [
                'type' => 'object',
                'properties' => [
                    'entity_type' => ['type' => 'string', 'description' => 'Entity type'],
                    'id' => ['type' => ['string', 'integer'], 'description' => 'Entity ID'],
                    'transition' => ['type' => 'string', 'description' => 'Transition name (e.g. publish, archive)'],
                ],
                'required' => ['entity_type', 'id', 'transition'],
            ],
            'editorial_validate' => [
                'type' => 'object',
                'properties' => [
                    'entity_type' => ['type' => 'string', 'description' => 'Entity type'],
                    'id' => ['type' => ['string', 'integer'], 'description' => 'Entity ID'],
                    'transition' => ['type' => 'string', 'description' => 'Transition to validate'],
                ],
                'required' => ['entity_type', 'id', 'transition'],
            ],
            'editorial_publish' => [
                'type' => 'object',
                'properties' => [
                    'entity_type' => ['type' => 'string', 'description' => 'Entity type'],
                    'id' => ['type' => ['string', 'integer'], 'description' => 'Entity ID'],
                ],
                'required' => ['entity_type', 'id'],
            ],
            'editorial_archive' => [
                'type' => 'object',
                'properties' => [
                    'entity_type' => ['type' => 'string', 'description' => 'Entity type'],
                    'id' => ['type' => ['string', 'integer'], 'description' => 'Entity ID'],
                ],
                'required' => ['entity_type', 'id'],
            ],
            default => [
                'type' => 'object',
                'properties' => new \stdClass,
            ],
        };
    }
}
