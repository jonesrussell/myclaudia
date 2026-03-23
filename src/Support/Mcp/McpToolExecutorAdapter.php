<?php

declare(strict_types=1);

namespace Claudriel\Support\Mcp;

use Waaseyaa\Mcp\Bridge\ToolExecutorInterface;
use Waaseyaa\Mcp\McpController;

/**
 * Adapts McpController's handleRpc into the ToolExecutorInterface
 * expected by McpEndpoint.
 *
 * Translates a tools/call invocation into an RPC dispatch through
 * McpController, which handles entity CRUD, traversal, editorial,
 * and discovery tools.
 */
final readonly class McpToolExecutorAdapter implements ToolExecutorInterface
{
    public function __construct(
        private McpController $controller,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array{content: array<int, array{type: string, text: string}>, isError?: bool}
     */
    public function execute(string $toolName, array $arguments): array
    {
        $rpcResult = $this->controller->handleRpc([
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments,
            ],
        ]);

        if (isset($rpcResult['error'])) {
            return [
                'content' => [
                    ['type' => 'text', 'text' => \json_encode($rpcResult['error'], \JSON_THROW_ON_ERROR)],
                ],
                'isError' => true,
            ];
        }

        $result = $rpcResult['result'] ?? [];

        if (isset($result['content']) && \is_array($result['content'])) {
            return $result;
        }

        return [
            'content' => [
                ['type' => 'text', 'text' => \json_encode($result, \JSON_THROW_ON_ERROR)],
            ],
        ];
    }
}
