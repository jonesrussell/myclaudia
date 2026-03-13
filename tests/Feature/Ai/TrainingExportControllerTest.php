<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Ai;

use Claudriel\Controller\Ai\TrainingExportController;
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

final class TrainingExportControllerTest extends TestCase
{
    public function test_daily_endpoint_returns_valid_json(): void
    {
        $controller = new TrainingExportController($this->buildSeededEntityTypeManager());

        $response = $controller->daily(query: ['days' => 7]);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertArrayHasKey('days', $payload);
        self::assertArrayHasKey('generated_at', $payload);
    }

    public function test_sender_endpoint_returns_expected_keys(): void
    {
        $controller = new TrainingExportController($this->buildSeededEntityTypeManager());

        $response = $controller->sender(['email' => rawurlencode('alpha@example.com')], ['days' => 30]);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame('alpha@example.com', $payload['sender']);
        self::assertArrayHasKey('samples', $payload);
        self::assertArrayHasKey('label', $payload['samples'][0]);
    }

    public function test_failures_endpoint_returns_failure_samples(): void
    {
        $controller = new TrainingExportController($this->buildSeededEntityTypeManager());

        $response = $controller->failures(query: ['days' => 90]);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertArrayHasKey('samples', $payload);
        self::assertSame('failure', $payload['samples'][0]['label']);
        self::assertArrayHasKey('failure_category', $payload['samples'][0]);
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
            'content_hash' => 'controller-export-alpha',
        ]);
        $eventStorage->save($alphaEvent);

        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Alpha follow-up',
            'confidence' => 0.82,
            'source_event_id' => $alphaEvent->id(),
        ]));

        $entityTypeManager->getStorage('commitment_extraction_log')->save(new CommitmentExtractionLog([
            'mc_event_id' => $alphaEvent->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha maybe"}',
            'extracted_commitment_payload' => '{"title":"Alpha maybe","confidence":0.22}',
            'confidence' => 0.22,
            'failure_category' => 'ambiguous',
            'created_at' => '2026-03-12 09:15:00',
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
