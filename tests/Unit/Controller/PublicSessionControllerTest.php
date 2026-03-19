<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\PublicSessionController;
use Claudriel\Entity\Account;
use Claudriel\Entity\Tenant;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\SSR\SsrResponse;

final class PublicSessionControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetSession();
    }

    protected function tearDown(): void
    {
        $this->resetSession();
    }

    public function test_login_form_renders_public_auth_surface(): void
    {
        $response = $this->controller()->loginForm();

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Your day is waiting.', $response->content);
    }

    public function test_login_form_redirects_authenticated_session_into_app_shell(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $account = $this->seedVerifiedAccount($entityTypeManager);
        $entityTypeManager->getStorage('tenant')->save(new Tenant([
            'uuid' => 'tenant-123',
            'name' => 'Tenant One',
            'metadata' => ['default_workspace_uuid' => 'workspace-abc'],
        ]));
        $_SESSION['claudriel_account_uuid'] = $account->get('uuid');

        $response = $this->controller($entityTypeManager)->loginForm();

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/app?tenant_id=tenant-123&workspace_uuid=workspace-abc', $response->getTargetUrl());
    }

    public function test_login_form_honors_safe_redirect_targets_for_authenticated_sessions(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $account = $this->seedVerifiedAccount($entityTypeManager);
        $_SESSION['claudriel_account_uuid'] = $account->get('uuid');

        $response = $this->controller($entityTypeManager)->loginForm(query: ['redirect' => '/admin']);

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/admin', $response->getTargetUrl());
    }

    public function test_verified_account_can_log_in_and_logout(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $account = $this->seedVerifiedAccount($entityTypeManager);
        $entityTypeManager->getStorage('tenant')->save(new Tenant([
            'uuid' => 'tenant-123',
            'name' => 'Tenant One',
            'metadata' => ['default_workspace_uuid' => 'workspace-abc'],
        ]));
        $controller = $this->controller($entityTypeManager);

        $login = $controller->login(
            httpRequest: Request::create('/login', 'POST', [
                'email' => 'test@example.com',
                'password' => 'correct horse battery staple',
            ]),
        );

        self::assertInstanceOf(RedirectResponse::class, $login);
        self::assertSame('/app?login=1&tenant_id=tenant-123&workspace_uuid=workspace-abc', $login->getTargetUrl());
        self::assertSame($account->get('uuid'), $_SESSION['claudriel_account_uuid'] ?? null);

        $sessionState = $controller->sessionState(account: new AuthenticatedAccount($account));
        self::assertSame(200, $sessionState->statusCode);
        self::assertStringContainsString('test@example.com', $sessionState->content);
        self::assertStringContainsString('workspace-abc', $sessionState->content);

        $logout = $controller->logout();
        self::assertSame('/?logged_out=1', $logout->getTargetUrl());
        self::assertArrayNotHasKey('claudriel_account_uuid', $_SESSION);
    }

    public function test_login_redirects_to_safe_redirect_target_when_provided(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $this->seedVerifiedAccount($entityTypeManager);
        $controller = $this->controller($entityTypeManager);

        $login = $controller->login(
            httpRequest: Request::create('/login', 'POST', [
                'email' => 'test@example.com',
                'password' => 'correct horse battery staple',
                'redirect' => '/admin',
            ]),
        );

        self::assertInstanceOf(RedirectResponse::class, $login);
        self::assertSame('/admin', $login->getTargetUrl());
    }

    public function test_session_state_uses_authenticated_session_when_account_argument_is_missing(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $account = $this->seedVerifiedAccount($entityTypeManager);
        $entityTypeManager->getStorage('tenant')->save(new Tenant([
            'uuid' => 'tenant-123',
            'name' => 'Tenant One',
            'metadata' => ['default_workspace_uuid' => 'workspace-abc'],
        ]));
        $_SESSION['claudriel_account_uuid'] = $account->get('uuid');

        $response = $this->controller($entityTypeManager)->sessionState();

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('test@example.com', $response->content);
        self::assertStringContainsString('workspace-abc', $response->content);
    }

    private function controller(?EntityTypeManager $entityTypeManager = null): PublicSessionController
    {
        return new PublicSessionController(
            $entityTypeManager ?? $this->buildEntityTypeManager(),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
        );
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
            'password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
            'tenant_id' => 'tenant-123',
            'roles' => ['tenant_owner'],
        ]);
        $entityTypeManager->getStorage('account')->save($account);

        return $account;
    }

    private function resetSession(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        $_SESSION = [];
        session_id('claudriel-public-session-'.bin2hex(random_bytes(4)));
        session_start();
    }
}
