<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Access;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityInterface;

trait AccessPolicyTestHelpers
{
    /**
     * @param array<string, mixed> $fields
     */
    private function createEntity(string $entityTypeId, array $fields): EntityInterface
    {
        return new class ($entityTypeId, $fields) implements EntityInterface {
            /**
             * @param array<string, mixed> $fields
             */
            public function __construct(
                private readonly string $entityTypeId,
                private readonly array $fields,
            ) {}

            public function get(string $field): mixed
            {
                return $this->fields[$field] ?? null;
            }

            public function id(): int|string|null
            {
                return $this->fields['id'] ?? 1;
            }

            public function uuid(): string
            {
                return '';
            }

            public function label(): string
            {
                return 'Test ' . $this->entityTypeId;
            }

            public function getEntityTypeId(): string
            {
                return $this->entityTypeId;
            }

            public function bundle(): string
            {
                return $this->entityTypeId;
            }

            public function toArray(): array
            {
                return $this->fields;
            }

            public function isNew(): bool
            {
                return false;
            }

            public function language(): string
            {
                return 'en';
            }
        };
    }

    private function createAuthenticatedAccount(int $id, ?string $tenantId, bool $isAdmin = false): AccountInterface
    {
        return new class ($id, $tenantId, $isAdmin) implements AccountInterface {
            public function __construct(
                private readonly int $accountId,
                private readonly ?string $tenantId,
                private readonly bool $isAdmin,
            ) {}

            public function id(): int|string
            {
                return $this->accountId;
            }

            public function isAuthenticated(): bool
            {
                return true;
            }

            public function hasPermission(string $permission): bool
            {
                return $this->isAdmin && $permission === 'administer content';
            }

            public function getRoles(): array
            {
                return [];
            }

            public function getTenantId(): ?string
            {
                return $this->tenantId;
            }
        };
    }

    private function createAnonymousAccount(): AccountInterface
    {
        return new class implements AccountInterface {
            public function id(): int|string
            {
                return 0;
            }

            public function isAuthenticated(): bool
            {
                return false;
            }

            public function hasPermission(string $permission): bool
            {
                return false;
            }

            public function getRoles(): array
            {
                return [];
            }

            public function getTenantId(): ?string
            {
                return null;
            }
        };
    }
}
