<?php

declare(strict_types=1);

namespace Claudriel\Admin\Host;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Account;
use Claudriel\Entity\Tenant;
use Claudriel\Support\AdminAccess;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * Authentication and redirect helpers for admin controllers.
 *
 * Session/catalog responsibilities have moved to ClaudrielSurfaceHost.
 */
final class ClaudrielAdminHost
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function resolveAuthenticatedAccount(mixed $account): ?AuthenticatedAccount
    {
        if ($account instanceof AuthenticatedAccount) {
            return $account;
        }

        return (new AuthenticatedAccountSessionResolver($this->entityTypeManager))->resolve();
    }

    public function allowsAdminAccess(mixed $account): bool
    {
        return AdminAccess::allows($account);
    }

    public function hasTenantContext(AuthenticatedAccount $account): bool
    {
        return ((string) ($account->getTenantId() ?? '')) !== '';
    }

    public function loginUrl(string $requestedPath = '/admin'): string
    {
        return '/login?redirect='.rawurlencode($requestedPath);
    }

    public function sanitizeRedirectTarget(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $candidate = trim($value);
        if ($candidate === '') {
            return null;
        }

        if (str_starts_with($candidate, 'http://') || str_starts_with($candidate, 'https://')) {
            $parsed = parse_url($candidate);
            if ($parsed === false || ! isset($parsed['scheme'], $parsed['host'], $parsed['path'])) {
                return null;
            }
            if (! str_starts_with($parsed['path'], '/')) {
                return null;
            }
            $host = strtolower($parsed['host']);

            if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
                return $candidate;
            }

            return null;
        }

        if (! str_starts_with($candidate, '/')) {
            return null;
        }

        if (str_starts_with($candidate, '//')) {
            return null;
        }

        return $candidate;
    }

    public function loginFormRedirect(Account|AuthenticatedAccount $account, ?string $redirect = null): string
    {
        return $redirect ?? $this->appUrl($this->tenantId($account), $this->defaultWorkspaceUuidForTenant($this->tenantId($account)));
    }

    public function postLoginRedirect(Account|AuthenticatedAccount $account, ?string $redirect = null): string
    {
        if ($redirect !== null) {
            return $redirect;
        }

        $tenantId = $this->tenantId($account);
        $workspaceUuid = $this->defaultWorkspaceUuidForTenant($tenantId);
        $query = ['login' => '1'];
        if ($tenantId !== '') {
            $query['tenant_id'] = $tenantId;
        }
        if ($workspaceUuid !== null) {
            $query['workspace_uuid'] = $workspaceUuid;
        }

        return '/app?'.http_build_query($query);
    }

    public function clearAuthenticatedSession(): void
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        unset($_SESSION['claudriel_account_uuid']);
        session_regenerate_id(true);
    }

    public function defaultWorkspaceUuidForTenant(string $tenantId): ?string
    {
        if ($tenantId === '') {
            return null;
        }

        $tenant = $this->findTenantByUuid($tenantId);
        if (! $tenant instanceof Tenant) {
            return null;
        }

        $metadata = $tenant->get('metadata');
        if (! is_array($metadata)) {
            return null;
        }

        $workspaceUuid = $metadata['default_workspace_uuid'] ?? null;

        return is_string($workspaceUuid) && $workspaceUuid !== '' ? $workspaceUuid : null;
    }

    private function findTenantByUuid(string $tenantId): ?Tenant
    {
        $ids = $this->entityTypeManager->getStorage('tenant')->getQuery()
            ->condition('uuid', $tenantId)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $tenant = $this->entityTypeManager->getStorage('tenant')->load(reset($ids));

        return $tenant instanceof Tenant ? $tenant : null;
    }

    private function appUrl(string $tenantId, ?string $workspaceUuid): string
    {
        $query = [];
        if ($tenantId !== '') {
            $query['tenant_id'] = $tenantId;
        }
        if ($workspaceUuid !== null && $workspaceUuid !== '') {
            $query['workspace_uuid'] = $workspaceUuid;
        }

        return '/app'.($query === [] ? '' : '?'.http_build_query($query));
    }

    private function tenantId(Account|AuthenticatedAccount $account): string
    {
        return $account instanceof AuthenticatedAccount
            ? (string) ($account->getTenantId() ?? '')
            : (string) ($account->get('tenant_id') ?? '');
    }
}
