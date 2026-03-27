<?php

declare(strict_types=1);

namespace Claudriel\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Tenant-scoped access for junction entities (project_repo, workspace_project, workspace_repo).
 */
#[PolicyAttribute(entityType: ['project_repo', 'workspace_project', 'workspace_repo'])]
final class JunctionAccessPolicy implements AccessPolicyInterface
{
    private const JUNCTION_TYPES = ['project_repo', 'workspace_project', 'workspace_repo'];

    public function appliesTo(string $entityTypeId): bool
    {
        return in_array($entityTypeId, self::JUNCTION_TYPES, true);
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
            return AccessResult::allowed('Owner can manage junction.');
        }

        $entityTenantId = $entity->get('tenant_id');
        $accountTenantId = $account->getTenantId();

        if ($entityTenantId !== null && $accountTenantId !== null && $entityTenantId === $accountTenantId) {
            return AccessResult::allowed('Tenant member can access junction.');
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

        return AccessResult::allowed('Authenticated tenant member can create junctions.');
    }
}
