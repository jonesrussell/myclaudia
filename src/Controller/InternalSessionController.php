<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalSessionController
{
    private const DEFAULT_TURN_LIMITS = [
        'quick_lookup' => 5,
        'email_compose' => 15,
        'brief_generation' => 10,
        'research' => 40,
        'general' => 25,
        'onboarding' => 30,
    ];

    private const DAILY_CEILING = 500;

    public function __construct(
        private readonly EntityRepositoryInterface $sessionRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId = 'default',
    ) {}

    public function getLimits(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        return $this->jsonResponse([
            'turn_limits' => self::DEFAULT_TURN_LIMITS,
            'daily_ceiling' => self::DAILY_CEILING,
        ]);
    }

    public function continueSession(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $sessionUuid = $params['id'] ?? '';
        if ($sessionUuid === '') {
            return $this->jsonError('Session ID required', 400);
        }

        $sessions = $this->sessionRepo->findBy([
            'uuid' => $sessionUuid,
            'tenant_id' => $this->tenantId,
        ]);

        if (empty($sessions)) {
            return $this->jsonError('Session not found', 404);
        }

        $session = $sessions[0];

        // Check daily ceiling: sum turns_consumed for tenant today
        $allSessions = $this->sessionRepo->findBy([
            'tenant_id' => $this->tenantId,
        ]);

        $todayStart = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $totalTurnsToday = 0;
        foreach ($allSessions as $s) {
            $createdAt = $s->get('created_at');
            if ($createdAt !== null) {
                $sessionDate = (new \DateTimeImmutable($createdAt))->format('Y-m-d');
                if ($sessionDate === $todayStart) {
                    $totalTurnsToday += (int) ($s->get('turns_consumed') ?? 0);
                }
            } else {
                // Sessions without created_at are assumed to be from today (e.g. in-memory tests)
                $totalTurnsToday += (int) ($s->get('turns_consumed') ?? 0);
            }
        }

        if ($totalTurnsToday >= self::DAILY_CEILING) {
            return $this->jsonError('Daily turn ceiling of '.self::DAILY_CEILING.' reached', 429);
        }

        // Increment continued_count
        $continuedCount = (int) ($session->get('continued_count') ?? 0) + 1;
        $session->set('continued_count', $continuedCount);
        $this->sessionRepo->save($session);

        // Calculate new turn budget
        $taskType = (string) ($session->get('task_type') ?? 'general');
        $turnBudget = self::DEFAULT_TURN_LIMITS[$taskType] ?? self::DEFAULT_TURN_LIMITS['general'];
        $remainingCeiling = self::DAILY_CEILING - $totalTurnsToday;
        $newBudget = min($turnBudget, $remainingCeiling);

        return $this->jsonResponse([
            'continued_count' => $continuedCount,
            'new_turn_budget' => $newBudget,
            'daily_turns_used' => $totalTurnsToday,
            'daily_ceiling' => self::DAILY_CEILING,
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
