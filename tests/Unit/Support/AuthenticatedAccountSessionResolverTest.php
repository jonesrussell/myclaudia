<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Account;
use Claudriel\Entity\Tenant;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Doctrine\DBAL\Schema\SchemaException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

#[CoversClass(AuthenticatedAccountSessionResolver::class)]
final class AuthenticatedAccountSessionResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }
        $_SESSION = [];
        parent::tearDown();
    }

    #[Test]
    public function resolve_returns_null_without_session_uuid(): void
    {
        $this->startCleanSession();
        $etm = $this->entityTypeManager();
        $resolver = new AuthenticatedAccountSessionResolver($etm);
        $this->seedVerifiedAccount($etm);

        self::assertNull($resolver->resolve());
    }

    #[Test]
    public function resolve_returns_authenticated_account_when_session_matches_verified_user(): void
    {
        $this->startCleanSession();
        $etm = $this->entityTypeManager();
        $account = $this->seedVerifiedAccount($etm);
        $_SESSION['claudriel_account_uuid'] = (string) $account->get('uuid');

        $resolver = new AuthenticatedAccountSessionResolver($etm);
        $resolved = $resolver->resolve();

        self::assertInstanceOf(AuthenticatedAccount::class, $resolved);
        self::assertSame((string) $account->get('uuid'), $resolved->getUuid());
    }

    #[Test]
    public function resolve_returns_null_when_session_points_at_unverified_account(): void
    {
        $this->startCleanSession();
        $etm = $this->entityTypeManager();
        $account = new Account([
            'name' => 'Pending',
            'email' => 'pending@example.com',
            'password_hash' => password_hash('x', PASSWORD_DEFAULT),
            'status' => 'pending',
            'email_verified_at' => null,
            'roles' => [],
        ]);
        $etm->getStorage('account')->save($account);
        $_SESSION['claudriel_account_uuid'] = (string) $account->get('uuid');

        $resolver = new AuthenticatedAccountSessionResolver($etm);

        self::assertNull($resolver->resolve());
    }

    private function startCleanSession(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        $_SESSION = [];
        session_id('claudriel-test-'.bin2hex(random_bytes(4)));
        session_start();
    }

    /**
     * @throws SchemaException
     */
    private function entityTypeManager(): EntityTypeManager
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
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'tenant',
            label: 'Tenant',
            class: Tenant::class,
            keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        return $entityTypeManager;
    }

    private function seedVerifiedAccount(EntityTypeManager $entityTypeManager): Account
    {
        $account = new Account([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password_hash' => password_hash('secret', PASSWORD_DEFAULT),
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
            'tenant_id' => 'tenant-123',
            'roles' => ['tenant_owner'],
        ]);
        $entityTypeManager->getStorage('account')->save($account);

        return $account;
    }
}
