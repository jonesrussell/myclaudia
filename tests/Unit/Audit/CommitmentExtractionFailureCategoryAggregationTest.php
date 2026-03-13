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

final class CommitmentExtractionFailureCategoryAggregationTest extends TestCase
{
    public function test_returns_failure_category_counts_and_distribution(): void
    {
        $service = new CommitmentExtractionAuditService($this->buildSeededEntityTypeManager());

        self::assertSame([
            'ambiguous' => 1,
            'insufficient_context' => 1,
            'non_actionable' => 1,
            'model_parse_error' => 0,
            'unknown' => 0,
        ], $service->getFailureCategoryCounts());

        self::assertSame([
            ['category' => 'ambiguous', 'count' => 1, 'rate' => 0.3333],
            ['category' => 'insufficient_context', 'count' => 1, 'rate' => 0.3333],
            ['category' => 'non_actionable', 'count' => 1, 'rate' => 0.3333],
            ['category' => 'model_parse_error', 'count' => 0, 'rate' => 0.0],
            ['category' => 'unknown', 'count' => 0, 'rate' => 0.0],
        ], $service->getFailureCategoryDistribution());
    }

    public function test_returns_sender_failure_category_breakdown(): void
    {
        $service = new CommitmentExtractionAuditService($this->buildSeededEntityTypeManager());

        self::assertSame([
            'sender' => 'alpha@example.com',
            'total_low_confidence_logs' => 2,
            'categories' => [
                ['category' => 'ambiguous', 'count' => 1, 'rate' => 0.5],
                ['category' => 'insufficient_context', 'count' => 1, 'rate' => 0.5],
                ['category' => 'non_actionable', 'count' => 0, 'rate' => 0.0],
                ['category' => 'model_parse_error', 'count' => 0, 'rate' => 0.0],
                ['category' => 'unknown', 'count' => 0, 'rate' => 0.0],
            ],
        ], $service->getSenderFailureCategories('alpha@example.com'));
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
        $alphaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"alpha@example.com","subject":"Alpha"}',
            'occurred' => '2026-03-12 08:15:00',
            'content_hash' => 'failure-alpha',
        ]);
        $eventStorage->save($alphaEvent);

        $betaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"beta@example.com","subject":"Beta"}',
            'occurred' => '2026-03-13 08:15:00',
            'content_hash' => 'failure-beta',
        ]);
        $eventStorage->save($betaEvent);

        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Reply to alpha',
            'confidence' => 0.91,
            'source_event_id' => $alphaEvent->id(),
        ]));

        $logStorage = $entityTypeManager->getStorage('commitment_extraction_log');
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $alphaEvent->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha"}',
            'extracted_commitment_payload' => '{"title":"Maybe reply","confidence":0.22}',
            'confidence' => 0.22,
            'failure_category' => 'ambiguous',
            'created_at' => '2026-03-12 09:15:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha follow-up"}',
            'extracted_commitment_payload' => '{"title":"Send the note","confidence":0.51}',
            'confidence' => 0.51,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-13 09:15:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $betaEvent->id(),
            'raw_event_payload' => '{"from_email":"beta@example.com","subject":"Beta"}',
            'extracted_commitment_payload' => '{"title":"Project update","confidence":0.44}',
            'confidence' => 0.44,
            'failure_category' => 'non_actionable',
            'created_at' => '2026-03-13 10:15:00',
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
