<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class WorkspaceApiController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function list(): SsrResponse
    {
        try {
            $storage = $this->entityTypeManager->getStorage('workspace');
        } catch (\Throwable) {
            return new SsrResponse(
                content: json_encode(['workspaces' => []], JSON_THROW_ON_ERROR),
                statusCode: 200,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $ids = $storage->getQuery()->execute();
        $entities = $storage->loadMultiple($ids);

        $workspaces = array_map(fn ($ws) => [
            'uuid' => $ws->get('uuid'),
            'name' => $ws->get('name'),
            'description' => $ws->get('description') ?? '',
        ], array_values($entities));

        return new SsrResponse(
            content: json_encode(['workspaces' => $workspaces], JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
