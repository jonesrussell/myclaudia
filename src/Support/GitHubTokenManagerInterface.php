<?php

declare(strict_types=1);

namespace Claudriel\Support;

interface GitHubTokenManagerInterface
{
    /**
     * Returns a valid access token for the given account.
     *
     * GitHub OAuth Apps issue permanent tokens, so no refresh logic is needed.
     *
     * @throws \RuntimeException if no active integration or integration is revoked
     */
    public function getValidAccessToken(string $accountId): string;

    /**
     * Check if an account has an active GitHub integration.
     */
    public function hasActiveIntegration(string $accountId): bool;

    /**
     * Mark all GitHub integrations for the given account as revoked.
     */
    public function markRevoked(string $accountId): void;
}
