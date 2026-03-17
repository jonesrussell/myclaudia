<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Entity\Person;
use Claudriel\Routing\RequestScopeViolation;
use Claudriel\Routing\TenantWorkspaceResolver;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class PeopleApiController
{
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
            function (Person $entry) use ($query, $resolver, $scope): bool {
                if (! $resolver->tenantMatches($entry, $scope->tenantId)) {
                    return false;
                }
                if (isset($query['email']) && (string) $query['email'] !== (string) ($entry->get('email') ?? '')) {
                    return false;
                }
                if (isset($query['tier']) && (string) $query['tier'] !== (string) ($entry->get('tier') ?? 'contact')) {
                    return false;
                }

                return true;
            },
        ));

        usort($entries, fn (Person $a, Person $b): int => ((string) ($b->get('last_interaction_at') ?? '')) <=> ((string) ($a->get('last_interaction_at') ?? '')));

        return $this->json([
            'people' => array_map(fn (Person $entry) => $this->serialize($entry), $entries),
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
        $email = is_string($body['email'] ?? null) ? trim($body['email']) : '';
        if ($email === '') {
            return $this->json(['error' => 'Field "email" is required.'], 422);
        }

        $person = new Person([
            'email' => $email,
            'name' => is_string($body['name'] ?? null) && trim($body['name']) !== '' ? trim($body['name']) : $email,
            'tier' => is_string($body['tier'] ?? null) ? $body['tier'] : 'contact',
            'source' => is_string($body['source'] ?? null) ? $body['source'] : 'manual',
            'tenant_id' => $scope->tenantId,
            'latest_summary' => is_string($body['latest_summary'] ?? null) ? $body['latest_summary'] : '',
            'last_interaction_at' => $this->normalizeDateTime($body['last_interaction_at'] ?? null),
            'last_inbox_category' => is_string($body['last_inbox_category'] ?? null) ? $body['last_inbox_category'] : null,
        ]);

        $this->entityTypeManager->getStorage('person')->save($person);

        return $this->json(['person' => $this->serialize($person)], 201);
    }

    public function show(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        error_log('Deprecated: '.__METHOD__.' — use /api/graphql endpoint instead');
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        $scope = $resolver->resolve($query, $account);
        $person = $this->findByUuid((string) ($params['uuid'] ?? ''), $scope->tenantId);
        if ($person === null) {
            return $this->json(['error' => 'Person not found.'], 404);
        }

        return $this->json(['person' => $this->serialize($person)]);
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

        $person = $this->findByUuid((string) ($params['uuid'] ?? ''), $scope->tenantId);
        if ($person === null) {
            return $this->json(['error' => 'Person not found.'], 404);
        }

        foreach (['name', 'tier', 'source', 'tenant_id', 'latest_summary', 'last_inbox_category'] as $field) {
            if (! array_key_exists($field, $body)) {
                continue;
            }

            if ($field === 'tenant_id') {
                continue;
            }

            if ($field === 'name') {
                $name = is_string($body['name']) ? trim($body['name']) : '';
                if ($name === '') {
                    return $this->json(['error' => 'Field "name" cannot be empty.'], 422);
                }
                $person->set('name', $name);

                continue;
            }

            $person->set($field, $body[$field]);
        }

        if (array_key_exists('email', $body)) {
            $email = is_string($body['email']) ? trim($body['email']) : '';
            if ($email === '') {
                return $this->json(['error' => 'Field "email" cannot be empty.'], 422);
            }
            $person->set('email', $email);
        }

        if (array_key_exists('last_interaction_at', $body)) {
            $normalized = $this->normalizeDateTime($body['last_interaction_at']);
            if ($body['last_interaction_at'] !== null && $normalized === null) {
                return $this->json(['error' => 'Field "last_interaction_at" must be a valid datetime.'], 422);
            }
            $person->set('last_interaction_at', $normalized);
        }

        $this->entityTypeManager->getStorage('person')->save($person);

        return $this->json(['person' => $this->serialize($person)]);
    }

    public function delete(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        error_log('Deprecated: '.__METHOD__.' — use /api/graphql endpoint instead');
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        $scope = $resolver->resolve($query, $account);
        $person = $this->findByUuid((string) ($params['uuid'] ?? ''), $scope->tenantId);
        if ($person === null) {
            return $this->json(['error' => 'Person not found.'], 404);
        }

        $this->entityTypeManager->getStorage('person')->delete([$person]);

        return $this->json(['deleted' => true]);
    }

    /**
     * @return list<Person>
     */
    private function loadAll(): array
    {
        $storage = $this->entityTypeManager->getStorage('person');
        $entries = $storage->loadMultiple($storage->getQuery()->execute());

        return array_values(array_filter($entries, fn ($entry): bool => $entry instanceof Person));
    }

    private function findByUuid(string $uuid, string $tenantId): ?Person
    {
        if ($uuid === '') {
            return null;
        }

        $storage = $this->entityTypeManager->getStorage('person');
        $ids = $storage->getQuery()->condition('uuid', $uuid)->execute();
        if ($ids === []) {
            return null;
        }

        $entry = $storage->load(reset($ids));

        return $entry instanceof Person && (new TenantWorkspaceResolver($this->entityTypeManager))->tenantMatches($entry, $tenantId) ? $entry : null;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value)) {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(Person $entry): array
    {
        return [
            'uuid' => $entry->get('uuid'),
            'email' => $entry->get('email'),
            'name' => $entry->get('name'),
            'tier' => $entry->get('tier') ?? 'contact',
            'source' => $entry->get('source') ?? 'gmail',
            'tenant_id' => $entry->get('tenant_id'),
            'latest_summary' => $entry->get('latest_summary') ?? '',
            'last_interaction_at' => $entry->get('last_interaction_at'),
            'last_inbox_category' => $entry->get('last_inbox_category'),
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
