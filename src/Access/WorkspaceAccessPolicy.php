<?php

declare(strict_types=1);

namespace Claudriel\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Personal: only owner can CRUD. Workspaces are not shared within a tenant.
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

        $accountId = (string) $account->id();

        if ($entity->get('account_id') !== $accountId) {
            return AccessResult::forbidden('Workspaces are personal. Only the owner can access.');
        }

        return match ($operation) {
            'view', 'update', 'delete' => AccessResult::allowed('Owner can manage their workspace.'),
            default => AccessResult::neutral('Unknown operation.'),
        };
    }

    public function createAccess(string $entityTypeId, string $bundle, AccountInterface $account): AccessResult
    {
        if (! $account->isAuthenticated()) {
            return AccessResult::unauthenticated('Authentication required.');
        }

        return AccessResult::allowed('Authenticated user can create workspaces.');
    }
}
