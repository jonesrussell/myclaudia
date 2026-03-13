<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Platform;

use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class ObservabilityDashboardAggregationTest extends TestCase
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

    public function test_json_view_aggregates_all_observability_sections(): void
    {
        $controller = $this->buildController();

        $response = $controller->jsonView(query: ['days' => 14]);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertArrayHasKey('extraction_health', $payload);
        self::assertArrayHasKey('drift_overview', $payload);
        self::assertArrayHasKey('self_assessment', $payload);
        self::assertArrayHasKey('improvement_suggestions', $payload);
        self::assertArrayHasKey('training_export_readiness', $payload);
        self::assertArrayHasKey('governance_integrity', $payload);
        self::assertArrayHasKey('model_update_batches', $payload);
        self::assertArrayHasKey('system_summary', $payload);
        self::assertSame('batch-observability-001', $payload['model_update_batches'][0]['batch_id']);
        self::assertGreaterThan(0, $payload['training_export_readiness']['daily_sample_count']);
        self::assertNotEmpty($payload['self_assessment']['recommended_focus_areas']);
    }

    private function buildController(): ObservabilityDashboardController
    {
        $this->batchDir = sys_get_temp_dir().'/claudriel-observability-batches-'.bin2hex(random_bytes(6));
        mkdir($this->batchDir, 0755, true);

        file_put_contents($this->batchDir.'/batch-observability-001.json', json_encode([
            'batch_id' => 'batch-observability-001',
            'metadata' => [
                'generated_at' => '2026-03-13T12:00:00+00:00',
                'window_days' => 14,
                'total_samples' => 5,
                'failure_rate' => 0.6,
                'drift_classification' => 'severe',
            ],
        ], JSON_THROW_ON_ERROR));

        return new ObservabilityDashboardController(
            $this->buildSeededEntityTypeManager(),
            null,
            '/home/fsd42/dev/claudriel',
            $this->batchDir,
        );
    }

    private function buildSeededEntityTypeManager(): EntityTypeManager
    {
        $today = new \DateTimeImmutable('today');
        $previousWindow = $today->sub(new \DateInterval('P20D'))->format('Y-m-d');
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
        $previousAlpha = $this->saveEvent($eventStorage, 'alpha@example.com', $previousWindow.' 09:00:00', 'obs-prev-alpha');
        $previousBeta = $this->saveEvent($eventStorage, 'beta@example.com', $previousWindow.' 10:00:00', 'obs-prev-beta');
        $currentAlpha = $this->saveEvent($eventStorage, 'alpha@example.com', $currentWindowOne.' 09:00:00', 'obs-current-alpha');
        $currentBeta = $this->saveEvent($eventStorage, 'beta@example.com', $currentWindowTwo.' 10:00:00', 'obs-current-beta');
        $currentGamma = $this->saveEvent($eventStorage, 'gamma@example.com', $currentWindowThree.' 11:00:00', 'obs-current-gamma');

        $commitmentStorage = $entityTypeManager->getStorage('commitment');
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous alpha commitment',
            'confidence' => 0.92,
            'source_event_id' => $previousAlpha->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous beta commitment',
            'confidence' => 0.9,
            'source_event_id' => $previousBeta->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Current alpha commitment',
            'confidence' => 0.54,
            'source_event_id' => $currentAlpha->id(),
        ]));

        $logStorage = $entityTypeManager->getStorage('commitment_extraction_log');
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentBeta->id(),
            'raw_event_payload' => '{"from_email":"beta@example.com","subject":"Beta context"}',
            'extracted_commitment_payload' => '{"title":"Need beta details","confidence":0.31}',
            'confidence' => 0.31,
            'failure_category' => 'insufficient_context',
            'created_at' => $currentWindowTwo.' 12:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentGamma->id(),
            'raw_event_payload' => '{"from_email":"gamma@example.com","subject":"Gamma maybe"}',
            'extracted_commitment_payload' => '{"title":"Maybe gamma","confidence":0.22}',
            'confidence' => 0.22,
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
