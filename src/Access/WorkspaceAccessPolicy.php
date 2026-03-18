<?php

declare(strict_types=1);

namespace Claudriel\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Tenant-scoped: owner or admin can CRUD.
 */
#[PolicyAttribute(entityType: 'workspace')]
final class WorkspaceAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'workspace';
    }

    public function access(EntityInterface $entity, string $operation, AccountInterface $account): AccessResult
    {
        if (! $account->isAuthenticated()) {
            return AccessResult::unauthenticated('Authentication required.');
        }

        if ($account->hasPermission('administer content')) {
            return AccessResult::allowed('Admin permission.');
        }

        $entityTenantId = $entity->get('tenant_id');
        $accountTenantId = $account->getTenantId();

        if ($entityTenantId === null || $accountTenantId === null || $entityTenantId !== $accountTenantId) {
            return AccessResult::forbidden('Tenant mismatch.');
        }

        $ownerId = $entity->get('owner_id');
        $accountId = (string) $account->id();
        $isOwner = $ownerId !== null && (string) $ownerId === $accountId;

        return match ($operation) {
            'view' => AccessResult::allowed('Tenant member can view workspaces.'),
            'update', 'delete' => $isOwner
                ? AccessResult::allowed('Owner can modify their workspace.')
                : AccessResult::neutral('Only owner or admin can modify workspaces.'),
            default => AccessResult::neutral('Unknown operation.'),
        };
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

        return AccessResult::allowed('Authenticated tenant member can create workspaces.');
    }
}
