<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalScheduleController
{
    public function __construct(
        private readonly EntityRepositoryInterface $scheduleRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId,
    ) {}

    public function query(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $limit = min((int) ($query['limit'] ?? 50), 100);
        $dateFrom = $query['date_from'] ?? null;
        $dateTo = $query['date_to'] ?? null;

        $all = $this->scheduleRepo->findBy(['tenant_id' => $this->tenantId]);

        $items = [];
        $count = 0;
        foreach ($all as $entry) {
            if ($count >= $limit) {
                break;
            }

            $startsAt = $entry->get('starts_at');

            if ($dateFrom !== null && $startsAt < $dateFrom) {
                continue;
            }
            if ($dateTo !== null && $startsAt > $dateTo) {
                continue;
            }

            $items[] = [
                'uuid' => $entry->get('uuid'),
                'title' => $entry->get('title'),
                'starts_at' => $startsAt,
                'ends_at' => $entry->get('ends_at'),
                'notes' => $entry->get('notes'),
                'source' => $entry->get('source'),
                'status' => $entry->get('status'),
            ];
            $count++;
        }

        return $this->jsonResponse(['entries' => $items, 'count' => $count]);
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
