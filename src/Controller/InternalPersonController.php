<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\SSR\SsrResponse;

final class InternalPersonController
{
    public function __construct(
        private readonly EntityRepository $personRepo,
        private readonly EntityRepository $commitmentRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId,
    ) {}

    public function searchPersons(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $limit = min((int) ($query['limit'] ?? 20), 100);
        $nameFilter = $query['name'] ?? null;
        $emailFilter = $query['email'] ?? null;

        $allPersons = $this->personRepo->findBy(['tenant_id' => $this->tenantId]);

        $results = [];
        foreach ($allPersons as $person) {
            if ($nameFilter !== null && $nameFilter !== '') {
                $name = $person->get('name') ?? '';
                if (! str_contains(strtolower($name), strtolower($nameFilter))) {
                    continue;
                }
            }
            if ($emailFilter !== null && $emailFilter !== '') {
                $email = $person->get('email') ?? '';
                if (! str_contains(strtolower($email), strtolower($emailFilter))) {
                    continue;
                }
            }

            $results[] = [
                'uuid' => $person->get('uuid'),
                'name' => $person->get('name'),
                'email' => $person->get('email'),
                'tier' => $person->get('tier'),
                'source' => $person->get('source'),
                'last_interaction_at' => $person->get('last_interaction_at'),
            ];

            if (count($results) >= $limit) {
                break;
            }
        }

        return $this->jsonResponse(['persons' => $results, 'count' => count($results)]);
    }

    public function personDetail(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('UUID required', 400);
        }

        $persons = $this->personRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if ($persons === []) {
            return $this->jsonError('Person not found', 404);
        }

        $person = $persons[0];

        $commitments = $this->commitmentRepo->findBy(['person_uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        $commitmentData = [];
        foreach ($commitments as $commitment) {
            $commitmentData[] = [
                'uuid' => $commitment->get('uuid'),
                'title' => $commitment->get('title'),
                'status' => $commitment->get('status'),
                'confidence' => $commitment->get('confidence'),
                'due_date' => $commitment->get('due_date'),
            ];
        }

        return $this->jsonResponse([
            'person' => [
                'uuid' => $person->get('uuid'),
                'name' => $person->get('name'),
                'email' => $person->get('email'),
                'tier' => $person->get('tier'),
                'source' => $person->get('source'),
                'last_interaction_at' => $person->get('last_interaction_at'),
                'metadata' => $person->get('metadata'),
            ],
            'commitments' => $commitmentData,
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
