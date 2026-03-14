<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Routing;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Account;
use Claudriel\Routing\AccountSessionMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;

final class AccountSessionMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetSession();
    }

    protected function tearDown(): void
    {
        $this->resetSession();
    }

    public function test_session_persists_authenticated_account_across_requests(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $account = new Account([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
            'tenant_id' => 'tenant-123',
        ]);
        $entityTypeManager->getStorage('account')->save($account);
        $_SESSION['claudriel_account_uuid'] = $account->get('uuid');

        $middleware = new AccountSessionMiddleware($entityTypeManager);
        $request = Request::create('/account/session', 'GET');

        $response = $middleware->process($request, new class implements HttpHandlerInterface
        {
            public function handle(Request $request): Response
            {
                $account = $request->attributes->get('_account');

                return new Response($account instanceof AuthenticatedAccount ? 'authenticated' : 'anonymous', 200);
            }
        });

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('authenticated', $response->getContent());
        self::assertInstanceOf(AuthenticatedAccount::class, $request->attributes->get('_account'));
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

        return $entityTypeManager;
    }

    private function resetSession(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        $_SESSION = [];
        session_id('claudriel-account-middleware-'.bin2hex(random_bytes(4)));
        session_start();
    }
}
