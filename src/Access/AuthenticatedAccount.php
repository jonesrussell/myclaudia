<?php

declare(strict_types=1);

namespace Claudriel\Access;

use Claudriel\Entity\Account;
use Waaseyaa\Access\AccountInterface;

final class AuthenticatedAccount implements AccountInterface
{
    public function __construct(
        private readonly Account $account,
    ) {}

    public function id(): int|string
    {
        return $this->account->id() ?? 0;
    }

    public function hasPermission(string $permission): bool
    {
        return $this->account->hasPermission($permission);
    }

    /**
     * @return string[]
     */
    public function getRoles(): array
    {
        return $this->account->getRoles();
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function getTenantId(): ?string
    {
        $tenantId = $this->account->get('tenant_id');

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : null;
    }

    public function getUuid(): ?string
    {
        $uuid = $this->account->get('uuid');

        return is_string($uuid) && $uuid !== '' ? $uuid : null;
    }

    public function getEmail(): string
    {
        return $this->account->getEmail();
    }

    public function account(): Account
    {
        return $this->account;
    }
}
