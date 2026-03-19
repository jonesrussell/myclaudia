<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalCommitmentController
{
    public function __construct(
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId = 'default',
    ) {}

    public function listCommitments(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $criteria = ['tenant_id' => $this->tenantId];

        $itemStatus = $query['status'] ?? null;
        if ($itemStatus !== null && $itemStatus !== '') {
            $criteria['status'] = $itemStatus;
        }

        $limit = min((int) ($query['limit'] ?? 20), 100);
        if ($limit < 1) {
            $limit = 20;
        }

        $commitments = $this->commitmentRepo->findBy($criteria, ['created_at' => 'DESC'], $limit);

        $dueBefore = $query['due_before'] ?? null;

        $result = [];
        foreach ($commitments as $commitment) {
            if ($dueBefore !== null && $dueBefore !== '') {
                $dueDate = $commitment->get('due_date');
                if ($dueDate !== null && $dueDate > $dueBefore) {
                    continue;
                }
            }

            $result[] = [
                'uuid' => $commitment->get('uuid'),
                'title' => $commitment->get('title'),
                'status' => $commitment->get('status'),
                'confidence' => $commitment->get('confidence'),
                'due_date' => $commitment->get('due_date'),
                'person_uuid' => $commitment->get('person_uuid'),
                'source' => $commitment->get('source'),
                'created_at' => $commitment->get('created_at'),
                'updated_at' => $commitment->get('updated_at'),
            ];
        }

        return $this->jsonResponse(['commitments' => $result, 'count' => count($result)]);
    }

    public function updateCommitment(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Commitment UUID required', 400);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $commitments = $this->commitmentRepo->findBy([
            'uuid' => $uuid,
            'tenant_id' => $this->tenantId,
        ]);

        if (empty($commitments)) {
            return $this->jsonError('Commitment not found', 404);
        }

        $commitment = $commitments[0];

        if (isset($body['status'])) {
            $commitment->set('status', $body['status']);
        }
        if (isset($body['notes'])) {
            $commitment->set('notes', $body['notes']);
        }

        $this->commitmentRepo->save($commitment);

        return $this->jsonResponse([
            'uuid' => $commitment->get('uuid'),
            'title' => $commitment->get('title'),
            'status' => $commitment->get('status'),
            'confidence' => $commitment->get('confidence'),
            'due_date' => $commitment->get('due_date'),
            'person_uuid' => $commitment->get('person_uuid'),
            'source' => $commitment->get('source'),
            'notes' => $commitment->get('notes'),
            'created_at' => $commitment->get('created_at'),
            'updated_at' => $commitment->get('updated_at'),
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

    private function getRequestBody(mixed $httpRequest): ?array
    {
        if (! $httpRequest instanceof Request) {
            return null;
        }
        $content = $httpRequest->getContent();
        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
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
