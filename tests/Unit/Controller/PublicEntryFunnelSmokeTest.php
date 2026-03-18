<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\AppShellController;
use Claudriel\Controller\PublicHomepageController;
use Claudriel\Controller\PublicSessionController;
use Claudriel\Entity\Account;
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

final class PublicEntryFunnelSmokeTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetSession();
    }

    protected function tearDown(): void
    {
        $this->resetSession();
    }

    public function test_public_homepage_and_app_entry_flow_stays_deterministic(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $verifiedAccount = $this->seedVerifiedAccount($entityTypeManager);

        $homepage = new PublicHomepageController(
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
        );
        $appShell = new AppShellController(
            $entityTypeManager,
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
        );
        $sessionController = new PublicSessionController(
            $entityTypeManager,
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
        );

        $anonymousHomepage = $homepage->show();
        self::assertSame(200, $anonymousHomepage->statusCode);
        self::assertStringContainsString('Create your account', $anonymousHomepage->content);
        self::assertStringContainsString('href="/signup"', $anonymousHomepage->content);
        self::assertStringContainsString('href="/login"', $anonymousHomepage->content);

        $anonymousApp = $appShell->show();
        self::assertInstanceOf(RedirectResponse::class, $anonymousApp);
        self::assertSame('/login', $anonymousApp->getTargetUrl());

        $login = $sessionController->login(httpRequest: Request::create('/login', 'POST', [
            'email' => 'smoke-entry@example.com',
            'password' => 'correct horse battery staple',
        ]));
        self::assertInstanceOf(RedirectResponse::class, $login);
        self::assertSame('/app?login=1&tenant_id=tenant-entry&workspace_uuid=workspace-entry', $login->getTargetUrl());

        $authenticatedHomepage = $homepage->show(account: new AuthenticatedAccount($verifiedAccount));
        self::assertInstanceOf(RedirectResponse::class, $authenticatedHomepage);
        self::assertSame('/app?tenant_id=tenant-entry', $authenticatedHomepage->getTargetUrl());

        $authenticatedApp = $appShell->show(account: new AuthenticatedAccount($verifiedAccount), httpRequest: Request::create('/app'));
        self::assertSame(200, $authenticatedApp->statusCode);
        self::assertStringContainsString('Entry Workspace', $authenticatedApp->content);
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
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'mc_event',
            label: 'Event',
            class: McEvent::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'person',
            label: 'Person',
            class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'skill',
            label: 'Skill',
            class: Skill::class,
            keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'chat_session',
            label: 'Chat Session',
            class: ChatSession::class,
            keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'chat_message',
            label: 'Chat Message',
            class: ChatMessage::class,
            keys: ['id' => 'cmid', 'uuid' => 'uuid'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'schedule_entry',
            label: 'Schedule Entry',
            class: ScheduleEntry::class,
            keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'temporal_notification',
            label: 'Temporal Notification',
            class: TemporalNotification::class,
            keys: ['id' => 'tnid', 'uuid' => 'uuid'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'triage_entry',
            label: 'Triage Entry',
            class: TriageEntry::class,
            keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        return $entityTypeManager;
    }

    private function seedVerifiedAccount(EntityTypeManager $entityTypeManager): Account
    {
        $entityTypeManager->getStorage('tenant')->save(new Tenant([
            'uuid' => 'tenant-entry',
            'name' => 'Entry Tenant',
            'metadata' => ['default_workspace_uuid' => 'workspace-entry'],
        ]));
        $entityTypeManager->getStorage('workspace')->save(new Workspace([
            'uuid' => 'workspace-entry',
            'name' => 'Entry Workspace',
            'description' => 'Smoke coverage workspace',
            'tenant_id' => 'tenant-entry',
        ]));

        $account = new Account([
            'name' => 'Entry User',
            'email' => 'smoke-entry@example.com',
            'password_hash' => password_hash('correct horse battery staple', PASSWORD_DEFAULT),
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
            'tenant_id' => 'tenant-entry',
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
        session_id('claudriel-public-entry-smoke-'.bin2hex(random_bytes(4)));
        session_start();
    }
}
