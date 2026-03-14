<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Entity\Workspace;
use Claudriel\Routing\RequestScopeViolation;
use Claudriel\Routing\TenantWorkspaceResolver;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class WorkspaceApiController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    public function list(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }
        $storage = $this->entityTypeManager->getStorage('workspace');
        $entityQuery = $storage->getQuery();
        $ids = $entityQuery->execute();
        $entities = array_values(array_filter(
            $storage->loadMultiple($ids),
            fn ($workspace): bool => $workspace instanceof Workspace && $resolver->tenantMatches($workspace, $scope->tenantId),
        ));

        $workspaces = array_map(fn ($ws) => $this->serialize($ws), $entities);

        return $this->json(['workspaces' => $workspaces]);
    }

    public function create(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $raw = $httpRequest?->getContent() ?? '';
        $body = json_decode($raw, true) ?? [];
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account, $httpRequest, $body);
            $resolver->assertPayloadTenantMatchesContext($body, $scope->tenantId);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }

        $name = $body['name'] ?? null;
        if (! is_string($name) || trim($name) === '') {
            return $this->json(['error' => 'Field "name" is required.'], 422);
        }

        $workspace = new Workspace([
            'name' => trim($name),
            'account_id' => $body['account_id'] ?? null,
            'tenant_id' => $scope->tenantId,
            'description' => $body['description'] ?? '',
            'metadata' => json_encode($body['metadata'] ?? new \stdClass, JSON_THROW_ON_ERROR),
        ]);

        $storage = $this->entityTypeManager->getStorage('workspace');
        $storage->save($workspace);

        return $this->json(['workspace' => $this->serialize($workspace)], 201);
    }

    public function show(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }
        $workspace = $this->findByUuid($params['uuid'] ?? '', $scope->tenantId);
        if ($workspace === null) {
            return $this->json(['error' => 'Workspace not found.'], 404);
        }

        return $this->json(['workspace' => $this->serialize($workspace)]);
    }

    public function update(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $body = json_decode($httpRequest?->getContent() ?? '', true) ?? [];
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account, $httpRequest, $body, (string) ($params['uuid'] ?? ''), true);
            $resolver->assertPayloadTenantMatchesContext($body, $scope->tenantId);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }

        $workspace = $scope->workspace;
        if ($workspace === null) {
            return $this->json(['error' => 'Workspace not found.'], 404);
        }

        $allowedFields = ['name', 'description', 'metadata', 'account_id'];
        foreach ($allowedFields as $field) {
            if (! array_key_exists($field, $body)) {
                continue;
            }

            $value = $body[$field];
            if ($field === 'name' && (! is_string($value) || trim($value) === '')) {
                return $this->json(['error' => 'Field "name" cannot be empty.'], 422);
            }
            if ($field === 'metadata') {
                $value = json_encode($value, JSON_THROW_ON_ERROR);
            }
            $workspace->set($field, $field === 'name' ? trim($value) : $value);
        }

        $storage = $this->entityTypeManager->getStorage('workspace');
        $storage->save($workspace);

        return $this->json(['workspace' => $this->serialize($workspace)]);
    }

    public function delete(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account, null, null, (string) ($params['uuid'] ?? ''), true);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }

        $workspace = $scope->workspace;
        if ($workspace === null) {
            return $this->json(['error' => 'Workspace not found.'], 404);
        }

        $storage = $this->entityTypeManager->getStorage('workspace');
        $storage->delete([$workspace]);

        return $this->json(['deleted' => true], 200);
    }

    private function findByUuid(string $uuid, string $tenantId): ?Workspace
    {
        return (new TenantWorkspaceResolver($this->entityTypeManager))->findWorkspaceByUuidForTenant($uuid, $tenantId);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Workspace $workspace): array
    {
        $metadata = $workspace->get('metadata');
        $decoded = is_string($metadata) ? json_decode($metadata, true) : $metadata;

        return [
            'uuid' => $workspace->get('uuid'),
            'account_id' => $workspace->get('account_id'),
            'tenant_id' => $workspace->get('tenant_id'),
            'name' => $workspace->get('name'),
            'description' => $workspace->get('description') ?? '',
            'metadata' => $decoded ?? new \stdClass,
            'created_at' => $workspace->get('created_at'),
            'updated_at' => $workspace->get('updated_at'),
        ];
    }

    private function json(mixed $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
