<?php

declare(strict_types=1);

namespace Claudriel\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Tenant-scoped: any authenticated user can CRUD.
 */
#[PolicyAttribute(entityType: 'schedule_entry')]
final class ScheduleEntryAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'schedule_entry';
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

        return match ($operation) {
            'view', 'update', 'delete' => AccessResult::allowed('Tenant member can manage schedule entries.'),
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

        return AccessResult::allowed('Authenticated tenant member can create schedule entries.');
    }
}
