<?php

declare(strict_types=1);

namespace Claudriel\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Tenant-scoped: owner can CRUD, tenant members can view.
 */
#[PolicyAttribute(entityType: 'repo')]
final class RepoAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'repo';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (! $account->isAuthenticated()) {
            return AccessResult::unauthenticated('Authentication required.');
        }

        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        $accountId = (string) $account->id();

        if ($entity->get('account_id') === $accountId) {
            return AccessResult::allowed('Owner can manage their repo.');
        }

        $entityTenantId = $entity->get('tenant_id');
        $accountTenantId = $account->getTenantId();

        if ($entityTenantId !== null && $accountTenantId !== null && $entityTenantId === $accountTenantId) {
            return match ($operation) {
                'view' => AccessResult::allowed('Tenant member can view repos.'),
                'update', 'delete' => AccessResult::neutral('Only owner or admin can modify repos.'),
                default => AccessResult::neutral('Unknown operation.'),
            };
        }

        return AccessResult::neutral('No access granted.');
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (! $account->isAuthenticated()) {
            return AccessResult::unauthenticated('Authentication required.');
        }

        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        if ($account->getTenantId() === null) {
            return AccessResult::forbidden('No tenant assigned.');
        }

        return AccessResult::allowed('Authenticated tenant member can create repos.');
    }
}
