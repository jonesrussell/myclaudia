<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Audit;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Claudriel\Service\Audit\CommitmentExtractionDriftDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class CommitmentExtractionDriftDetectorTest extends TestCase
{
    public function test_detect_daily_drift_returns_expected_deltas(): void
    {
        $service = new CommitmentExtractionAuditService($this->buildSeededEntityTypeManager());
        $detector = new CommitmentExtractionDriftDetector($service, new \DateTimeImmutable('2026-03-13'));

        $drift = $detector->detectDailyDrift(14);

        self::assertSame('global', $drift['scope']);
        self::assertSame(7, $drift['window_days']);
        self::assertSame('2026-03-07', $drift['current_window']['start_date']);
        self::assertSame('2026-03-13', $drift['current_window']['end_date']);
        self::assertSame('2026-02-28', $drift['previous_window']['start_date']);
        self::assertSame('2026-03-06', $drift['previous_window']['end_date']);
        self::assertSame(-0.3467, $drift['delta']['avg_confidence_delta']);
        self::assertSame(0.3467, $drift['delta']['avg_confidence_drop']);
        self::assertSame(0.3334, $drift['delta']['low_confidence_rate_delta']);
        self::assertSame('severe', $drift['classification']);

        $failureDelta = [];
        foreach ($drift['delta']['failure_category_distribution_delta'] as $category) {
            $failureDelta[$category['category']] = $category;
        }

        self::assertSame(0.5, $failureDelta['ambiguous']['current_rate']);
        self::assertSame(0.0, $failureDelta['ambiguous']['previous_rate']);
        self::assertSame(0.5, $failureDelta['ambiguous']['delta']);
        self::assertSame(0.0, $failureDelta['insufficient_context']['current_rate']);
        self::assertSame(1.0, $failureDelta['insufficient_context']['previous_rate']);
        self::assertSame(-1.0, $failureDelta['insufficient_context']['delta']);
    }

    public function test_detect_sender_drift_scopes_to_sender(): void
    {
        $service = new CommitmentExtractionAuditService($this->buildSeededEntityTypeManager());
        $detector = new CommitmentExtractionDriftDetector($service, new \DateTimeImmutable('2026-03-13'));

        $drift = $detector->detectSenderDrift('alpha@example.com', 14);

        self::assertSame('sender', $drift['scope']);
        self::assertSame('alpha@example.com', $drift['sender']);
        self::assertSame(7, $drift['window_days']);
        self::assertSame('severe', $drift['classification']);
    }

    public function test_classify_drift_thresholds(): void
    {
        $service = new CommitmentExtractionAuditService($this->buildSeededEntityTypeManager());
        $detector = new CommitmentExtractionDriftDetector($service, new \DateTimeImmutable('2026-03-13'));

        self::assertSame('none', $detector->classifyDrift([
            'avg_confidence_delta' => -0.02,
            'avg_confidence_drop' => 0.02,
            'low_confidence_rate_delta' => 0.0,
            'failure_category_distribution_delta' => [],
        ]));
        self::assertSame('minor', $detector->classifyDrift([
            'avg_confidence_delta' => -0.05,
            'avg_confidence_drop' => 0.05,
            'low_confidence_rate_delta' => 0.0,
            'failure_category_distribution_delta' => [],
        ]));
        self::assertSame('moderate', $detector->classifyDrift([
            'avg_confidence_delta' => -0.11,
            'avg_confidence_drop' => 0.11,
            'low_confidence_rate_delta' => 0.0,
            'failure_category_distribution_delta' => [],
        ]));
        self::assertSame('severe', $detector->classifyDrift([
            'avg_confidence_delta' => -0.18,
            'avg_confidence_drop' => 0.18,
            'low_confidence_rate_delta' => 0.0,
            'failure_category_distribution_delta' => [],
        ]));
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
            'content_hash' => 'drift-prev-alpha',
        ]);
        $eventStorage->save($previousAlphaEvent);

        $previousBetaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"beta@example.com","subject":"Prev beta"}',
            'occurred' => '2026-03-03 09:00:00',
            'content_hash' => 'drift-prev-beta',
        ]);
        $eventStorage->save($previousBetaEvent);

        $currentAlphaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"alpha@example.com","subject":"Current alpha"}',
            'occurred' => '2026-03-10 09:00:00',
            'content_hash' => 'drift-current-alpha',
        ]);
        $eventStorage->save($currentAlphaEvent);

        $currentBetaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"beta@example.com","subject":"Current beta"}',
            'occurred' => '2026-03-11 09:00:00',
            'content_hash' => 'drift-current-beta',
        ]);
        $eventStorage->save($currentBetaEvent);

        $commitmentStorage = $entityTypeManager->getStorage('commitment');
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous alpha commitment',
            'confidence' => 0.92,
            'source_event_id' => $previousAlphaEvent->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Previous beta commitment',
            'confidence' => 0.86,
            'source_event_id' => $previousBetaEvent->id(),
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Current alpha commitment',
            'confidence' => 0.72,
            'source_event_id' => $currentAlphaEvent->id(),
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
            'mc_event_id' => $currentBetaEvent->id(),
            'raw_event_payload' => '{"from_email":"beta@example.com","subject":"Current beta"}',
            'extracted_commitment_payload' => '{"title":"Status update","confidence":0.41}',
            'confidence' => 0.41,
            'failure_category' => 'non_actionable',
            'created_at' => '2026-03-13 10:00:00',
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
