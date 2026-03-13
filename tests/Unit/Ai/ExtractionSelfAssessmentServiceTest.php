<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ai;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Service\Ai\ExtractionSelfAssessmentService;
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

final class ExtractionSelfAssessmentServiceTest extends TestCase
{
    public function test_generate_assessment_returns_expected_score_and_focus_areas(): void
    {
        $service = $this->buildService();

        $assessment = $service->generateAssessment(7);

        self::assertSame(46, $assessment['overall_score']);
        self::assertSame('severe', $assessment['drift_summary']['classification']);
        self::assertSame('ambiguous', $assessment['top_failure_categories'][0]['category']);
        self::assertContains(
            'Improve commitment disambiguation for vague or weakly phrased requests.',
            $assessment['recommended_focus_areas'],
        );
        self::assertContains(
            'Improve date extraction and person resolution for partial commitments.',
            $assessment['recommended_focus_areas'],
        );
    }

    public function test_generate_assessment_returns_hotspots_sorted_by_worst_drop(): void
    {
        $service = $this->buildService();

        $assessment = $service->generateAssessment(7);

        self::assertNotEmpty($assessment['sender_hotspots']);
        self::assertSame('alpha@example.com', $assessment['sender_hotspots'][0]['sender']);
        self::assertSame('severe', $assessment['sender_hotspots'][0]['classification']);
        self::assertGreaterThan(0.2, $assessment['sender_hotspots'][0]['avg_confidence_drop']);
    }

    public function test_generate_focus_summary_returns_short_natural_language_text(): void
    {
        $service = $this->buildService();
        $service->generateAssessment(7);

        $summary = $service->generateFocusSummary();

        self::assertStringContainsString('Claudriel scored 46/100', $summary);
        self::assertStringContainsString('ambiguous', $summary);
        self::assertStringContainsString('alpha@example.com', $summary);
    }

    private function buildService(): ExtractionSelfAssessmentService
    {
        $entityTypeManager = $this->buildSeededEntityTypeManager();
        $auditService = new CommitmentExtractionAuditService($entityTypeManager);
        $driftDetector = new CommitmentExtractionDriftDetector($auditService, new \DateTimeImmutable('2026-03-13'));

        return new ExtractionSelfAssessmentService(
            $auditService,
            $driftDetector,
            new CommitmentExtractionFailureClassifier,
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

        $previousAlphaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"alpha@example.com","subject":"Prev alpha"}',
            'occurred' => '2026-03-02 09:00:00',
            'content_hash' => 'assessment-prev-alpha',
        ]);
        $eventStorage->save($previousAlphaEvent);

        $previousBetaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"beta@example.com","subject":"Prev beta"}',
            'occurred' => '2026-03-03 09:00:00',
            'content_hash' => 'assessment-prev-beta',
        ]);
        $eventStorage->save($previousBetaEvent);

        $currentAlphaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"alpha@example.com","subject":"Current alpha"}',
            'occurred' => '2026-03-12 09:00:00',
            'content_hash' => 'assessment-current-alpha',
        ]);
        $eventStorage->save($currentAlphaEvent);

        $currentBetaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"beta@example.com","subject":"Current beta"}',
            'occurred' => '2026-03-13 09:00:00',
            'content_hash' => 'assessment-current-beta',
        ]);
        $eventStorage->save($currentBetaEvent);

        $currentGammaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"gamma@example.com","subject":"Current gamma"}',
            'occurred' => '2026-03-13 11:00:00',
            'content_hash' => 'assessment-current-gamma',
        ]);
        $eventStorage->save($currentGammaEvent);

        $commitmentStorage = $entityTypeManager->getStorage('commitment');
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous alpha commitment',
            'confidence' => 0.94,
            'source_event_id' => $previousAlphaEvent->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous beta commitment',
            'confidence' => 0.88,
            'source_event_id' => $previousBetaEvent->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Current alpha commitment',
            'confidence' => 0.82,
            'source_event_id' => $currentAlphaEvent->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Current beta commitment',
            'confidence' => 0.9,
            'source_event_id' => $currentBetaEvent->id(),
        ]));

        $logStorage = $entityTypeManager->getStorage('commitment_extraction_log');
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $previousAlphaEvent->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Prev alpha"}',
            'extracted_commitment_payload' => '{"title":"Send previous alpha note","confidence":0.61}',
            'confidence' => 0.61,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-04 10:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentAlphaEvent->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Current alpha"}',
            'extracted_commitment_payload' => '{"title":"Maybe current alpha","confidence":0.22}',
            'confidence' => 0.22,
            'failure_category' => 'ambiguous',
            'created_at' => '2026-03-12 10:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentGammaEvent->id(),
            'raw_event_payload' => '{"from_email":"gamma@example.com","subject":"Current gamma"}',
            'extracted_commitment_payload' => '{"title":"Send current gamma note","confidence":0.48}',
            'confidence' => 0.48,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-13 10:30:00',
        ]));

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
        ];
    }
}
