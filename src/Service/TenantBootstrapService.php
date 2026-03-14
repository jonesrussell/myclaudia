<?php

declare(strict_types=1);

namespace Claudriel\Service;

use Claudriel\Entity\Account;
use Claudriel\Entity\Tenant;
use Waaseyaa\Entity\EntityTypeManager;

final class TenantBootstrapService
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function bootstrapForAccount(Account $account): Tenant
    {
        $existing = $this->findByOwnerAccountUuid((string) $account->get('uuid'));
        if ($existing instanceof Tenant) {
            if (($account->get('tenant_id') ?? null) !== $existing->get('uuid')) {
                $account->set('tenant_id', $existing->get('uuid'));
                $this->ensureOwnerRole($account);
                $this->entityTypeManager->getStorage('account')->save($account);
            }

            return $existing;
        }

        $tenant = new Tenant([
            'name' => $this->tenantName($account),
            'slug' => $this->slugify($this->tenantName($account)),
            'owner_account_uuid' => $account->get('uuid'),
            'metadata' => [
                'bootstrap_source' => 'public_signup',
                'bootstrap_state' => 'tenant_ready',
                'owner_email' => $account->getEmail(),
            ],
        ]);
        $this->entityTypeManager->getStorage('tenant')->save($tenant);

        $account->set('tenant_id', $tenant->get('uuid'));
        $this->ensureOwnerRole($account);
        $this->entityTypeManager->getStorage('account')->save($account);

        return $tenant;
    }

    public function findByOwnerAccountUuid(string $accountUuid): ?Tenant
    {
        $ids = $this->entityTypeManager->getStorage('tenant')->getQuery()
            ->condition('owner_account_uuid', $accountUuid)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $tenant = $this->entityTypeManager->getStorage('tenant')->load(reset($ids));

        return $tenant instanceof Tenant ? $tenant : null;
    }

    public function findByUuid(string $uuid): ?Tenant
    {
        $ids = $this->entityTypeManager->getStorage('tenant')->getQuery()
            ->condition('uuid', $uuid)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $tenant = $this->entityTypeManager->getStorage('tenant')->load(reset($ids));

        return $tenant instanceof Tenant ? $tenant : null;
    }

    private function ensureOwnerRole(Account $account): void
    {
        $roles = $account->getRoles();
        if (! in_array('tenant_owner', $roles, true)) {
            $roles[] = 'tenant_owner';
            $account->set('roles', $roles);
        }
    }

    private function tenantName(Account $account): string
    {
        $name = trim((string) ($account->get('name') ?? ''));
        if ($name !== '') {
            return $name."'s Workspace";
        }

        $email = $account->getEmail();
        $localPart = explode('@', $email)[0];

        return ucfirst($localPart)."'s Workspace";
    }

    private function slugify(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? '', '-'));

        return $slug !== '' ? $slug : 'tenant';
    }
}
