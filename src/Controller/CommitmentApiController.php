<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Entity\Commitment;
use Claudriel\Routing\RequestScopeViolation;
use Claudriel\Routing\TenantWorkspaceResolver;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class CommitmentApiController
{
    private const VALID_STATUSES = ['pending', 'active', 'done', 'ignored'];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        mixed $twig = null,
    ) {
        unset($twig);
    }

    public function list(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        error_log('Deprecated: '.__METHOD__.' — use /api/graphql endpoint instead');
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        $scope = $resolver->resolve($query, $account);
        $entries = array_values(array_filter(
            $this->loadAll(),
            function (Commitment $entry) use ($query, $resolver, $scope): bool {
                if (! $resolver->tenantMatches($entry, $scope->tenantId)) {
                    return false;
                }
                if (isset($query['status']) && (string) $query['status'] !== (string) ($entry->get('status') ?? 'pending')) {
                    return false;
                }

                if (isset($query['person_uuid']) && (string) $query['person_uuid'] !== (string) ($entry->get('person_uuid') ?? '')) {
                    return false;
                }

                return true;
            },
        ));

        usort($entries, fn (Commitment $a, Commitment $b): int => ((string) ($b->get('updated_at') ?? $b->get('created_at') ?? '')) <=> ((string) ($a->get('updated_at') ?? $a->get('created_at') ?? '')));

        return $this->json([
            'commitments' => array_map(fn (Commitment $entry) => $this->serialize($entry), $entries),
        ]);
    }

    public function create(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        error_log('Deprecated: '.__METHOD__.' — use /api/graphql endpoint instead');
        $body = json_decode($httpRequest?->getContent() ?? '', true) ?? [];
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account, $httpRequest, $body);
            $resolver->assertPayloadTenantMatchesContext($body, $scope->tenantId);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }
        $title = is_string($body['title'] ?? null) ? trim($body['title']) : '';
        if ($title === '') {
            return $this->json(['error' => 'Field "title" is required.'], 422);
        }

        $status = $this->normalizeStatus($body['status'] ?? 'pending');
        if ($status === null) {
            return $this->json(['error' => 'Field "status" is invalid.'], 422);
        }

        $commitment = new Commitment([
            'title' => $title,
            'status' => $status,
            'confidence' => is_numeric($body['confidence'] ?? null) ? (float) $body['confidence'] : 1.0,
            'due_date' => is_string($body['due_date'] ?? null) ? $body['due_date'] : null,
            'person_uuid' => is_string($body['person_uuid'] ?? null) ? $body['person_uuid'] : null,
            'source' => is_string($body['source'] ?? null) ? $body['source'] : 'manual',
            'tenant_id' => $scope->tenantId,
            'created_at' => (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            'updated_at' => (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
        ]);

        $this->entityTypeManager->getStorage('commitment')->save($commitment);

        return $this->json(['commitment' => $this->serialize($commitment)], 201);
    }

    public function show(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        error_log('Deprecated: '.__METHOD__.' — use /api/graphql endpoint instead');
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        $scope = $resolver->resolve($query, $account);
        $commitment = $this->findByUuid((string) ($params['uuid'] ?? ''), $scope->tenantId);
        if ($commitment === null) {
            return $this->json(['error' => 'Commitment not found.'], 404);
        }

        return $this->json(['commitment' => $this->serialize($commitment)]);
    }

    public function update(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        error_log('Deprecated: '.__METHOD__.' — use /api/graphql endpoint instead');
        $body = json_decode($httpRequest?->getContent() ?? '', true) ?? [];
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account, $httpRequest, $body);
            $resolver->assertPayloadTenantMatchesContext($body, $scope->tenantId);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }

        $commitment = $this->findByUuid((string) ($params['uuid'] ?? ''), $scope->tenantId);
        if ($commitment === null) {
            return $this->json(['error' => 'Commitment not found.'], 404);
        }

        if (array_key_exists('title', $body)) {
            $title = is_string($body['title']) ? trim($body['title']) : '';
            if ($title === '') {
                return $this->json(['error' => 'Field "title" cannot be empty.'], 422);
            }
            $commitment->set('title', $title);
        }

        if (array_key_exists('status', $body)) {
            $status = $this->normalizeStatus($body['status']);
            if ($status === null) {
                return $this->json(['error' => 'Field "status" is invalid.'], 422);
            }
            $commitment->set('status', $status);
        }

        foreach (['due_date', 'person_uuid', 'source'] as $field) {
            if (array_key_exists($field, $body)) {
                $commitment->set($field, $body[$field]);
            }
        }

        if (array_key_exists('confidence', $body)) {
            if (! is_numeric($body['confidence'])) {
                return $this->json(['error' => 'Field "confidence" must be numeric.'], 422);
            }
            $commitment->set('confidence', (float) $body['confidence']);
        }

        $commitment->set('updated_at', (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM));
        $this->entityTypeManager->getStorage('commitment')->save($commitment);

        return $this->json(['commitment' => $this->serialize($commitment)]);
    }

    public function delete(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        error_log('Deprecated: '.__METHOD__.' — use /api/graphql endpoint instead');
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        $scope = $resolver->resolve($query, $account);
        $commitment = $this->findByUuid((string) ($params['uuid'] ?? ''), $scope->tenantId);
        if ($commitment === null) {
            return $this->json(['error' => 'Commitment not found.'], 404);
        }

        $this->entityTypeManager->getStorage('commitment')->delete([$commitment]);

        return $this->json(['deleted' => true]);
    }

    /**
     * @return list<Commitment>
     */
    private function loadAll(): array
    {
        $storage = $this->entityTypeManager->getStorage('commitment');
        $entries = $storage->loadMultiple($storage->getQuery()->execute());

        return array_values(array_filter($entries, fn ($entry): bool => $entry instanceof Commitment));
    }

    private function findByUuid(string $uuid, string $tenantId): ?Commitment
    {
        if ($uuid === '') {
            return null;
        }

        $storage = $this->entityTypeManager->getStorage('commitment');
        $ids = $storage->getQuery()->condition('uuid', $uuid)->execute();
        if ($ids === []) {
            return null;
        }

        $entry = $storage->load(reset($ids));

        return $entry instanceof Commitment && (new TenantWorkspaceResolver($this->entityTypeManager))->tenantMatches($entry, $tenantId) ? $entry : null;
    }

    private function normalizeStatus(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, self::VALID_STATUSES, true) ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Commitment $entry): array
    {
        return [
            'uuid' => $entry->get('uuid'),
            'title' => $entry->get('title'),
            'status' => $entry->get('status') ?? 'pending',
            'confidence' => (float) ($entry->get('confidence') ?? 1.0),
            'due_date' => $entry->get('due_date'),
            'person_uuid' => $entry->get('person_uuid'),
            'source' => $entry->get('source'),
            'tenant_id' => $entry->get('tenant_id'),
            'created_at' => $entry->get('created_at'),
            'updated_at' => $entry->get('updated_at'),
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
