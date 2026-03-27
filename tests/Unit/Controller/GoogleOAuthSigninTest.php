<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\GoogleOAuthController;
use Claudriel\Entity\Account;
use Claudriel\Entity\Integration;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class GoogleOAuthSigninTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];
        $_ENV['GOOGLE_CLIENT_ID'] = 'test-client-id';
        $_ENV['GOOGLE_CLIENT_SECRET'] = 'test-secret';
        $_ENV['GOOGLE_REDIRECT_URI'] = 'https://claudriel.ai/auth/google/callback';
        $_ENV['GOOGLE_SIGNIN_REDIRECT_URI'] = 'https://claudriel.ai/auth/google/signin/callback';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        unset(
            $_ENV['GOOGLE_CLIENT_ID'],
            $_ENV['GOOGLE_CLIENT_SECRET'],
            $_ENV['GOOGLE_REDIRECT_URI'],
            $_ENV['GOOGLE_SIGNIN_REDIRECT_URI'],
        );
    }

    public function test_signin_redirects_to_google_with_identity_scopes(): void
    {
        $controller = new GoogleOAuthController($this->buildEntityTypeManager());

        $response = $controller->signin();

        self::assertSame(302, $response->getStatusCode());
        $location = $response->headers->get('Location');
        self::assertStringContainsString('accounts.google.com', $location);
        self::assertStringContainsString('openid', $location);
        self::assertStringContainsString('userinfo.email', $location);
        self::assertStringContainsString('userinfo.profile', $location);
        self::assertStringNotContainsString('gmail', $location);
        self::assertStringNotContainsString('calendar', $location);
        self::assertStringContainsString('signin%2Fcallback', $location);
    }

    public function test_signin_sets_flow_session_variable(): void
    {
        $controller = new GoogleOAuthController($this->buildEntityTypeManager());

        $controller->signin();

        self::assertSame('signin', $_SESSION['google_oauth_flow']);
        self::assertNotEmpty($_SESSION['google_oauth_state']);
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
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
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        return $etm;
    }
}
