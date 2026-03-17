<?php

declare(strict_types=1);

namespace Claudriel\Support;

interface GoogleTokenManagerInterface
{
    /**
     * Returns a valid access token for the given account.
     *
     * Refreshes transparently if expired.
     *
     * @throws \RuntimeException if no active integration or refresh fails
     */
    public function getValidAccessToken(string $accountId): string;

    /**
     * Check if an account has an active Google integration.
     */
    public function hasActiveIntegration(string $accountId): bool;
}
