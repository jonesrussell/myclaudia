<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Service;

use Claudriel\Entity\Account;
use Claudriel\Service\PublicAccountSignupService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class PublicAccountSignupServiceGoogleTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    private PublicAccountSignupService $service;

    protected function setUp(): void
    {
        $this->entityTypeManager = $this->buildEntityTypeManager();
        $this->service = new PublicAccountSignupService($this->entityTypeManager);
    }

    public function test_create_from_google_creates_active_account(): void
    {
        $account = $this->service->createFromGoogle('ada@example.com', 'Ada Lovelace');

        self::assertInstanceOf(Account::class, $account);
        self::assertSame('ada@example.com', $account->get('email'));
        self::assertSame('Ada Lovelace', $account->get('name'));
        self::assertNull($account->get('password_hash'));
        self::assertSame('active', $account->get('status'));
        self::assertNotNull($account->get('email_verified_at'));
    }

    public function test_create_from_google_returns_existing_account_if_email_matches(): void
    {
        $first = $this->service->createFromGoogle('ada@example.com', 'Ada Lovelace');
        $second = $this->service->createFromGoogle('ada@example.com', 'Ada L');

        self::assertSame($first->get('uuid'), $second->get('uuid'));
    }

    public function test_create_from_google_activates_pending_verification_account(): void
    {
        $storage = $this->entityTypeManager->getStorage('account');
        $pending = new Account([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'status' => 'pending_verification',
            'roles' => [],
            'permissions' => [],
        ]);
        $storage->save($pending);

        $account = $this->service->createFromGoogle('ada@example.com', 'Ada Lovelace');

        self::assertSame('active', $account->get('status'));
        self::assertNotNull($account->get('email_verified_at'));
        self::assertSame($pending->get('uuid'), $account->get('uuid'));
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = DBALDatabase::createSqlite(':memory:');
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

        return $entityTypeManager;
    }
}
