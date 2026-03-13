<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Audit;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class CommitmentExtractionAuditServiceTest extends TestCase
{
    public function test_aggregates_metrics_distribution_and_top_senders(): void
    {
        $entityTypeManager = $this->buildEntityTypeManager();

        $eventStorage = $entityTypeManager->getStorage('mc_event');
        $alphaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"alpha@example.com","subject":"Alpha"}',
            'content_hash' => 'audit-alpha',
        ]);
        $eventStorage->save($alphaEvent);
        $alphaEventId = $alphaEvent->id();

        $betaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"beta@example.com","subject":"Beta"}',
            'content_hash' => 'audit-beta',
        ]);
        $eventStorage->save($betaEvent);
        $betaEventId = $betaEvent->id();

        $gammaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"gamma@example.com","subject":"Gamma"}',
            'content_hash' => 'audit-gamma',
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
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha"}',
            'extracted_commitment_payload' => '{"title":"Alpha maybe","confidence":0.61}',
            'confidence' => 0.61,
            'created_at' => '2026-03-12 09:15:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $gammaEventId,
            'raw_event_payload' => '{"from_email":"gamma@example.com","subject":"Gamma"}',
            'extracted_commitment_payload' => '{"title":"Gamma maybe","confidence":0.22}',
            'confidence' => 0.22,
            'created_at' => '2026-03-12 09:16:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha second"}',
            'extracted_commitment_payload' => '{"title":"Alpha second maybe","confidence":0.48}',
            'confidence' => 0.48,
            'created_at' => '2026-03-12 09:17:00',
        ]));

        $service = new CommitmentExtractionAuditService($entityTypeManager);

        self::assertSame([
            'total_extraction_attempts' => 5,
            'total_successful_commitments' => 2,
            'total_low_confidence_logs' => 3,
        ], $service->getSummaryMetrics());

        self::assertSame([
            ['label' => '0.0-0.3', 'count' => 1],
            ['label' => '0.3-0.5', 'count' => 1],
            ['label' => '0.5-0.7', 'count' => 1],
            ['label' => '0.7-0.9', 'count' => 1],
            ['label' => '0.9-1.0', 'count' => 1],
        ], $service->getConfidenceDistribution());

        $topSenders = $service->getTopSenders();
        self::assertSame('gamma@example.com', $topSenders[0]['sender']);
        self::assertSame(1.0, $topSenders[0]['low_confidence_rate']);
        self::assertSame('alpha@example.com', $topSenders[1]['sender']);
        self::assertSame(3, $topSenders[1]['total_attempts']);
        self::assertSame(2, $topSenders[1]['low_confidence_attempts']);
        self::assertSame(1, $topSenders[1]['successful_commitments']);
        self::assertSame('beta@example.com', $topSenders[2]['sender']);
        self::assertSame(0.0, $topSenders[2]['low_confidence_rate']);

        $paginated = $service->getPaginatedLogs(1, 2);
        self::assertSame(3, $paginated['total']);
        self::assertSame(2, $paginated['per_page']);
        self::assertCount(2, $paginated['items']);
        self::assertSame('2026-03-12 09:17:00', $paginated['items'][0]['created_at']);
    }

    private function buildEntityTypeManager(): EntityTypeManager
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
