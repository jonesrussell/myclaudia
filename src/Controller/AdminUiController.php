<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Admin\Host\ClaudrielAdminHost;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class AdminUiController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?string $adminBuildRoot = null,
    ) {}

    public function show(
        array $params = [],
        array $query = [],
        mixed $account = null,
        ?Request $httpRequest = null,
    ): RedirectResponse|SsrResponse {
        $resolvedAccount = $this->host()->resolveAuthenticatedAccount($account);

        if (! $resolvedAccount instanceof AuthenticatedAccount) {
            return new RedirectResponse($this->host()->loginUrl($this->requestedPath($httpRequest)), 302);
        }

        if (! $this->host()->allowsAdminAccess($resolvedAccount)) {
            return new SsrResponse(
                content: 'Admin access is required.',
                statusCode: 403,
                headers: ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        if (! $this->host()->hasTenantContext($resolvedAccount)) {
            return new SsrResponse(
                content: 'Tenant context is required for admin access.',
                statusCode: 409,
                headers: ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        $indexPath = rtrim($this->adminBuildRoot ?? dirname(__DIR__, 2).'/public/admin', '/').'/index.html';
        if (! is_file($indexPath)) {
            return new SsrResponse(
                content: 'Admin UI build is unavailable.',
                statusCode: 503,
                headers: ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        return new SsrResponse(
            content: (string) file_get_contents($indexPath),
            statusCode: 200,
            headers: ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function requestedPath(?Request $request): string
    {
        if (! $request instanceof Request) {
            return '/admin';
        }

        $path = $request->getPathInfo();

        return $path !== '' ? $path : '/admin';
    }

    private function host(): ClaudrielAdminHost
    {
        return new ClaudrielAdminHost($this->entityTypeManager);
    }
}
