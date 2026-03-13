<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ai;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Service\Ai\ExtractionImprovementSuggestionService;
use Claudriel\Service\Ai\ExtractionSelfAssessmentService;
use Claudriel\Service\Ai\ModelUpdateBatchGenerator;
use Claudriel\Service\Ai\TrainingExportService;
use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Claudriel\Service\Audit\CommitmentExtractionDriftDetector;
use Claudriel\Service\Audit\CommitmentExtractionFailureClassifier;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class ModelUpdateBatchGeneratorTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir.'/*') ?: [];
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->tmpDir);
        }

        parent::tearDown();
    }

    public function test_generate_batch_returns_expected_structure_and_metadata(): void
    {
        $generator = $this->buildGenerator();

        $batch = $generator->generateBatch(14);

        self::assertSame('batch-2026-03-13-001', $batch['batch_id']);
        self::assertSame(14, $batch['metadata']['window_days']);
        self::assertSame(5, $batch['metadata']['total_samples']);
        self::assertSame('severe', $batch['metadata']['drift_classification']);
        self::assertGreaterThan(0.0, $batch['metadata']['failure_rate']);
        self::assertArrayHasKey('daily', $batch['samples']);
        self::assertArrayHasKey('sender_hotspots', $batch['samples']);
        self::assertNotEmpty($batch['samples']['sender_hotspots']);
        self::assertSame('insufficient_context', $batch['failure_summary']['top_failure_categories'][0]['category']);
        self::assertContains(
            'Improve entity linking and partial-commitment resolution so extracted commitments preserve people, dates, and referenced actions.',
            $batch['recommended_actions'],
        );
    }

    public function test_generate_batch_id_increments_when_prior_batches_exist(): void
    {
        $generator = $this->buildGenerator();
        file_put_contents($this->tmpDir.'/batch-2026-03-13-001.json', '{}');

        self::assertSame('batch-2026-03-13-002', $generator->generateBatchId());
    }

    public function test_save_batch_writes_json_file(): void
    {
        $generator = $this->buildGenerator();
        $batch = $generator->generateBatch(14);

        $generator->saveBatch($batch);

        $path = $generator->getBatchPath($batch['batch_id']);
        self::assertFileExists($path);

        $stored = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($batch['batch_id'], $stored['batch_id']);
        self::assertSame($batch['metadata']['total_samples'], $stored['metadata']['total_samples']);
    }

    private function buildGenerator(): ModelUpdateBatchGenerator
    {
        $this->tmpDir = sys_get_temp_dir().'/claudriel-model-batch-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);

        $entityTypeManager = $this->buildSeededEntityTypeManager();
        $auditService = new CommitmentExtractionAuditService($entityTypeManager);
        $driftDetector = new CommitmentExtractionDriftDetector($auditService, new \DateTimeImmutable('2026-03-13'));
        $failureClassifier = new CommitmentExtractionFailureClassifier;
        $selfAssessment = new ExtractionSelfAssessmentService($auditService, $driftDetector, $failureClassifier);
        $trainingExport = new TrainingExportService($entityTypeManager);

        return new ModelUpdateBatchGenerator(
            $trainingExport,
            $auditService,
            $driftDetector,
            $selfAssessment,
            new ExtractionImprovementSuggestionService($selfAssessment, $driftDetector, $auditService, $trainingExport),
            $this->tmpDir,
            new \DateTimeImmutable('2026-03-13 12:00:00'),
        );
    }

    private function buildSeededEntityTypeManager(): EntityTypeManager
    {
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
        $events = [
            $this->saveEvent($eventStorage, 'alpha@example.com', '2026-02-20 09:00:00', 'batch-prev-alpha'),
            $this->saveEvent($eventStorage, 'beta@example.com', '2026-02-21 09:00:00', 'batch-prev-beta'),
            $this->saveEvent($eventStorage, 'gamma@example.com', '2026-02-22 09:00:00', 'batch-prev-gamma'),
            $this->saveEvent($eventStorage, 'alpha@example.com', '2026-03-11 09:00:00', 'batch-current-alpha-1'),
            $this->saveEvent($eventStorage, 'alpha@example.com', '2026-03-12 09:00:00', 'batch-current-alpha-2'),
            $this->saveEvent($eventStorage, 'beta@example.com', '2026-03-12 11:00:00', 'batch-current-beta-1'),
            $this->saveEvent($eventStorage, 'gamma@example.com', '2026-03-13 09:00:00', 'batch-current-gamma-1'),
            $this->saveEvent($eventStorage, 'delta@example.com', '2026-03-13 10:00:00', 'batch-current-delta-1'),
        ];

        $commitmentStorage = $entityTypeManager->getStorage('commitment');
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous alpha commitment',
            'confidence' => 0.94,
            'source_event_id' => $events[0]->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous beta commitment',
            'confidence' => 0.9,
            'source_event_id' => $events[1]->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous gamma commitment',
            'confidence' => 0.88,
            'source_event_id' => $events[2]->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Current alpha commitment',
            'confidence' => 0.54,
            'source_event_id' => $events[3]->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Current beta commitment',
            'confidence' => 0.59,
            'source_event_id' => $events[5]->id(),
        ]));

        $logStorage = $entityTypeManager->getStorage('commitment_extraction_log');
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $events[4]->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha context"}',
            'extracted_commitment_payload' => '{"title":"Need alpha details","confidence":0.34}',
            'confidence' => 0.34,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-12 10:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $events[6]->id(),
            'raw_event_payload' => '{"from_email":"gamma@example.com","subject":"Gamma maybe"}',
            'extracted_commitment_payload' => '{"title":"Maybe gamma","confidence":0.23}',
            'confidence' => 0.23,
            'failure_category' => 'ambiguous',
            'created_at' => '2026-03-13 10:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $events[7]->id(),
            'raw_event_payload' => '{"from_email":"delta@example.com","subject":"Delta context"}',
            'extracted_commitment_payload' => '{"title":"Need delta details","confidence":0.29}',
            'confidence' => 0.29,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-13 10:30:00',
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
