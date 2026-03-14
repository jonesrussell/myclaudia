<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\PublicAccountController;
use Claudriel\Entity\Account;
use Claudriel\Entity\AccountVerificationToken;
use Claudriel\Entity\Tenant;
use Claudriel\Entity\Workspace;
use Claudriel\Service\Mail\MailTransportInterface;
use Claudriel\Service\SidecarWorkspaceBootstrapService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class PublicAccountControllerTest extends TestCase
{
    public function test_signup_form_renders_public_signup_surface(): void
    {
        $controller = $this->controller();

        $response = $controller->signupForm();

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Create Your Claudriel Account', $response->content);
        self::assertStringContainsString('Create account', $response->content);
    }

    public function test_signup_form_redirects_authenticated_account_into_app_shell(): void
    {
        $controller = $this->controller();
        $account = new Account([
            'name' => 'Ready User',
            'email' => 'ready@example.com',
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
            'tenant_id' => 'tenant-123',
        ]);

        $response = $controller->signupForm(account: new AuthenticatedAccount($account));

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/app?tenant_id=tenant-123', $response->getTargetUrl());
    }

    public function test_signup_creates_pending_account_and_sends_verification_delivery(): void
    {
        $transport = new InMemoryMailTransport;
        $entityTypeManager = $this->buildEntityTypeManager();
        $controller = $this->controller($entityTypeManager, $transport);

        $response = $controller->signup(
            httpRequest: Request::create('/signup', 'POST', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'correct horse battery staple',
            ]),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/signup/check-email?email=test%40example.com', $response->getTargetUrl());

        $accounts = $entityTypeManager->getStorage('account')->loadMultiple(
            $entityTypeManager->getStorage('account')->getQuery()->execute(),
        );
        $account = array_values($accounts)[0] ?? null;

        self::assertInstanceOf(Account::class, $account);
        self::assertSame('pending_verification', $account->get('status'));
        self::assertNull($account->get('email_verified_at'));
        self::assertTrue(password_verify('correct horse battery staple', (string) $account->get('password_hash')));

        $tokens = $entityTypeManager->getStorage('account_verification_token')->loadMultiple(
            $entityTypeManager->getStorage('account_verification_token')->getQuery()->execute(),
        );
        $token = array_values($tokens)[0] ?? null;

        self::assertInstanceOf(AccountVerificationToken::class, $token);
        self::assertSame($account->get('uuid'), $token->get('account_uuid'));
        self::assertCount(1, $transport->messages);
        self::assertSame('Verify your Claudriel account', $transport->messages[0]['subject']);
        self::assertStringContainsString('/verify-email/', (string) $transport->messages[0]['verification_url']);
    }

    public function test_verification_link_bootstraps_tenant_once_and_redirects_to_onboarding(): void
    {
        $transport = new InMemoryMailTransport;
        $entityTypeManager = $this->buildEntityTypeManager();
        $controller = $this->controller(
            $entityTypeManager,
            $transport,
            new SidecarWorkspaceBootstrapService(responder: static fn (string $tenantId, string $workspaceId): array => [
                'state' => 'created',
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceId,
            ]),
        );

        $controller->signup(
            httpRequest: Request::create('/signup', 'POST', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'correct horse battery staple',
            ]),
        );

        $verificationUrl = (string) $transport->messages[0]['verification_url'];
        $token = basename($verificationUrl);

        $first = $controller->verifyEmail(['token' => $token]);
        self::assertInstanceOf(RedirectResponse::class, $first);
        self::assertStringStartsWith('/onboarding/bootstrap?verified=1&account=', $first->getTargetUrl());

        $accounts = $entityTypeManager->getStorage('account')->loadMultiple(
            $entityTypeManager->getStorage('account')->getQuery()->execute(),
        );
        $account = array_values($accounts)[0] ?? null;
        self::assertInstanceOf(Account::class, $account);
        self::assertSame('active', $account->get('status'));
        self::assertNotNull($account->get('email_verified_at'));
        self::assertContains('tenant_owner', $account->getRoles());
        self::assertNotNull($account->get('tenant_id'));

        $tenantStorage = $entityTypeManager->getStorage('tenant');
        $tenants = $tenantStorage->loadMultiple($tenantStorage->getQuery()->execute());
        $tenant = array_values($tenants)[0] ?? null;
        self::assertInstanceOf(Tenant::class, $tenant);
        self::assertSame($account->get('uuid'), $tenant->get('owner_account_uuid'));
        self::assertSame($tenant->get('uuid'), $account->get('tenant_id'));
        self::assertSame('tenant_ready', $tenant->get('metadata')['bootstrap_state']);

        $workspaceStorage = $entityTypeManager->getStorage('workspace');
        $workspaces = $workspaceStorage->loadMultiple($workspaceStorage->getQuery()->execute());
        $workspace = array_values($workspaces)[0] ?? null;
        self::assertInstanceOf(Workspace::class, $workspace);
        self::assertSame($tenant->get('uuid'), $workspace->get('tenant_id'));
        self::assertSame('Main Workspace', $workspace->get('name'));
        $workspaceMetadata = json_decode((string) $workspace->get('metadata'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('default', $workspaceMetadata['bootstrap_kind']);
        self::assertSame('created', $workspaceMetadata['sidecar_bootstrap']['state']);
        self::assertSame($workspace->get('uuid'), $tenant->get('metadata')['default_workspace_uuid']);

        $onboarding = $controller->onboardingBootstrap(query: [
            'verified' => '1',
            'account' => (string) $account->get('uuid'),
            'tenant' => (string) $tenant->get('uuid'),
            'workspace' => (string) $workspace->get('uuid'),
        ]);
        self::assertInstanceOf(RedirectResponse::class, $onboarding);
        self::assertSame('/app?verified=1&tenant_id='.$tenant->get('uuid').'&workspace_uuid='.$workspace->get('uuid'), $onboarding->getTargetUrl());

        $second = $controller->verifyEmail(['token' => $token]);
        self::assertSame('/signup/verification-result?status=invalid', $second->getTargetUrl());
        self::assertCount(1, $tenantStorage->getQuery()->execute());
        self::assertCount(1, $workspaceStorage->getQuery()->execute());
    }

    private function controller(
        ?EntityTypeManager $entityTypeManager = null,
        ?MailTransportInterface $transport = null,
        ?SidecarWorkspaceBootstrapService $sidecarWorkspaceBootstrapService = null,
    ): PublicAccountController {
        return new PublicAccountController(
            $entityTypeManager ?? $this->buildEntityTypeManager(),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
            $transport,
            'https://claudriel.test',
            sys_get_temp_dir().'/claudriel-signup-tests',
            $sidecarWorkspaceBootstrapService,
        );
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
            id: 'account_verification_token',
            label: 'Account Verification Token',
            class: AccountVerificationToken::class,
            keys: ['id' => 'avtid', 'uuid' => 'uuid'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'tenant',
            label: 'Tenant',
            class: Tenant::class,
            keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        return $entityTypeManager;
    }
}

final class InMemoryMailTransport implements MailTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public function send(array $message): array
    {
        $this->messages[] = $message;

        return [
            'transport' => 'memory',
            'status' => 'queued',
        ];
    }
}
