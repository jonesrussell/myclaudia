<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\BriefStreamController;
use Claudriel\Entity\Account;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\Skill;
use Claudriel\Entity\TemporalNotification;
use Claudriel\Entity\TriageEntry;
use Claudriel\Entity\Workspace;
use Claudriel\Support\BriefSignal;
use Claudriel\Temporal\AtomicTimeService;
use Claudriel\Temporal\Clock\WallClockInterface;
use Claudriel\Temporal\TemporalContextFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\SSR\SsrResponse;

final class BriefStreamControllerTest extends TestCase
{
    public function test_stream_emits_brief_data_on_signal_change(): void
    {
        $signalFile = sys_get_temp_dir().'/brief_signal_stream_'.uniqid('', true).'.txt';
        $signal = new BriefSignal($signalFile);
        $signal->touch();

        $etm = $this->buildEntityTypeManager();
        $this->seedWorkspace($etm, 'workspace-stream-1', 'Stream Workspace');

        $controller = new BriefStreamController($etm);

        $output = [];
        $iterations = 0;

        $controller->streamLoop(
            $signalFile,
            outputCallback: function (string $data) use (&$output): void {
                $output[] = $data;
            },
            flushCallback: function (): void {},
            shouldStop: function () use (&$iterations): bool {
                $iterations++;

                return $iterations > 1;
            },
            sleepCallback: function (): void {},
        );

        $combined = implode('', $output);
        self::assertStringContainsString('retry:', $combined);
        self::assertStringContainsString('event: brief-update', $combined);
        self::assertStringContainsString('"schedule"', $combined);
        self::assertStringContainsString('"workspaces"', $combined);
        self::assertStringContainsString('"Stream Workspace"', $combined);

        // Cleanup
        if (file_exists($signalFile)) {
            unlink($signalFile);
        }
    }

    public function test_stream_returns_fallback_json_payload_when_requested(): void
    {
        $etm = $this->buildEntityTypeManager();
        $this->seedWorkspace($etm, 'workspace-fallback-1', 'Fallback Workspace', 'user-42');

        $controller = new BriefStreamController($etm);
        $request = Request::create('/stream/brief', 'GET', server: ['HTTP_X_REQUEST_ID' => 'req-fallback-1']);

        $account = new AuthenticatedAccount(new Account([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'status' => 'active',
            'tenant_id' => 'user-42',
        ]));
        $response = $controller->stream(
            query: ['transport' => 'fallback'],
            account: $account,
            httpRequest: $request,
        );

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);

        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('workspaces', $payload);
        self::assertArrayHasKey('briefs', $payload);
        self::assertArrayHasKey('updated_at', $payload);
        self::assertArrayHasKey('time_snapshot', $payload['briefs']);
        self::assertArrayHasKey('proactive_guidance', $payload['briefs']);
        self::assertSame('Fallback Workspace', $payload['workspaces'][0]['name']);
        self::assertSame('Fallback Workspace', $payload['briefs']['workspaces'][0]['name']);
    }

    public function test_fallback_payload_includes_live_proactive_guidance_when_schedule_has_upcoming_block(): void
    {
        $fixedNow = new \DateTimeImmutable('10:00:00', new \DateTimeZone('UTC'));
        $etm = $this->buildEntityTypeManager();
        $this->seedWorkspace($etm, 'workspace-fallback-2', 'Guidance Workspace', 'user-77');
        $this->seedUpcomingScheduleEntry($etm, 'Planning', $fixedNow);

        $fixedClock = new class implements WallClockInterface
        {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('10:00:00', new \DateTimeZone('UTC'));
            }
        };
        $factory = new TemporalContextFactory($etm, new AtomicTimeService($fixedClock));
        $controller = new BriefStreamController($etm, $factory);
        $account = new AuthenticatedAccount(new Account([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'status' => 'active',
            'tenant_id' => 'user-77',
        ]));
        $response = $controller->stream(
            query: ['transport' => 'fallback'],
            account: $account,
            httpRequest: Request::create('/stream/brief', 'GET', server: ['HTTP_X_REQUEST_ID' => 'req-fallback-guidance']),
        );

        self::assertSame(200, $response->statusCode);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $payload['briefs']['proactive_guidance']['notifications']);
        self::assertSame('upcoming-block-prep', $payload['briefs']['proactive_guidance']['notifications'][0]['agent_name']);
        self::assertNotNull($payload['briefs']['proactive_guidance']['ambient_nudge']);
        self::assertSame('Prepare for next block', $payload['briefs']['proactive_guidance']['notifications'][0]['title']);
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = DBALDatabase::createSqlite(':memory:');
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

    private function seedWorkspace(EntityTypeManager $etm, string $uuid, string $name, string $tenantId = 'default'): void
    {
        $etm->getStorage('workspace')->save(new Workspace([
            'uuid' => $uuid,
            'name' => $name,
            'description' => 'Workspace used in controller coverage',
            'tenant_id' => $tenantId,
        ]));
    }

    private function seedUpcomingScheduleEntry(EntityTypeManager $etm, string $title, ?\DateTimeImmutable $referenceNow = null): void
    {
        $start = ($referenceNow ?? new \DateTimeImmutable)->modify('+20 minutes');
        $end = $start->modify('+45 minutes');

        $etm->getStorage('schedule_entry')->save(new ScheduleEntry([
            'uuid' => 'brief-stream-upcoming-'.md5($title),
            'tenant_id' => 'user-77',
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
            new EntityType(id: 'schedule_entry', label: 'Schedule Entry', class: ScheduleEntry::class, keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'temporal_notification', label: 'Temporal Notification', class: TemporalNotification::class, keys: ['id' => 'tnid', 'uuid' => 'uuid']),
            new EntityType(id: 'triage_entry', label: 'Triage Entry', class: TriageEntry::class, keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name']),
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
        ];
    }
}
