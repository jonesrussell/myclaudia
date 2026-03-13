<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Platform;

use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class HeartbeatIndicatorViewTest extends TestCase
{
    private ?string $batchDir = null;

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

    #[DataProvider('heartbeatAges')]
    public function test_heartbeat_indicator_renders_expected_badge(string $heartbeatTimestamp, string $expectedBadge): void
    {
        $reference = new \DateTimeImmutable('2026-03-13T12:00:00+00:00');
        $controller = new ObservabilityDashboardController(
            $this->buildSeededEntityTypeManager(),
            new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates')),
            '/home/fsd42/dev/claudriel',
            $this->buildBatchDirectory(),
            $reference,
            new \DateTimeImmutable($heartbeatTimestamp),
        );

        $response = $controller->index(query: ['days' => 14]);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Last System Scan', $response->content);
        self::assertStringContainsString($heartbeatTimestamp, $response->content);
        self::assertStringContainsString('status-badge '.$expectedBadge, $response->content);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function heartbeatAges(): array
    {
        return [
            'fresh' => ['2026-03-13T11:55:30+00:00', 'green'],
            'stale' => ['2026-03-13T11:15:00+00:00', 'yellow'],
            'expired' => ['2026-03-13T10:00:00+00:00', 'red'],
        ];
    }

    private function buildBatchDirectory(): string
    {
        if ($this->batchDir !== null) {
            return $this->batchDir;
        }

        $this->batchDir = sys_get_temp_dir().'/claudriel-heartbeat-'.bin2hex(random_bytes(6));
        mkdir($this->batchDir, 0755, true);
        file_put_contents($this->batchDir.'/batch-heartbeat-001.json', json_encode([
            'batch_id' => 'batch-heartbeat-001',
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
        $today = new \DateTimeImmutable('today');
        $previousWindow = $today->sub(new \DateInterval('P18D'))->format('Y-m-d');
        $currentWindowOne = $today->sub(new \DateInterval('P2D'))->format('Y-m-d');
        $currentWindowTwo = $today->sub(new \DateInterval('P1D'))->format('Y-m-d');
        $currentWindowThree = $today->format('Y-m-d');

        $db = PdoDatabase::createSqlite(':memory:');
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
        $previousAlpha = $this->saveEvent($eventStorage, 'alpha@example.com', $previousWindow.' 09:00:00', 'heartbeat-prev-alpha');
        $currentAlpha = $this->saveEvent($eventStorage, 'alpha@example.com', $currentWindowOne.' 09:00:00', 'heartbeat-current-alpha');
        $currentBeta = $this->saveEvent($eventStorage, 'beta@example.com', $currentWindowTwo.' 10:00:00', 'heartbeat-current-beta');
        $currentGamma = $this->saveEvent($eventStorage, 'gamma@example.com', $currentWindowThree.' 11:00:00', 'heartbeat-current-gamma');

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
        ];
    }
}
