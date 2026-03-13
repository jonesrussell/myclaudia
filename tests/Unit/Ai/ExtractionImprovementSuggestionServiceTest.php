<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ai;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Service\Ai\ExtractionImprovementSuggestionService;
use Claudriel\Service\Ai\ExtractionSelfAssessmentService;
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

final class ExtractionImprovementSuggestionServiceTest extends TestCase
{
    public function test_generate_suggestions_returns_expected_recommendations(): void
    {
        $service = $this->buildService();

        $report = $service->generateSuggestions(14);
        $categories = array_column($report['suggestions'], 'category');

        self::assertContains('failure_category', $categories);
        self::assertContains('drift', $categories);
        self::assertContains('sender_hotspot', $categories);
        self::assertContains('confidence', $categories);
        self::assertContains('training_export', $categories);
        self::assertContains('self_assessment', $categories);
    }

    public function test_generate_suggestions_assigns_high_severity_to_major_regressions(): void
    {
        $service = $this->buildService();

        $report = $service->generateSuggestions(14);
        $suggestionsByCategory = [];
        foreach ($report['suggestions'] as $suggestion) {
            $suggestionsByCategory[$suggestion['category']] = $suggestion;
        }

        self::assertSame('high', $suggestionsByCategory['drift']['severity']);
        self::assertSame('high', $suggestionsByCategory['sender_hotspot']['severity']);
        self::assertSame('high', $suggestionsByCategory['training_export']['severity']);
        self::assertStringContainsString('Retrain or recalibrate the extractor', $suggestionsByCategory['drift']['recommended_action']);
    }

    public function test_summarize_suggestions_returns_short_natural_language_summary(): void
    {
        $service = $this->buildService();
        $report = $service->generateSuggestions(14);

        $summary = $service->summarizeSuggestions($report['suggestions']);

        self::assertStringContainsString('Claudriel generated', $summary);
        self::assertStringContainsString('High severity drift recommendation', $summary);
        self::assertStringContainsString('sender hotspot', strtolower($summary));
    }

    private function buildService(): ExtractionImprovementSuggestionService
    {
        $entityTypeManager = $this->buildSeededEntityTypeManager();
        $auditService = new CommitmentExtractionAuditService($entityTypeManager);
        $driftDetector = new CommitmentExtractionDriftDetector($auditService, new \DateTimeImmutable('2026-03-13'));
        $failureClassifier = new CommitmentExtractionFailureClassifier;

        return new ExtractionImprovementSuggestionService(
            new ExtractionSelfAssessmentService($auditService, $driftDetector, $failureClassifier),
            $driftDetector,
            $auditService,
            new TrainingExportService($entityTypeManager),
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

        $prevAlpha = $this->saveEvent($eventStorage, 'alpha@example.com', '2026-02-20 09:00:00', 'improvement-prev-alpha');
        $prevBeta = $this->saveEvent($eventStorage, 'beta@example.com', '2026-02-21 09:00:00', 'improvement-prev-beta');
        $prevGamma = $this->saveEvent($eventStorage, 'gamma@example.com', '2026-02-22 09:00:00', 'improvement-prev-gamma');
        $currentAlpha = $this->saveEvent($eventStorage, 'alpha@example.com', '2026-03-12 09:00:00', 'improvement-current-alpha');
        $currentBeta = $this->saveEvent($eventStorage, 'beta@example.com', '2026-03-11 09:00:00', 'improvement-current-beta');
        $currentGamma = $this->saveEvent($eventStorage, 'gamma@example.com', '2026-03-13 09:00:00', 'improvement-current-gamma');

        $commitmentStorage = $entityTypeManager->getStorage('commitment');
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous alpha commitment',
            'confidence' => 0.95,
            'source_event_id' => $prevAlpha->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous beta commitment',
            'confidence' => 0.91,
            'source_event_id' => $prevBeta->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous gamma commitment',
            'confidence' => 0.88,
            'source_event_id' => $prevGamma->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Current alpha commitment',
            'confidence' => 0.54,
            'source_event_id' => $currentAlpha->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Current beta commitment',
            'confidence' => 0.58,
            'source_event_id' => $currentBeta->id(),
        ]));

        $logStorage = $entityTypeManager->getStorage('commitment_extraction_log');
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentAlpha->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha maybe"}',
            'extracted_commitment_payload' => '{"title":"Maybe alpha","confidence":0.22}',
            'confidence' => 0.22,
            'failure_category' => 'ambiguous',
            'created_at' => '2026-03-12 10:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentAlpha->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha context"}',
            'extracted_commitment_payload' => '{"title":"Need alpha details","confidence":0.37}',
            'confidence' => 0.37,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-13 08:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentBeta->id(),
            'raw_event_payload' => '{"from_email":"beta@example.com","subject":"Beta context"}',
            'extracted_commitment_payload' => '{"title":"Need beta details","confidence":0.33}',
            'confidence' => 0.33,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-11 10:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentGamma->id(),
            'raw_event_payload' => '{"from_email":"gamma@example.com","subject":"Gamma context"}',
            'extracted_commitment_payload' => '{"title":"Need gamma details","confidence":0.28}',
            'confidence' => 0.28,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-13 10:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentGamma->id(),
            'raw_event_payload' => '{"from_email":"gamma@example.com","subject":"Gamma parse"}',
            'confidence' => 0.19,
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
