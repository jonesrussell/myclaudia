<?php

declare(strict_types=1);

namespace Claudriel\Support;

interface OAuthTokenManagerInterface
{
    /** @throws \RuntimeException if no active integration, integration is revoked, or refresh fails */
    public function getValidAccessToken(string $accountId, string $provider = 'google'): string;

    public function hasActiveIntegration(string $accountId, string $provider = 'google'): bool;

    public function markRevoked(string $accountId, string $provider): void;
}
