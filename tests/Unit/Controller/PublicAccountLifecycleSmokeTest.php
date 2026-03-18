<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\BriefStreamController;
use Claudriel\Controller\ChatStreamController;
use Claudriel\Controller\DashboardController;
use Claudriel\Controller\PublicAccountController;
use Claudriel\Controller\PublicPasswordResetController;
use Claudriel\Controller\PublicSessionController;
use Claudriel\Entity\Account;
use Claudriel\Entity\AccountPasswordResetToken;
use Claudriel\Entity\AccountVerificationToken;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\Skill;
use Claudriel\Entity\TemporalNotification;
use Claudriel\Entity\Tenant;
use Claudriel\Entity\TriageEntry;
use Claudriel\Entity\Workspace;
use Claudriel\Service\Mail\MailTransportInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class PublicAccountLifecycleSmokeTest extends TestCase
{
    public function test_full_public_account_lifecycle_supports_first_authenticated_use(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $mailTransport = new PublicLifecycleMailTransport;

        $accountController = new PublicAccountController(
            $entityTypeManager,
            null,
            $mailTransport,
            'https://claudriel.test',
            sys_get_temp_dir().'/claudriel-public-lifecycle',
        );

        $signup = $accountController->signup(httpRequest: Request::create('/signup', 'POST', [
            'name' => 'Smoke User',
            'email' => 'smoke@example.com',
            'password' => 'initial secret',
        ]));
        self::assertInstanceOf(RedirectResponse::class, $signup);

        $verificationToken = basename((string) $mailTransport->messages[0]['verification_url']);
        $verify = $accountController->verifyEmail(['token' => $verificationToken]);
        self::assertInstanceOf(RedirectResponse::class, $verify);

        $accounts = $entityTypeManager->getStorage('account')->loadMultiple(
            $entityTypeManager->getStorage('account')->getQuery()->execute(),
        );
        $account = array_values($accounts)[0] ?? null;
        self::assertInstanceOf(Account::class, $account);
        $authenticated = new AuthenticatedAccount($account);

        $passwordResetController = new PublicPasswordResetController(
            $entityTypeManager,
            null,
            $mailTransport,
            'https://claudriel.test',
            sys_get_temp_dir().'/claudriel-public-lifecycle',
        );
        $passwordResetController->requestReset(
            httpRequest: Request::create('/forgot-password', 'POST', ['email' => 'smoke@example.com']),
        );
        $resetToken = basename((string) $mailTransport->messages[1]['reset_url']);
        $passwordResetController->resetPassword(
            params: ['token' => $resetToken],
            httpRequest: Request::create('/reset-password/'.$resetToken, 'POST', ['password' => 'smoke reset secret']),
        );

        $sessionController = new PublicSessionController($entityTypeManager);
        $login = $sessionController->login(
            httpRequest: Request::create('/login', 'POST', [
                'email' => 'smoke@example.com',
                'password' => 'smoke reset secret',
            ]),
        );
        self::assertInstanceOf(RedirectResponse::class, $login);
        self::assertStringContainsString('tenant_id=', $login->getTargetUrl());
        self::assertStringContainsString('workspace_uuid=', $login->getTargetUrl());

        $dashboard = new DashboardController($entityTypeManager);
        $dashboardResponse = $dashboard->show(account: $authenticated, httpRequest: Request::create('/dashboard'));
        self::assertSame(200, $dashboardResponse->statusCode);
        self::assertStringContainsString('Main Workspace', $dashboardResponse->content);

        $briefStream = new BriefStreamController($entityTypeManager);
        $briefFallback = $briefStream->stream(
            query: ['transport' => 'fallback'],
            account: $authenticated,
            httpRequest: Request::create('/stream/brief', 'GET'),
        );
        self::assertSame(200, $briefFallback->statusCode);
        self::assertStringContainsString('Main Workspace', $briefFallback->content);

        // Workspace CRUD now served by /api/graphql (v1.4 admin migration).
        // Verify workspace lifecycle via entity storage directly.
        $workspaceStorage = $entityTypeManager->getStorage('workspace');
        $phoenix = new Workspace([
            'name' => 'Project Phoenix',
            'tenant_id' => (string) $account->get('tenant_id'),
        ]);
        $workspaceStorage->save($phoenix);
        self::assertNotEmpty($phoenix->get('uuid'));

        $phoenix->set('description', 'Renamed from smoke');
        $workspaceStorage->save($phoenix);

        $reloaded = $workspaceStorage->load($phoenix->id());
        self::assertInstanceOf(Workspace::class, $reloaded);
        self::assertSame('Renamed from smoke', $reloaded->get('description'));

        $workspaceStorage->delete([$phoenix]);
        self::assertNull($workspaceStorage->load($phoenix->id()));

        $tenantId = (string) $account->get('tenant_id');
        $defaultWorkspaceUuid = (string) ($this->firstTenant($entityTypeManager)?->get('metadata')['default_workspace_uuid'] ?? '');

        $sessionStorage = $entityTypeManager->getStorage('chat_session');
        $sessionStorage->save(new ChatSession([
            'uuid' => 'signup-smoke-chat',
            'title' => 'Workspace bootstrap',
            'created_at' => date('c'),
            'tenant_id' => $tenantId,
            'workspace_id' => $defaultWorkspaceUuid,
        ]));
        $messageStorage = $entityTypeManager->getStorage('chat_message');
        $messageStorage->save(new ChatMessage([
            'uuid' => 'signup-smoke-message',
            'session_uuid' => 'signup-smoke-chat',
            'role' => 'user',
            'content' => 'create a workspace named "Delivery Ops"',
            'created_at' => date('c'),
            'tenant_id' => $tenantId,
            'workspace_id' => $defaultWorkspaceUuid,
        ]));

        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $chatStream = new ChatStreamController($entityTypeManager);
        $chatResponse = $chatStream->stream(
            ['messageId' => 'signup-smoke-message'],
            [],
            $authenticated,
            null,
        );
        self::assertSame(200, $chatResponse->getStatusCode());
        $callback = $chatResponse->getCallback();
        self::assertIsCallable($callback);
        $callback();
        $deliveryWorkspaceIds = $entityTypeManager->getStorage('workspace')->getQuery()
            ->condition('name', 'Delivery Ops')
            ->execute();
        self::assertNotEmpty($deliveryWorkspaceIds);

        $assistantMessageIds = $entityTypeManager->getStorage('chat_message')->getQuery()
            ->condition('role', 'assistant')
            ->execute();
        $assistantMessage = $entityTypeManager->getStorage('chat_message')->load(reset($assistantMessageIds));
        self::assertInstanceOf(ChatMessage::class, $assistantMessage);
        self::assertSame('Created the Claudriel workspace "Delivery Ops". Refresh the sidebar if it is not visible yet.', $assistantMessage->get('content'));
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
        $entityTypeManager = new EntityTypeManager($dispatcher, function ($definition) use ($db, $dispatcher): SqlEntityStorage {
            (new SqlSchemaHandler($definition, $db))->ensureTable();

            return new SqlEntityStorage($definition, $db, $dispatcher);
        });

        foreach ($this->entityTypes() as $type) {
            $entityTypeManager->registerEntityType($type);
        }

        return $entityTypeManager;
    }

    /**
     * @return list<EntityType>
     */
    private function entityTypes(): array
    {
        return [
            new EntityType(id: 'account', label: 'Account', class: Account::class, keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'account_verification_token', label: 'Account Verification Token', class: AccountVerificationToken::class, keys: ['id' => 'avtid', 'uuid' => 'uuid']),
            new EntityType(id: 'account_password_reset_token', label: 'Account Password Reset Token', class: AccountPasswordResetToken::class, keys: ['id' => 'aprtid', 'uuid' => 'uuid']),
            new EntityType(id: 'tenant', label: 'Tenant', class: Tenant::class, keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'skill', label: 'Skill', class: Skill::class, keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'chat_session', label: 'Chat Session', class: ChatSession::class, keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'chat_message', label: 'Chat Message', class: ChatMessage::class, keys: ['id' => 'cmid', 'uuid' => 'uuid']),
            new EntityType(id: 'schedule_entry', label: 'Schedule Entry', class: ScheduleEntry::class, keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'temporal_notification', label: 'Temporal Notification', class: TemporalNotification::class, keys: ['id' => 'tnid', 'uuid' => 'uuid']),
            new EntityType(id: 'triage_entry', label: 'Triage Entry', class: TriageEntry::class, keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name']),
        ];
    }

    private function tenantStorage(EntityTypeManager $entityTypeManager): SqlEntityStorage
    {
        /** @var SqlEntityStorage $storage */
        $storage = $entityTypeManager->getStorage('tenant');

        return $storage;
    }

    private function firstTenant(EntityTypeManager $entityTypeManager): ?Tenant
    {
        $tenants = $this->tenantStorage($entityTypeManager)->loadMultiple(
            $this->tenantStorage($entityTypeManager)->getQuery()->execute(),
        );
        $tenant = array_values($tenants)[0] ?? null;

        return $tenant instanceof Tenant ? $tenant : null;
    }
}

final class PublicLifecycleMailTransport implements MailTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public function send(array $message): array
    {
        $this->messages[] = $message;

        return ['transport' => 'memory', 'status' => 'queued'];
    }
}
