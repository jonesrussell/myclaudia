<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Claudriel\Entity\Integration;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\OAuthProvider\ProviderRegistry;
use Waaseyaa\OAuthProvider\UnsupportedOperationException;

final class OAuthTokenManager implements OAuthTokenManagerInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $integrationRepo,
        private readonly ProviderRegistry $providerRegistry,
    ) {}

    public function getValidAccessToken(string $accountId, string $provider = 'google'): string
    {
        // Check for revoked integrations first (specific error message)
        $revokedIntegrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => $provider,
            'status' => 'revoked',
        ]);

        if ($revokedIntegrations !== []) {
            throw new \RuntimeException(
                "Your {$provider} integration has been revoked. Please reconnect your account."
            );
        }

        $integrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => $provider,
            'status' => 'active',
        ], null, 1);

        if ($integrations === []) {
            throw new \RuntimeException(
                "No active {$provider} integration found. Please connect your account."
            );
        }

        $integration = $integrations[0];
        assert($integration instanceof Integration);

        $accessToken = (string) $integration->get('access_token');
        $expiresAt = $integration->get('token_expires_at');

        // GitHub tokens have no expiry, return directly
        if ($expiresAt === null) {
            return $accessToken;
        }

        if (! $this->isExpired((string) $expiresAt)) {
            return $accessToken;
        }

        // Token is expired, attempt refresh
        $refreshToken = $integration->get('refresh_token');
        if ($refreshToken === null || $refreshToken === '') {
            $integration->set('status', 'error');
            $this->integrationRepo->save($integration);

            throw new \RuntimeException(
                "Token expired and no refresh token available for {$provider}."
            );
        }

        try {
            $oauthProvider = $this->providerRegistry->get($provider);
            $newToken = $oauthProvider->refreshToken((string) $refreshToken);

            $integration->set('access_token', $newToken->accessToken);
            if ($newToken->refreshToken !== null) {
                $integration->set('refresh_token', $newToken->refreshToken);
            }
            $integration->set('token_expires_at', $newToken->expiresAt?->format('c'));
            $integration->set('scopes', json_encode($newToken->scopes));
            $this->integrationRepo->save($integration);

            return $newToken->accessToken;
        } catch (UnsupportedOperationException $e) {
            // Provider doesn't support refresh, return existing token
            return $accessToken;
        } catch (\Throwable $e) {
            $integration->set('status', 'error');
            $this->integrationRepo->save($integration);

            throw new \RuntimeException(
                "Failed to refresh {$provider} token: {$e->getMessage()}"
            );
        }
    }

    public function hasActiveIntegration(string $accountId, string $provider = 'google'): bool
    {
        $integrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => $provider,
            'status' => 'active',
        ], null, 1);

        return $integrations !== [];
    }

    public function markRevoked(string $accountId, string $provider): void
    {
        $integrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => $provider,
        ]);

        foreach ($integrations as $integration) {
            assert($integration instanceof Integration);
            $integration->set('status', 'revoked');
            $this->integrationRepo->save($integration);
        }
    }

    private function isExpired(string $expiresAt): bool
    {
        $expiry = new \DateTimeImmutable($expiresAt);
        $now = new \DateTimeImmutable;

        // 60-second buffer before actual expiry
        return $expiry <= $now->modify('+60 seconds');
    }
}
