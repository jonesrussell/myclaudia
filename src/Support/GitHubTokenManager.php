<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Claudriel\Entity\Integration;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class GitHubTokenManager implements GitHubTokenManagerInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $integrationRepo,
    ) {}

    public function getValidAccessToken(string $accountId): string
    {
        // Check for revoked integrations first to give a specific error message
        $revokedIntegrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => 'github',
            'status' => 'revoked',
        ], null, 1);

        if ($revokedIntegrations !== []) {
            throw new \RuntimeException(
                'GitHub integration has been revoked. Re-authorize at /github/connect'
            );
        }

        $integration = $this->findIntegration($accountId);

        if ($integration === null) {
            throw new \RuntimeException(
                'No active GitHub integration found for this account. Connect GitHub at /github/connect'
            );
        }

        return $integration->get('access_token');
    }

    public function hasActiveIntegration(string $accountId): bool
    {
        return $this->findIntegration($accountId) !== null;
    }

    public function markRevoked(string $accountId): void
    {
        $integrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => 'github',
        ]);

        foreach ($integrations as $integration) {
            assert($integration instanceof Integration);
            $integration->set('status', 'revoked');
            $this->integrationRepo->save($integration);
        }
    }

    private function findIntegration(string $accountId): ?Integration
    {
        $integrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => 'github',
            'status' => 'active',
        ], null, 1);

        if ($integrations === []) {
            return null;
        }

        $integration = reset($integrations);
        assert($integration instanceof Integration);

        return $integration;
    }
}
