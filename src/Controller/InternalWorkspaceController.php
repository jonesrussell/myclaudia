<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalWorkspaceController
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId,
    ) {}

    public function listWorkspaces(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $limit = min((int) ($query['limit'] ?? 50), 100);

        $all = $this->workspaceRepo->findBy(['tenant_id' => $this->tenantId]);

        $items = [];
        $count = 0;
        foreach ($all as $workspace) {
            if ($count >= $limit) {
                break;
            }
            $items[] = [
                'uuid' => $workspace->get('uuid'),
                'name' => $workspace->get('name'),
                'description' => $workspace->get('description'),
                'status' => $workspace->get('status'),
                'mode' => $workspace->get('mode'),
            ];
            $count++;
        }

        return $this->jsonResponse(['workspaces' => $items, 'count' => $count]);
    }

    public function workspaceContext(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Workspace UUID required', 400);
        }

        $results = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        $workspace = $results[0] ?? null;

        if ($workspace === null) {
            return $this->jsonError('Workspace not found', 404);
        }

        return $this->jsonResponse([
            'uuid' => $workspace->get('uuid'),
            'name' => $workspace->get('name'),
            'description' => $workspace->get('description'),
            'mode' => $workspace->get('mode'),
            'status' => $workspace->get('status'),
            'saved_context' => $workspace->get('saved_context'),
        ]);
    }

    private function authenticate(mixed $httpRequest): ?string
    {
        $auth = '';
        if ($httpRequest instanceof Request) {
            $auth = $httpRequest->headers->get('Authorization', '');
        }

        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return $this->apiTokenGenerator->validate(substr($auth, 7));
    }

    private function jsonResponse(array $data): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function jsonError(string $message, int $statusCode): SsrResponse
    {
        return new SsrResponse(
            content: json_encode(['error' => $message], JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
