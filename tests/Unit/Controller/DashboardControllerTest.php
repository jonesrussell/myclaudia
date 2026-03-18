<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\DashboardController;
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

final class DashboardControllerTest extends TestCase
{
    public function test_show_returns_json_when_no_twig(): void
    {
        $etm = $this->buildEntityTypeManager();
        $this->seedWorkspace($etm, 'workspace-dashboard-1', 'Dashboard Workspace');
        $this->seedSession($etm, 'sess-a', 'Session A', '2026-03-13T10:00:00+00:00');
        $this->seedSession($etm, 'sess-b', 'Session B', '2026-03-13T11:00:00+00:00');
        $controller = new DashboardController($etm);

        $response = $controller->show(httpRequest: Request::create('/dashboard'));
        self::assertSame(200, $response->statusCode);

        $data = json_decode($response->content, true);
        self::assertArrayHasKey('brief', $data);
        self::assertArrayHasKey('sessions', $data);
        self::assertArrayHasKey('api_configured', $data);
        self::assertArrayHasKey('workspaces', $data);
        self::assertArrayHasKey('brief_fallback', $data);
        self::assertArrayHasKey('brief_fallback_url', $data);
        self::assertArrayHasKey('proactive_guidance', $data['brief']);
        self::assertSame('Dashboard Workspace', $data['workspaces'][0]['name']);
        self::assertSame('Dashboard Workspace', $data['brief_fallback']['workspaces'][0]['name']);
        self::assertCount(2, $data['sessions']);
        self::assertSame('Session B', $data['sessions'][0]['title']);
    }

    public function test_show_returns_all_sessions_not_just_ten(): void
    {
        $etm = $this->buildEntityTypeManager();

        for ($i = 1; $i <= 12; $i++) {
            $this->seedSession(
                $etm,
                sprintf('sess-%02d', $i),
                sprintf('Session %02d', $i),
                sprintf('2026-03-13T%02d:00:00+00:00', min($i, 23)),
            );
        }

        $controller = new DashboardController($etm);
        $response = $controller->show(httpRequest: Request::create('/dashboard'));
        $data = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(12, $data['sessions']);
        self::assertSame('Session 12', $data['sessions'][0]['title']);
        self::assertSame('Session 01', $data['sessions'][11]['title']);
    }

