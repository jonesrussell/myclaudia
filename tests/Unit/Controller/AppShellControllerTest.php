<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\AppShellController;
use Claudriel\Entity\Account;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\Skill;
use Claudriel\Entity\TemporalNotification;
use Claudriel\Entity\TriageEntry;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\SSR\SsrResponse;

final class AppShellControllerTest extends TestCase
{
    protected function setUp(): void
    {
        $this->resetSession();
    }

    protected function tearDown(): void
    {
        $this->resetSession();
    }

    public function test_show_redirects_anonymous_requests_to_login(): void
    {
        $response = $this->controller()->show();

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/login', $response->headers->get('Location'));
    }

    public function test_show_renders_dashboard_for_authenticated_account(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $entityTypeManager->getStorage('workspace')->save(new Workspace([
            'uuid' => 'workspace-app-shell',
            'name' => 'App Workspace',
            'description' => 'Authenticated shell coverage',
            'tenant_id' => 'tenant-123',
        ]));

        $account = new Account([
            'name' => 'Ready User',
            'email' => 'ready@example.com',
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
            'tenant_id' => 'tenant-123',
        ]);

        $response = $this->controller($entityTypeManager)->show(account: new AuthenticatedAccount($account), httpRequest: Request::create('/app'));

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('App Workspace', $response->content);
        self::assertStringContainsString('guidance-panel', $response->content);
    }

    public function test_show_renders_dashboard_for_authenticated_session(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $entityTypeManager->getStorage('workspace')->save(new Workspace([
            'uuid' => 'workspace-session-shell',
            'name' => 'Session Workspace',
            'description' => 'Session-authenticated shell coverage',
            'tenant_id' => 'tenant-session',
        ]));

        $account = new Account([
            'name' => 'Session User',
            'email' => 'session@example.com',
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
            'tenant_id' => 'tenant-session',
        ]);
        $entityTypeManager->getStorage('account')->save($account);
        $_SESSION['claudriel_account_uuid'] = $account->get('uuid');

        $response = $this->controller($entityTypeManager)->show(query: [
            'tenant_id' => 'tenant-session',
            'workspace_uuid' => 'workspace-session-shell',
        ], httpRequest: Request::create('/app'));

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Session Workspace', $response->content);
    }

    private function controller(?EntityTypeManager $entityTypeManager = null): AppShellController
    {
        return new AppShellController(
            $entityTypeManager ?? $this->buildEntityTypeManager(),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
        );
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
        $etm = new EntityTypeManager($dispatcher, function ($def) use ($db, $dispatcher) {
            (new SqlSchemaHandler($def, $db))->ensureTable();

            return new SqlEntityStorage($def, $db, $dispatcher);
        });
        foreach ($this->entityTypes() as $type) {
            $etm->registerEntityType($type);
        }

        return $etm;
    }

    /** @return list<EntityType> */
    private function entityTypes(): array
    {
        return [
            new EntityType(id: 'account', label: 'Account', class: Account::class, keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'skill', label: 'Skill', class: Skill::class, keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'chat_session', label: 'Chat Session', class: ChatSession::class, keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'chat_message', label: 'Chat Message', class: ChatMessage::class, keys: ['id' => 'cmid', 'uuid' => 'uuid']),
            new EntityType(id: 'schedule_entry', label: 'Schedule Entry', class: ScheduleEntry::class, keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'temporal_notification', label: 'Temporal Notification', class: TemporalNotification::class, keys: ['id' => 'tnid', 'uuid' => 'uuid']),
            new EntityType(id: 'triage_entry', label: 'Triage Entry', class: TriageEntry::class, keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name']),
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
        ];
    }

    private function resetSession(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        $_SESSION = [];
        session_id('claudriel-app-shell-'.bin2hex(random_bytes(4)));
        session_start();
    }
}
