<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Account extends ContentEntityBase
{
    protected string $entityTypeId = 'account';

    protected array $entityKeys = [
        'id' => 'aid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'account', $this->entityKeys);

        if ($this->get('roles') === null) {
            $this->set('roles', []);
        }
        if ($this->get('permissions') === null) {
            $this->set('permissions', []);
        }
        if ($this->get('status') === null) {
            $this->set('status', 'pending_verification');
        }
        if ($this->get('email_verified_at') === null) {
            $this->set('email_verified_at', null);
        }
        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', null);
        }
        if ($this->get('settings') === null) {
            $this->set('settings', []);
        }
        if ($this->get('metadata') === null) {
            $this->set('metadata', []);
        }
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = $this->get('permissions');

        return is_array($permissions) && in_array($permission, $permissions, true);
    }

    /**
     * @return string[]
     */
    public function getRoles(): array
    {
        $roles = $this->get('roles');

        return is_array($roles) ? array_values(array_filter($roles, is_string(...))) : [];
    }

    public function isAuthenticated(): bool
    {
        return $this->id() !== null && $this->isVerified();
    }

    public function getEmail(): string
    {
        return trim((string) ($this->get('email') ?? ''));
    }

    public function isVerified(): bool
    {
        return $this->get('status') === 'active' && $this->get('email_verified_at') !== null;
    }
}