    public function test_show_renders_fallback_bootstrap_into_dashboard_html(): void
    {
        $etm = $this->buildEntityTypeManager();
        $this->seedWorkspace($etm, 'workspace-dashboard-2', 'Fallback Render Workspace');
        $this->seedSession($etm, 'sess-tabs-1', 'Daily Brief Follow-up', '2026-03-13T12:00:00+00:00');
        $this->seedSession($etm, 'sess-tabs-2', 'Calendar cleanup', '2026-03-13T13:00:00+00:00');

        $controller = new DashboardController(
            $etm,
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
        );

        $request = Request::create('/dashboard', 'GET', server: ['HTTP_X_REQUEST_ID' => 'dashboard-fallback-req']);
        $response = $controller->show(query: ['request_id' => 'dashboard-fallback-req'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Fallback Render Workspace', $response->content);
        self::assertStringContainsString('transport=fallback', $response->content);
        self::assertStringContainsString('dashboard-fallback-req', $response->content);
        self::assertStringContainsString('"briefs"', $response->content);
        self::assertStringContainsString('id="sessionTabs"', $response->content);
        self::assertStringContainsString('data-session-tab="sess-tabs-2"', $response->content);
        self::assertStringContainsString('chat-progress', $response->content);
        self::assertStringContainsString('schedule-timeline', $response->content);
        self::assertStringContainsString('timeline-event-action', $response->content);
        self::assertStringContainsString('timeline-event-action-status', $response->content);
        self::assertStringContainsString('guidance-panel', $response->content);
        self::assertStringContainsString('ambient-nudge', $response->content);
        self::assertStringContainsString('Queued in chat', $response->content);
    }

    public function test_show_renders_live_guidance_card_and_ambient_nudge_from_schedule(): void
    {
        $etm = $this->buildEntityTypeManager();
        $this->seedWorkspace($etm, 'workspace-dashboard-3', 'Guidance Workspace');
        $this->seedUpcomingScheduleEntry($etm, 'Planning');

        $controller = new DashboardController(
            $etm,
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
        );

        $response = $controller->show(
            query: ['request_id' => 'dashboard-guidance-req'],
            httpRequest: Request::create('/dashboard', 'GET', server: ['HTTP_X_REQUEST_ID' => 'dashboard-guidance-req']),
        );

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Prepare for next block', $response->content);
        self::assertStringContainsString('Planning', $response->content);
        self::assertStringContainsString('Snooze 15m', $response->content);
        self::assertStringContainsString('Dismiss', $response->content);
        self::assertStringContainsString('Ambient Nudge', $response->content);
        self::assertStringContainsString('Prep in chat', $response->content);
        self::assertStringContainsString('data-agent-name="upcoming-block-prep"', $response->content);
        self::assertStringContainsString('data-guidance-action="open_chat"', $response->content);
        self::assertStringContainsString('data-guidance-snooze="', $response->content);
        self::assertStringContainsString('data-guidance-dismiss="', $response->content);
    }

    public function test_show_renders_persisted_guidance_action_state_in_html(): void
    {
        $etm = $this->buildEntityTypeManager();
        $this->seedWorkspace($etm, 'workspace-dashboard-4', 'Guidance State Workspace');
        $this->seedUpcomingScheduleEntry($etm, 'Planning');

        $controller = new DashboardController(
            $etm,
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
        );

        $request = Request::create('/dashboard', 'GET', server: ['HTTP_X_REQUEST_ID' => 'dashboard-guidance-state-req']);
        $firstResponse = $controller->show(query: ['request_id' => 'dashboard-guidance-state-req'], httpRequest: $request);

        self::assertSame(200, $firstResponse->statusCode);

        $notificationStorage = $etm->getStorage('temporal_notification');
        $notifications = $notificationStorage->loadMultiple($notificationStorage->getQuery()->execute());
        $notification = array_values($notifications)[0] ?? null;

        self::assertInstanceOf(TemporalNotification::class, $notification);
        $notification->set('action_states', ['open_chat' => 'complete']);
        $notificationStorage->save($notification);

        $secondResponse = $controller->show(query: ['request_id' => 'dashboard-guidance-state-req'], httpRequest: $request);

        self::assertSame(200, $secondResponse->statusCode);
        self::assertStringContainsString('data-guidance-status="complete"', $secondResponse->content);
        self::assertStringContainsString('data-state="complete"', $secondResponse->content);
        self::assertStringContainsString('>Complete</div>', $secondResponse->content);
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

    private function seedWorkspace(EntityTypeManager $etm, string $uuid, string $name): void
    {
        $etm->getStorage('workspace')->save(new Workspace([
            'uuid' => $uuid,
            'name' => $name,
            'description' => 'Shows up in dashboard payload',
        ]));
    }

    private function seedSession(EntityTypeManager $etm, string $uuid, string $title, string $createdAt): void
    {
        $etm->getStorage('chat_session')->save(new ChatSession([
            'uuid' => $uuid,
            'title' => $title,
            'created_at' => $createdAt,
        ]));
    }

    private function seedUpcomingScheduleEntry(EntityTypeManager $etm, string $title): void
    {
        $start = new \DateTimeImmutable('+20 minutes');
        $end = $start->modify('+45 minutes');

        $etm->getStorage('schedule_entry')->save(new ScheduleEntry([
            'uuid' => 'schedule-upcoming-'.md5($title),
            'title' => $title,
            'starts_at' => $start->format(\DateTimeInterface::ATOM),
            'ends_at' => $end->format(\DateTimeInterface::ATOM),
            'source' => 'manual',
        ]));
    }

    /** @return list<EntityType> */
    private function entityTypes(): array
    {
        return [
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
}
