<?php

declare(strict_types=1);

namespace Claudriel\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Tenant-scoped: read-only for authenticated users (events are created by ingestion).
 */
#[PolicyAttribute(entityType: 'mc_event')]
final class McEventAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'mc_event';
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
            'view' => AccessResult::allowed('Tenant member can view events.'),
            default => AccessResult::neutral('Events are read-only; created by ingestion.'),
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

        return AccessResult::neutral('Events are created by ingestion, not users.');
    }
}
