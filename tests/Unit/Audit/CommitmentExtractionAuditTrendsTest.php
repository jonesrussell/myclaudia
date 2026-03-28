<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Audit;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class CommitmentExtractionAuditTrendsTest extends TestCase
{
    public function test_daily_trends_aggregate_attempts_successes_and_average_confidence(): void
    {
        $service = new CommitmentExtractionAuditService($this->buildSeededEntityTypeManager());

        $trends = $service->getDailyTrends(7);
        $series = [];
        foreach ($trends['series'] as $point) {
            $series[$point['date']] = $point;
        }

        $day2 = date('Y-m-d', strtotime('-2 days'));
        $day1 = date('Y-m-d', strtotime('-1 day'));

        self::assertSame(3, $trends['summary']['total_attempts']);
        self::assertSame(2, $trends['summary']['successful_extractions']);
        self::assertSame(1, $trends['summary']['low_confidence_logs']);
        self::assertSame(0.7967, $trends['summary']['average_confidence']);

        self::assertSame(2, $series[$day2]['total_attempts']);
        self::assertSame(1, $series[$day2]['successful_extractions']);
        self::assertSame(1, $series[$day2]['low_confidence_logs']);
        self::assertSame(0.715, $series[$day2]['average_confidence']);

        self::assertSame(1, $series[$day1]['total_attempts']);
        self::assertSame(1, $series[$day1]['successful_extractions']);
        self::assertSame(0, $series[$day1]['low_confidence_logs']);
        self::assertSame(0.96, $series[$day1]['average_confidence']);
    }

    public function test_monthly_trends_roll_up_by_month(): void
    {
        $service = new CommitmentExtractionAuditService($this->buildSeededEntityTypeManager());

        $trends = $service->getMonthlyTrends(3);
        $series = [];
        foreach ($trends['series'] as $point) {
            $series[$point['month']] = $point;
        }

        $monthOld = date('Y-m', strtotime('-55 days'));
        $monthMid = date('Y-m', strtotime('-29 days'));
        $monthNow = date('Y-m');

        // When monthOld and monthMid fall in the same calendar month,
        // their totals merge. Verify per-month totals accordingly.
        if ($monthOld === $monthMid) {
            self::assertSame(2, $series[$monthOld]['total_attempts']);
            self::assertSame(0, $series[$monthOld]['successful_extractions']);
            self::assertSame(2, $series[$monthOld]['low_confidence_logs']);
        } else {
            self::assertSame(1, $series[$monthOld]['total_attempts']);
            self::assertSame(0, $series[$monthOld]['successful_extractions']);
            self::assertSame(1, $series[$monthOld]['low_confidence_logs']);
            self::assertSame(0.22, $series[$monthOld]['average_confidence']);

            self::assertSame(1, $series[$monthMid]['total_attempts']);
            self::assertSame(0, $series[$monthMid]['successful_extractions']);
            self::assertSame(1, $series[$monthMid]['low_confidence_logs']);
            self::assertSame(0.48, $series[$monthMid]['average_confidence']);
        }

        self::assertSame(3, $series[$monthNow]['total_attempts']);
        self::assertSame(2, $series[$monthNow]['successful_extractions']);
        self::assertSame(1, $series[$monthNow]['low_confidence_logs']);
        self::assertSame(0.7967, $series[$monthNow]['average_confidence']);
    }

    public function test_sender_trends_calculate_distribution_and_low_confidence_rate(): void
    {
        $service = new CommitmentExtractionAuditService($this->buildSeededEntityTypeManager());

        $trends = $service->getSenderTrends('alpha@example.com', 30);
        $distribution = [];
        foreach ($trends['confidence_distribution'] as $bucket) {
            $distribution[$bucket['label']] = $bucket['count'];
        }
        $series = [];
        foreach ($trends['daily_trends'] as $point) {
            $series[$point['date']] = $point;
        }

        self::assertSame('alpha@example.com', $trends['sender']);
        self::assertSame(3, $trends['summary']['total_attempts']);
        self::assertSame(1, $trends['summary']['successful_extractions']);
        self::assertSame(2, $trends['summary']['low_confidence_logs']);
        self::assertSame(0.6667, $trends['summary']['low_confidence_rate']);
        self::assertSame(0.6367, $trends['summary']['average_confidence']);

        self::assertSame(0, $distribution['0.0-0.3']);
        self::assertSame(1, $distribution['0.3-0.5']);
        self::assertSame(1, $distribution['0.5-0.7']);
        self::assertSame(1, $distribution['0.7-0.9']);
        self::assertSame(0, $distribution['0.9-1.0']);

        $day2 = date('Y-m-d', strtotime('-2 days'));
        $dayMid = date('Y-m-d', strtotime('-29 days'));

        self::assertSame(2, $series[$day2]['total_attempts']);
        self::assertSame(1, $series[$day2]['low_confidence_logs']);
        self::assertSame(0.5, $series[$day2]['low_confidence_rate']);
        self::assertSame(0.715, $series[$day2]['average_confidence']);

        self::assertSame(1, $series[$dayMid]['total_attempts']);
        self::assertSame(1, $series[$dayMid]['low_confidence_logs']);
        self::assertSame(1.0, $series[$dayMid]['low_confidence_rate']);
    }

    private function buildSeededEntityTypeManager(): EntityTypeManager
    {
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
        $day2 = date('Y-m-d', strtotime('-2 days'));
        $day1 = date('Y-m-d', strtotime('-1 day'));
        $dayOld = date('Y-m-d', strtotime('-55 days'));

        $alphaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"alpha@example.com","subject":"Alpha"}',
            'occurred' => $day2.' 08:15:00',
            'content_hash' => 'trend-alpha',
        ]);
        $eventStorage->save($alphaEvent);
        $alphaEventId = $alphaEvent->id();

        $betaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"beta@example.com","subject":"Beta"}',
            'occurred' => $day1.' 11:30:00',
            'content_hash' => 'trend-beta',
        ]);
        $eventStorage->save($betaEvent);
        $betaEventId = $betaEvent->id();

        $gammaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"gamma@example.com","subject":"Gamma"}',
            'occurred' => $dayOld.' 09:00:00',
            'content_hash' => 'trend-gamma',
        ]);
        $eventStorage->save($gammaEvent);
        $gammaEventId = $gammaEvent->id();

        $commitmentStorage = $entityTypeManager->getStorage('commitment');
        $commitmentStorage->save(new Commitment([
            'title' => 'Alpha follow-up',
            'confidence' => 0.82,
            'source_event_id' => $alphaEventId,
        ]));
        $commitmentStorage->save(new Commitment([
            'title' => 'Beta launch',
            'confidence' => 0.96,
            'source_event_id' => $betaEventId,
        ]));

        $logStorage = $entityTypeManager->getStorage('commitment_extraction_log');
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $alphaEventId,
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha maybe"}',
            'extracted_commitment_payload' => '{"title":"Alpha maybe","confidence":0.61}',
            'confidence' => 0.61,
            'created_at' => $day2.' 09:15:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $gammaEventId,
            'raw_event_payload' => '{"from_email":"gamma@example.com","subject":"Gamma maybe"}',
            'extracted_commitment_payload' => '{"title":"Gamma maybe","confidence":0.22}',
            'confidence' => 0.22,
            'created_at' => $dayOld.' 09:16:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha second"}',
            'extracted_commitment_payload' => '{"title":"Alpha second maybe","confidence":0.48}',
            'confidence' => 0.48,
            'created_at' => date('Y-m-d', strtotime('-29 days')).' 15:17:00',
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
