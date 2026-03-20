<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Platform;

use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\TemporalNotification;
use Claudriel\Support\StorageRepositoryAdapter;
use Claudriel\Temporal\Agent\TemporalAgentContextBuilder;
use Claudriel\Temporal\Agent\TemporalAgentOrchestrator;
use Claudriel\Temporal\Agent\TemporalAgentRegistry;
use Claudriel\Temporal\Agent\TemporalNotificationDeliveryService;
use Claudriel\Temporal\Agent\UpcomingBlockPrepAgent;
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
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class ObservabilityDashboardViewTest extends TestCase
{
    private ?string $batchDir = null;

    private DateTimeImmutable $referenceDate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->referenceDate = new DateTimeImmutable('2026-03-13T10:30:00+00:00', new DateTimeZone('UTC'));
    }

    protected function tearDown(): void
    {
        if ($this->batchDir !== null && is_dir($this->batchDir)) {
            $files = glob($this->batchDir.'/*') ?: [];
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->batchDir);
        }

        parent::tearDown();
    }

    public function test_html_view_renders_all_observability_sections(): void
    {
        $controller = new ObservabilityDashboardController(
            $this->buildSeededEntityTypeManager(),
            new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates')),
            '/home/fsd42/dev/claudriel',
            $this->buildBatchDirectory(),
            $this->referenceDate,
        );

        $response = $controller->index(query: ['days' => 14]);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Platform Observability Dashboard', $response->content);
        self::assertStringContainsString('Observability Call Chain', $response->content);
        self::assertStringContainsString('call-chain-node', $response->content);
        self::assertStringContainsString('call-chain-status success', $response->content);
        self::assertStringContainsString('call-chain-status fallback', $response->content);
        self::assertStringContainsString('call-chain-status retry', $response->content);
        self::assertStringContainsString('call-chain-status error', $response->content);
        self::assertStringContainsString('Temporal + tooling execution', $response->content);
        self::assertStringContainsString('Atomic time snapshot', $response->content);
        self::assertStringContainsString('Tooling transport signals', $response->content);
        self::assertStringContainsString('Proactive agent execution', $response->content);
        self::assertStringContainsString('upcoming-block-prep', $response->content);
        self::assertStringContainsString('Notification delivery', $response->content);
        self::assertStringContainsString('Action: open_chat', $response->content);
        self::assertStringContainsString('Extraction Health', $response->content);
        self::assertStringContainsString('Drift Overview', $response->content);
        self::assertStringContainsString('Self-Assessment', $response->content);
        self::assertStringContainsString('Improvement Suggestions', $response->content);
        self::assertStringContainsString('Training Export Readiness', $response->content);
        self::assertStringContainsString('Governance Integrity', $response->content);
        self::assertStringContainsString('Model Update Batches', $response->content);
        self::assertStringContainsString('System Summary', $response->content);
        self::assertStringContainsString('batch-platform-view-001', $response->content);
    }

    public function test_json_view_returns_key_metrics(): void
    {
        $controller = new ObservabilityDashboardController(
            $this->buildSeededEntityTypeManager(),
            null,
            '/home/fsd42/dev/claudriel',
            $this->buildBatchDirectory(),
            $this->referenceDate,
        );

        $response = $controller->jsonView(query: ['days' => 14]);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertArrayHasKey('average_confidence', $payload['extraction_health']);
        self::assertArrayHasKey('classification', $payload['drift_overview']);
        self::assertArrayHasKey('overall_score', $payload['self_assessment']);
        self::assertArrayHasKey('daily_sample_count', $payload['training_export_readiness']);
        self::assertArrayHasKey('summary', $payload['governance_integrity']);
        self::assertArrayHasKey('temporal_tooling', $payload);
        self::assertArrayHasKey('call_chain', $payload);
        self::assertSame('Platform observability scan', $payload['call_chain']['root']['title']);
        self::assertSame('Atomic time snapshot', $payload['call_chain']['root']['children'][7]['children'][0]['title']);
        self::assertSame('Proactive agent execution', $payload['call_chain']['root']['children'][7]['children'][4]['title']);
        self::assertSame('upcoming-block-prep', $payload['call_chain']['root']['children'][7]['children'][4]['children'][3]['title']);
        self::assertSame('Action: open_chat', $payload['call_chain']['root']['children'][7]['children'][4]['children'][3]['children'][1]['children'][0]['title']);
        self::assertSame('batch-platform-view-001', $payload['model_update_batches'][0]['batch_id']);
    }

    private function buildBatchDirectory(): string
    {
        if ($this->batchDir !== null) {
            return $this->batchDir;
        }

        $this->batchDir = sys_get_temp_dir().'/claudriel-platform-observability-'.bin2hex(random_bytes(6));
        mkdir($this->batchDir, 0755, true);
        file_put_contents($this->batchDir.'/batch-platform-view-001.json', json_encode([
            'batch_id' => 'batch-platform-view-001',
            'metadata' => [
                'generated_at' => '2026-03-13T12:00:00+00:00',
                'window_days' => 14,
                'total_samples' => 4,
                'failure_rate' => 0.5,
                'drift_classification' => 'moderate',
            ],
        ], JSON_THROW_ON_ERROR));

        return $this->batchDir;
    }

    private function buildSeededEntityTypeManager(): EntityTypeManager
    {
        $today = new DateTimeImmutable('today');
        $previousWindow = $today->sub(new \DateInterval('P18D'))->format('Y-m-d');
        $currentWindowOne = $today->sub(new \DateInterval('P2D'))->format('Y-m-d');
        $currentWindowTwo = $today->sub(new \DateInterval('P1D'))->format('Y-m-d');
        $currentWindowThree = $today->format('Y-m-d');

        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;

        $entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($db, $dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $db))->ensureTable();

                return new SqlEntityStorage($definition, $db, $dispatcher);
            },
        );

        foreach ($this->entityTypes() as $entityType) {
            $entityTypeManager->registerEntityType($entityType);
        }

        $eventStorage = $entityTypeManager->getStorage('mc_event');
        $previousAlpha = $this->saveEvent($eventStorage, 'alpha@example.com', $previousWindow.' 09:00:00', 'platform-prev-alpha');
        $currentAlpha = $this->saveEvent($eventStorage, 'alpha@example.com', $currentWindowOne.' 09:00:00', 'platform-current-alpha');
        $currentBeta = $this->saveEvent($eventStorage, 'beta@example.com', $currentWindowTwo.' 10:00:00', 'platform-current-beta');
        $currentGamma = $this->saveEvent($eventStorage, 'gamma@example.com', $currentWindowThree.' 11:00:00', 'platform-current-gamma');

        $commitmentStorage = $entityTypeManager->getStorage('commitment');
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous alpha commitment',
            'confidence' => 0.92,
            'source_event_id' => $previousAlpha->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Current alpha commitment',
            'confidence' => 0.56,
            'source_event_id' => $currentAlpha->id(),
        ]));

        $logStorage = $entityTypeManager->getStorage('commitment_extraction_log');
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentBeta->id(),
            'raw_event_payload' => '{"from_email":"beta@example.com","subject":"Beta context"}',
            'extracted_commitment_payload' => '{"title":"Need beta details","confidence":0.33}',
            'confidence' => 0.33,
            'failure_category' => 'insufficient_context',
            'created_at' => $currentWindowTwo.' 12:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentGamma->id(),
            'raw_event_payload' => '{"from_email":"gamma@example.com","subject":"Gamma maybe"}',
            'extracted_commitment_payload' => '{"title":"Maybe gamma","confidence":0.24}',
            'confidence' => 0.24,
            'failure_category' => 'ambiguous',
            'created_at' => $currentWindowThree.' 12:30:00',
        ]));

        $this->seedTemporalObservabilityData($entityTypeManager);

        return $entityTypeManager;
    }

    /**
     * @param  mixed  $storage
     */
    private function saveEvent($storage, string $sender, string $occurred, string $hash): McEvent
    {
        $event = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => sprintf('{"from_email":"%s","subject":"%s"}', $sender, $hash),
            'occurred' => $occurred,
            'content_hash' => $hash,
        ]);
        $storage->save($event);

        return $event;
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
            new EntityType(id: 'schedule_entry', label: 'Schedule Entry', class: ScheduleEntry::class, keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'temporal_notification', label: 'Temporal Notification', class: TemporalNotification::class, keys: ['id' => 'tnid', 'uuid' => 'uuid']),
        ];
    }

    private function seedTemporalObservabilityData(EntityTypeManager $entityTypeManager): void
    {
        $snapshot = new TimeSnapshot(
            $this->referenceDate->setTimezone(new DateTimeZone('UTC')),
            $this->referenceDate,
            0,
            $this->referenceDate->getTimezone()->getName(),
        );
        $nextBlockStart = $snapshot->local()->modify('+20 minutes');
        $nextBlockEnd = $nextBlockStart->modify('+45 minutes');

        $entityTypeManager->getStorage('schedule_entry')->save(new ScheduleEntry([
            'uuid' => 'schedule-platform-observability-001',
            'tenant_id' => 'tenant-123',
            'title' => 'Planning',
            'starts_at' => $nextBlockStart->format(DateTimeInterface::ATOM),
            'ends_at' => $nextBlockEnd->format(DateTimeInterface::ATOM),
            'source' => 'manual',
        ]));

        $context = (new TemporalAgentContextBuilder)->build(
            tenantId: 'platform-observability',
            workspaceUuid: null,
            snapshot: $snapshot,
            clockHealth: (new ClockHealthMonitor(
                new AtomicTimeService,
                new SystemClockSyncProbe,
                new SystemWallClock,
            ))->assess('system-wall-clock'),
            schedule: [[
                'title' => 'Planning',
                'start_time' => $nextBlockStart->format(DateTimeInterface::ATOM),
                'end_time' => $nextBlockEnd->format(DateTimeInterface::ATOM),
                'source' => 'manual',
            ]],
            timezoneContext: [
                'timezone' => $snapshot->timezone(),
                'source' => 'time_snapshot',
            ],
        );

        $batch = (new TemporalAgentOrchestrator(new TemporalAgentRegistry([
            new UpcomingBlockPrepAgent(leadWindowMinutes: 30),
        ])))->evaluate($context);

        $notification = (new TemporalNotificationDeliveryService(
            new StorageRepositoryAdapter($entityTypeManager->getStorage('temporal_notification')),
        ))->deliver($batch)[0] ?? null;

        self::assertInstanceOf(TemporalNotification::class, $notification);
    }
}
