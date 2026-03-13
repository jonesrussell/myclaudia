<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ai;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Service\Ai\TrainingExportService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class TrainingExportServiceTest extends TestCase
{
    public function test_export_daily_samples_returns_grouped_samples_with_labels(): void
    {
        $service = new TrainingExportService($this->buildSeededEntityTypeManager());

        $export = $service->exportDailySamples(7);
        $days = [];
        foreach ($export['days'] as $day) {
            $days[$day['date']] = $day['samples'];
        }

        self::assertSame(7, $export['window_days']);
        self::assertArrayHasKey('2026-03-12', $days);
        self::assertCount(2, $days['2026-03-12']);
        self::assertSame('success', $days['2026-03-12'][0]['label']);
        self::assertSame('failure', $days['2026-03-12'][1]['label']);
        self::assertArrayHasKey('mc_event_id', $days['2026-03-12'][0]);
        self::assertArrayHasKey('raw_event_payload', $days['2026-03-12'][0]);
        self::assertArrayHasKey('extracted_commitment_payload', $days['2026-03-12'][0]);
        self::assertArrayHasKey('confidence', $days['2026-03-12'][0]);
        self::assertArrayHasKey('failure_category', $days['2026-03-12'][0]);
    }

    public function test_export_sender_samples_filters_by_sender_and_date(): void
    {
        $service = new TrainingExportService($this->buildSeededEntityTypeManager());

        $export = $service->exportSenderSamples('alpha@example.com', 30);

        self::assertSame('alpha@example.com', $export['sender']);
        self::assertCount(3, $export['samples']);
        foreach ($export['samples'] as $sample) {
            self::assertSame('alpha@example.com', $sample['sender']);
        }
    }

    public function test_export_all_failures_only_includes_failures_in_window(): void
    {
        $service = new TrainingExportService($this->buildSeededEntityTypeManager());

        $export = $service->exportAllFailures(90);

        self::assertCount(2, $export['samples']);
        self::assertSame('failure', $export['samples'][0]['label']);
        self::assertSame('ambiguous', $export['samples'][0]['failure_category']);
        self::assertSame('insufficient_context', $export['samples'][1]['failure_category']);
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
            'content_hash' => 'export-alpha',
        ]);
        $eventStorage->save($alphaEvent);

        $betaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"beta@example.com","subject":"Beta"}',
            'occurred' => '2026-03-13 11:30:00',
            'content_hash' => 'export-beta',
        ]);
        $eventStorage->save($betaEvent);

        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Alpha follow-up',
            'confidence' => 0.82,
            'source_event_id' => $alphaEvent->id(),
        ]));
        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Beta launch',
            'confidence' => 0.96,
            'source_event_id' => $betaEvent->id(),
        ]));

        $logStorage = $entityTypeManager->getStorage('commitment_extraction_log');
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $alphaEvent->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha maybe"}',
            'extracted_commitment_payload' => '{"title":"Alpha maybe","confidence":0.22}',
            'confidence' => 0.22,
            'failure_category' => 'ambiguous',
            'created_at' => '2026-03-12 09:15:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha context"}',
            'extracted_commitment_payload' => '{"title":"Send note","confidence":0.48}',
            'confidence' => 0.48,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-02-20 15:17:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $betaEvent->id(),
            'raw_event_payload' => '{"from_email":"beta@example.com","subject":"Beta old"}',
            'extracted_commitment_payload' => '{"title":"Old beta","confidence":0.31}',
            'confidence' => 0.31,
            'failure_category' => 'unknown',
            'created_at' => '2025-11-01 10:00:00',
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
