<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Service;

use Claudriel\Entity\Account;
use Claudriel\Entity\Tenant;
use Claudriel\Service\TenantBootstrapService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class TenantBootstrapServiceTest extends TestCase
{
    public function test_bootstrap_creates_tenant_once_and_reuses_it_on_replay(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $account = new Account([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
        ]);
        $entityTypeManager->getStorage('account')->save($account);

        $service = new TenantBootstrapService($entityTypeManager);

        $first = $service->bootstrapForAccount($account);
        $second = $service->bootstrapForAccount($account);

        self::assertInstanceOf(Tenant::class, $first);
        self::assertSame($first->get('uuid'), $second->get('uuid'));
        self::assertCount(1, $entityTypeManager->getStorage('tenant')->getQuery()->execute());
        self::assertSame($first->get('uuid'), $account->get('tenant_id'));
        self::assertContains('tenant_owner', $account->get('roles'));
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
        $entityTypeManager = new EntityTypeManager($dispatcher, function ($definition) use ($db, $dispatcher): SqlEntityStorage {
            (new SqlSchemaHandler($definition, $db))->ensureTable();

            return new SqlEntityStorage($definition, $db, $dispatcher);
        });

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'account',
            label: 'Account',
            class: Account::class,
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'tenant',
            label: 'Tenant',
            class: Tenant::class,
            keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        return $entityTypeManager;
    }
}
