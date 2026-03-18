<?php

declare(strict_types=1);

namespace Claudriel\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Tenant-scoped: read for authenticated, write for system/admin.
 */
#[PolicyAttribute(entityType: 'triage_entry')]
final class TriageEntryAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'triage_entry';
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
            'view' => AccessResult::allowed('Tenant member can view triage entries.'),
            default => AccessResult::neutral('Only system or admin can modify triage entries.'),
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

        return AccessResult::neutral('Only system or admin can create triage entries.');
    }
}
