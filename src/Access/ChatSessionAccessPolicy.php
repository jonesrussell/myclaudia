<?php

declare(strict_types=1);

namespace Claudriel\Access;

use Waaseyaa\Access\AccessPolicyInterface;
use Waaseyaa\Access\AccessResult;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\Gate\PolicyAttribute;
use Waaseyaa\Entity\EntityInterface;

/**
 * Tenant-scoped: owner can CRUD their own sessions.
 */
#[PolicyAttribute(entityType: 'chat_session')]
final class ChatSessionAccessPolicy implements AccessPolicyInterface
{
    public function appliesTo(string $entityTypeId): bool
    {
        return $entityTypeId === 'chat_session';
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

        if (! $isOwner) {
            return AccessResult::forbidden('Users can only access their own chat sessions.');
        }

        return match ($operation) {
            'view', 'update', 'delete' => AccessResult::allowed('Owner can manage their chat session.'),
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

        return AccessResult::allowed('Authenticated tenant member can create chat sessions.');
    }
}
