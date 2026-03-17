<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\GoogleOAuthController;
use Claudriel\Entity\Account;
use Claudriel\Entity\Integration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class GoogleOAuthControllerTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $_ENV['GOOGLE_CLIENT_ID'] = 'test-client-id';
        $_ENV['GOOGLE_REDIRECT_URI'] = 'https://example.com/auth/google/callback';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset($_ENV['GOOGLE_CLIENT_ID'], $_ENV['GOOGLE_CLIENT_SECRET'], $_ENV['GOOGLE_REDIRECT_URI']);
    }

    public function test_redirect_sets_session_state_and_redirects_to_google(): void
    {
        $etm = $this->buildEntityTypeManager();
        $controller = new GoogleOAuthController($etm);
        $account = new Account(['uuid' => 'acc-uuid-1']);

        $response = $controller->redirect(account: $account);

        self::assertSame(302, $response->getStatusCode());
        self::assertArrayHasKey('google_oauth_state', $_SESSION);
        self::assertNotEmpty($_SESSION['google_oauth_state']);

        $targetUrl = $response->getTargetUrl();
        self::assertStringContainsString('accounts.google.com', $targetUrl);
        self::assertStringContainsString('test-client-id', $targetUrl);
    }

    public function test_redirect_requires_authenticated_account(): void
    {
        $etm = $this->buildEntityTypeManager();
        $controller = new GoogleOAuthController($etm);

        $response = $controller->redirect(account: null);

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getTargetUrl());
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $etm = new EntityTypeManager($dispatcher, function ($def) use ($db, $dispatcher) {
            (new SqlSchemaHandler($def, $db))->ensureTable();

            return new SqlEntityStorage($def, $db, $dispatcher);
        });

        $etm->registerEntityType(new EntityType(
            id: 'integration',
            label: 'Integration',
            class: Integration::class,
            keys: ['id' => 'iid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $etm->registerEntityType(new EntityType(
            id: 'account',
            label: 'Account',
            class: Account::class,
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'email'],
        ));

        return $etm;
    }
}
