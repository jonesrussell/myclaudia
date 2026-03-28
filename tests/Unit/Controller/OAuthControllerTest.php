<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\OAuthController;
use Claudriel\Entity\Account;
use Claudriel\Entity\Integration;
use Claudriel\Service\PublicAccountSignupService;
use Claudriel\Support\NativeSessionAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthStateManager;
use Waaseyaa\OAuthProvider\ProviderRegistry;

final class OAuthControllerTest extends TestCase
{
    private EntityTypeManager $etm;

    private ProviderRegistry $registry;

    private OAuthStateManager $stateManager;

    private NativeSessionAdapter $session;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $this->etm = $this->buildEntityTypeManager();
        $this->registry = new ProviderRegistry;
        $this->stateManager = new OAuthStateManager;
        $this->session = new NativeSessionAdapter;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function test_connect_redirects_to_provider_auth_url(): void
    {
        $provider = $this->createMock(OAuthProviderInterface::class);
        $provider->method('getAuthorizationUrl')->willReturn('https://accounts.google.com/authorize?foo=bar');
        $this->registry->register('google', $provider);

        $controller = $this->buildController();
        $account = new AuthenticatedAccount(new Account(['uuid' => 'acc-uuid-1']));

        $response = $controller->connect(
            params: ['provider' => 'google'],
            account: $account,
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertStringContainsString('accounts.google.com', $response->getTargetUrl());
    }

    public function test_connect_redirects_to_login_when_unauthenticated(): void
    {
        $controller = $this->buildController();

        $response = $controller->connect(
            params: ['provider' => 'google'],
            account: null,
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->getTargetUrl());
    }

    public function test_connect_returns_error_for_unknown_provider(): void
    {
        $controller = $this->buildController();
        $account = new AuthenticatedAccount(new Account(['uuid' => 'acc-uuid-1']));

        $response = $controller->connect(
            params: ['provider' => 'bitbucket'],
            account: $account,
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/app', $response->getTargetUrl());
        self::assertStringContainsString('Unknown OAuth provider', $_SESSION['flash_error']);
    }

    public function test_connect_callback_handles_error_param(): void
    {
        $provider = $this->createMock(OAuthProviderInterface::class);
        $this->registry->register('google', $provider);

        $controller = $this->buildController();
        $account = new AuthenticatedAccount(new Account(['uuid' => 'acc-uuid-1']));

        $response = $controller->connectCallback(
            params: ['provider' => 'google'],
            query: ['error' => 'access_denied'],
            account: $account,
        );

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/app', $response->getTargetUrl());
        self::assertStringContainsString('authorization denied', $_SESSION['flash_error']);
    }

    private function buildController(): OAuthController
    {
        $signupService = new PublicAccountSignupService($this->etm);

        return new OAuthController(
            $this->registry,
            $this->stateManager,
            $this->etm,
            $signupService,
            $this->session,
        );
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
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'email'],
        ));

        return $etm;
    }
}
