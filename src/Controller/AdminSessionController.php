<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Admin\Host\ClaudrielAdminHost;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class AdminSessionController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function state(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $resolvedAccount = $this->host()->resolveAuthenticatedAccount($account);
        if (! $resolvedAccount instanceof AuthenticatedAccount) {
            return $this->json(['error' => 'Not authenticated.'], 401);
        }

        if (! $this->host()->allowsAdminAccess($resolvedAccount)) {
            return $this->json(['error' => 'Admin access is required.'], 403);
        }

        return $this->json($this->host()->buildSessionPayload($resolvedAccount));
    }

    public function logout(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $this->host()->clearAuthenticatedSession();

        return $this->json($this->host()->buildLogoutPayload(), 200);
    }

    private function json(mixed $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function host(): ClaudrielAdminHost
    {
        return new ClaudrielAdminHost($this->entityTypeManager);
    }
}
