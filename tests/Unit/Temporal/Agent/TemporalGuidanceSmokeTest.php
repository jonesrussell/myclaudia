<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Temporal\Agent;

use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Controller\TemporalNotificationApiController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\TemporalNotification;
use Claudriel\Entity\Workspace;
use Claudriel\Support\StorageRepositoryAdapter;
use Claudriel\Temporal\Agent\OverrunAlertAgent;
use Claudriel\Temporal\Agent\ShiftRiskAgent;
use Claudriel\Temporal\Agent\TemporalAgentContext;
use Claudriel\Temporal\Agent\TemporalAgentContextBuilder;
use Claudriel\Temporal\Agent\TemporalAgentOrchestrator;
use Claudriel\Temporal\Agent\TemporalAgentRegistry;
use Claudriel\Temporal\Agent\TemporalGuidanceAssembler;
use Claudriel\Temporal\Agent\TemporalNotificationDeliveryService;
use Claudriel\Temporal\Agent\UpcomingBlockPrepAgent;
use Claudriel\Temporal\Agent\WrapUpPromptAgent;
use Claudriel\Temporal\AtomicTimeService;
use Claudriel\Temporal\Clock\SystemWallClock;
use Claudriel\Temporal\ClockHealthMonitor;
use Claudriel\Temporal\SystemClockSyncProbe;
use Claudriel\Temporal\TimeSnapshot;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class TemporalGuidanceSmokeTest extends TestCase
{
    public function test_prep_guidance_flows_from_delivery_to_snooze_and_dismiss(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $this->seedWorkspace($entityTypeManager);
        $assembler = new TemporalGuidanceAssembler($entityTypeManager);
        $controller = new TemporalNotificationApiController($entityTypeManager);
        $snapshot = $this->snapshot('2026-03-13T10:30:00+00:00');

        $guidance = $assembler->build(
            'tenant-123',
            'workspace-a',
            $this->prepBrief($snapshot),
            $snapshot,
        );

        self::assertCount(1, $guidance['notifications']);
        self::assertSame('upcoming-block-prep', $guidance['notifications'][0]['agent_name']);
        self::assertNotNull($guidance['ambient_nudge']);

        $notificationUuid = $guidance['notifications'][0]['uuid'];

        $snoozeResponse = $controller->snooze(
            params: ['uuid' => $notificationUuid],
            httpRequest: Request::create(
                '/api/temporal-notifications/'.$notificationUuid.'/snooze',
                'POST',
                server: ['HTTP_X_TENANT_ID' => 'tenant-123'],
                content: json_encode([
                    'tenant_id' => 'tenant-123',
                    'workspace_uuid' => 'workspace-a',
                    'minutes' => 15,
                ], JSON_THROW_ON_ERROR),
            ),
        );
        self::assertSame(200, $snoozeResponse->statusCode);
        self::assertSame('snoozed', json_decode($snoozeResponse->content, true, 512, JSON_THROW_ON_ERROR)['notification']['state']);

        $afterSnooze = $assembler->build('tenant-123', 'workspace-a', $this->prepBrief($snapshot), $snapshot);
        self::assertSame([], $afterSnooze['notifications']);
        self::assertNull($afterSnooze['ambient_nudge']);

        $dismissResponse = $controller->dismiss(
            params: ['uuid' => $notificationUuid],
            httpRequest: Request::create(
                '/api/temporal-notifications/'.$notificationUuid.'/dismiss',
                'POST',
                server: ['HTTP_X_TENANT_ID' => 'tenant-123'],
                content: json_encode([
                    'tenant_id' => 'tenant-123',
                    'workspace_uuid' => 'workspace-a',
                ], JSON_THROW_ON_ERROR),
            ),
        );
        self::assertSame(200, $dismissResponse->statusCode);
        self::assertSame('dismissed', json_decode($dismissResponse->content, true, 512, JSON_THROW_ON_ERROR)['notification']['state']);

        $afterDismiss = $assembler->build('tenant-123', 'workspace-a', $this->prepBrief($snapshot), $snapshot);
        self::assertSame([], $afterDismiss['notifications']);
    }

    public function test_overrun_and_wrap_up_paths_remain_deterministic_under_fixed_time_snapshots(): void
    {
        $overrunBatch = (new TemporalAgentOrchestrator(new TemporalAgentRegistry([
            new OverrunAlertAgent(minimumOverrunMinutes: 5),
            new ShiftRiskAgent(minimumOverrunMinutes: 5, riskLeadWindowMinutes: 15),
        ])))->evaluate($this->context(
            nowLocal: '2026-03-13T11:15:00+00:00',
            nextBlockStart: '2026-03-13T11:20:00+00:00',
            overrunMinutes: 15,
        ));

        self::assertSame('overrun-alert', $overrunBatch->toArray()['decisions'][0]['agent']);
        self::assertSame('emitted', $overrunBatch->toArray()['decisions'][0]['state']);
        self::assertSame('shift-risk', $overrunBatch->toArray()['decisions'][1]['agent']);
        self::assertSame('emitted', $overrunBatch->toArray()['decisions'][1]['state']);

        $wrapUpBatch = (new TemporalAgentOrchestrator(new TemporalAgentRegistry([
            new WrapUpPromptAgent(postBlockWindowMinutes: 10, shiftRiskLeadWindowMinutes: 15),
            new UpcomingBlockPrepAgent(leadWindowMinutes: 30),
        ])))->evaluate($this->context(
            nowLocal: '2026-03-13T11:10:00+00:00',
            nextBlockStart: '2026-03-13T11:40:00+00:00',
            overrunMinutes: 5,
        ));

        self::assertSame('wrap-up-prompt', $wrapUpBatch->toArray()['decisions'][0]['agent']);
        self::assertSame('emitted', $wrapUpBatch->toArray()['decisions'][0]['state']);
        self::assertSame('post_block_wrap_up_window', $wrapUpBatch->toArray()['decisions'][0]['reason_code']);
        self::assertSame('upcoming-block-prep', $wrapUpBatch->toArray()['decisions'][1]['agent']);
        self::assertSame('emitted', $wrapUpBatch->toArray()['decisions'][1]['state']);
    }

    public function test_real_prep_notification_action_state_moves_through_working_and_complete(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $this->seedWorkspace($entityTypeManager);
        $assembler = new TemporalGuidanceAssembler($entityTypeManager);
        $controller = new TemporalNotificationApiController($entityTypeManager);
        $snapshot = $this->snapshot('2026-03-13T10:30:00+00:00');

        $guidance = $assembler->build(
            'tenant-123',
            'workspace-a',
            $this->prepBrief($snapshot),
            $snapshot,
        );

        $notificationUuid = $guidance['notifications'][0]['uuid'];

        $workingResponse = $controller->updateAction(
            params: ['uuid' => $notificationUuid, 'action' => 'open_chat'],
            httpRequest: Request::create(
                '/api/temporal-notifications/'.$notificationUuid.'/actions/open_chat',
                'POST',
                server: ['HTTP_X_TENANT_ID' => 'tenant-123'],
                content: json_encode([
                    'tenant_id' => 'tenant-123',
                    'workspace_uuid' => 'workspace-a',
                    'state' => 'working',
                ], JSON_THROW_ON_ERROR),
            ),
        );
        self::assertSame(200, $workingResponse->statusCode);
        self::assertSame('working', json_decode($workingResponse->content, true, 512, JSON_THROW_ON_ERROR)['notification']['action_states']['open_chat']);

        $completeResponse = $controller->updateAction(
            params: ['uuid' => $notificationUuid, 'action' => 'open_chat'],
            httpRequest: Request::create(
                '/api/temporal-notifications/'.$notificationUuid.'/actions/open_chat',
                'POST',
                server: ['HTTP_X_TENANT_ID' => 'tenant-123'],
                content: json_encode([
                    'tenant_id' => 'tenant-123',
                    'workspace_uuid' => 'workspace-a',
                    'state' => 'complete',
                ], JSON_THROW_ON_ERROR),
            ),
        );
        self::assertSame(200, $completeResponse->statusCode);
        self::assertSame('complete', json_decode($completeResponse->content, true, 512, JSON_THROW_ON_ERROR)['notification']['action_states']['open_chat']);

        $stored = $entityTypeManager->getStorage('temporal_notification')->loadMultiple(
            $entityTypeManager->getStorage('temporal_notification')->getQuery()->condition('uuid', $notificationUuid)->execute(),
        );
        $notification = array_values($stored)[0] ?? null;

        self::assertInstanceOf(TemporalNotification::class, $notification);
        self::assertSame('complete', $notification->get('action_states')['open_chat']);
    }

    public function test_observability_json_includes_proactive_agent_trace_for_validation(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();
        $this->seedWorkspace($entityTypeManager);
        $snapshot = $this->snapshot('2026-03-13T10:30:00+00:00');
        $this->seedPrepNotification($entityTypeManager, $snapshot);

        $controller = new ObservabilityDashboardController(
            $entityTypeManager,
            null,
            '/home/fsd42/dev/claudriel',
            sys_get_temp_dir(),
            $snapshot->local(),
        );

        $response = $controller->jsonView();
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);
        $proactiveNode = $payload['call_chain']['root']['children'][7]['children'][4];

        self::assertSame('Proactive agent execution', $proactiveNode['title']);
        self::assertSame('upcoming-block-prep', $proactiveNode['children'][3]['title']);
        self::assertSame('Notification delivery', $proactiveNode['children'][3]['children'][1]['title']);
        self::assertSame('Action: open_chat', $proactiveNode['children'][3]['children'][1]['children'][0]['title']);
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $database = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;

        $entityTypeManager = new EntityTypeManager($dispatcher, function ($definition) use ($database, $dispatcher) {
            (new SqlSchemaHandler($definition, $database))->ensureTable();

            return new SqlEntityStorage($definition, $database, $dispatcher);
        });

        foreach ($this->entityTypes() as $entityType) {
            $entityTypeManager->registerEntityType($entityType);
        }

        return $entityTypeManager;
    }

    /**
     * @return list<EntityType>
     */
    private function entityTypes(): array
    {
        return [
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'commitment_extraction_log', label: 'Commitment Extraction Log', class: CommitmentExtractionLog::class, keys: ['id' => 'celid', 'uuid' => 'uuid']),
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'schedule_entry', label: 'Schedule Entry', class: ScheduleEntry::class, keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'temporal_notification', label: 'Temporal Notification', class: TemporalNotification::class, keys: ['id' => 'tnid', 'uuid' => 'uuid']),
        ];
    }

    private function seedWorkspace(EntityTypeManager $entityTypeManager): void
    {
        $entityTypeManager->getStorage('workspace')->save(new Workspace([
            'uuid' => 'workspace-a',
            'name' => 'Workspace A',
            'tenant_id' => 'tenant-123',
        ]));
    }

    private function seedPrepNotification(EntityTypeManager $entityTypeManager, TimeSnapshot $snapshot): void
    {
        $nextBlockStart = $snapshot->local()->modify('+20 minutes');
        $nextBlockEnd = $nextBlockStart->modify('+45 minutes');

        $entityTypeManager->getStorage('schedule_entry')->save(new ScheduleEntry([
            'uuid' => 'schedule-observability-prep',
            'tenant_id' => 'tenant-123',
            'title' => 'Planning',
            'starts_at' => $nextBlockStart->format(DateTimeInterface::ATOM),
            'ends_at' => $nextBlockEnd->format(DateTimeInterface::ATOM),
            'source' => 'manual',
        ]));

        $schedule = [[
            'title' => 'Planning',
            'start_time' => $nextBlockStart->format(DateTimeInterface::ATOM),
            'end_time' => $nextBlockEnd->format(DateTimeInterface::ATOM),
            'source' => 'manual',
        ]];

        $context = (new TemporalAgentContextBuilder)->build(
            tenantId: 'platform-observability',
            workspaceUuid: null,
            snapshot: $snapshot,
            clockHealth: $this->clockHealth(),
            schedule: $schedule,
            timezoneContext: [
                'timezone' => $snapshot->timezone(),
                'source' => 'time_snapshot',
            ],
        );

        $batch = (new TemporalAgentOrchestrator(new TemporalAgentRegistry([
            new OverrunAlertAgent,
            new ShiftRiskAgent,
            new WrapUpPromptAgent,
            new UpcomingBlockPrepAgent,
        ])))->evaluate($context);

        $repository = new StorageRepositoryAdapter($entityTypeManager->getStorage('temporal_notification'));
        $notification = (new TemporalNotificationDeliveryService($repository))
            ->deliver($batch)[0] ?? null;

        self::assertInstanceOf(TemporalNotification::class, $notification);
    }

    /**
     * @return array{
     *   schedule: list<array{title: string, start_time: string, end_time: string, source: string}>,
     *   schedule_timeline: list<array{title: string, start_time: string, end_time: string, source: string}>,
     *   schedule_summary: string,
     *   temporal_awareness: array{
     *     current_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *     next_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *     gaps: list<array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}>,
     *     overruns: list<array{title: string, ended_at: string, overrun_minutes: int}>
     *   }
     * }
     */
    private function prepBrief(TimeSnapshot $snapshot): array
    {
        $nextBlockStart = $snapshot->local()->modify('+20 minutes');
        $nextBlockEnd = $nextBlockStart->modify('+45 minutes');

        return [
            'schedule' => [[
                'title' => 'Planning',
                'start_time' => $nextBlockStart->format(DateTimeInterface::ATOM),
                'end_time' => $nextBlockEnd->format(DateTimeInterface::ATOM),
                'source' => 'manual',
            ]],
            'schedule_timeline' => [[
                'title' => 'Planning',
                'start_time' => $nextBlockStart->format(DateTimeInterface::ATOM),
                'end_time' => $nextBlockEnd->format(DateTimeInterface::ATOM),
                'source' => 'manual',
            ]],
            'schedule_summary' => 'Planning at '.$nextBlockStart->format('g:i A'),
            'temporal_awareness' => [
                'current_block' => null,
                'next_block' => [
                    'title' => 'Planning',
                    'start_time' => $nextBlockStart->format(DateTimeInterface::ATOM),
                    'end_time' => $nextBlockEnd->format(DateTimeInterface::ATOM),
                    'source' => 'manual',
                ],
                'gaps' => [],
                'overruns' => [],
            ],
        ];
    }

    private function snapshot(string $utcTimestamp): TimeSnapshot
    {
        $utc = new DateTimeImmutable($utcTimestamp, new DateTimeZone('+00:00'));

        return new TimeSnapshot(
            $utc,
            $utc,
            0,
            '+00:00',
        );
    }

    private function context(string $nowLocal, ?string $nextBlockStart, ?int $overrunMinutes = null): TemporalAgentContext
    {
        $local = new DateTimeImmutable($nowLocal, new DateTimeZone('UTC'));
        $overruns = [];

        if ($overrunMinutes !== null) {
            $overruns[] = [
                'title' => 'Design Review',
                'ended_at' => $local->modify(sprintf('-%d minutes', $overrunMinutes))->format(DateTimeInterface::ATOM),
                'overrun_minutes' => $overrunMinutes,
            ];
        }

        return new TemporalAgentContext(
            tenantId: 'tenant-123',
            workspaceUuid: 'workspace-a',
            timeSnapshot: new TimeSnapshot($local, $local, 0, 'UTC'),
            temporalAwareness: [
                'current_block' => null,
                'next_block' => $nextBlockStart !== null ? [
                    'title' => 'Client Call',
                    'start_time' => $nextBlockStart,
                    'end_time' => (new DateTimeImmutable($nextBlockStart))->modify('+45 minutes')->format(DateTimeInterface::ATOM),
                    'source' => 'manual',
                ] : null,
                'gaps' => [],
                'overruns' => $overruns,
            ],
            clockHealth: $this->clockHealth(),
            scheduleMetadata: [
                'schedule' => [],
                'schedule_summary' => '',
                'has_clear_day' => false,
            ],
            timezoneContext: [
                'timezone' => 'UTC',
                'source' => 'workspace',
            ],
        );
    }

    /**
     * @return array{
     *   provider: string,
     *   synchronized: bool,
     *   reference_source: string,
     *   drift_seconds: float,
     *   threshold_seconds: int,
     *   state: string,
     *   safe_for_temporal_reasoning: bool,
     *   retry_after_seconds: int,
     *   fallback_mode: string,
     *   metadata: array<string, scalar|null>
     * }
     */
    private function clockHealth(): array
    {
        return (new ClockHealthMonitor(
            new AtomicTimeService,
            new SystemClockSyncProbe,
            new SystemWallClock,
        ))->assess('system-wall-clock');
    }
}
