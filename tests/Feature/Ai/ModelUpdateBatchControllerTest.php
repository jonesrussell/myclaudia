<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Ai;

use Claudriel\Controller\Ai\ModelUpdateBatchController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class ModelUpdateBatchControllerTest extends TestCase
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

    public function test_post_creates_and_persists_batch(): void
    {
        $controller = $this->buildController();

        $response = $controller->create(
            query: ['days' => 14],
            httpRequest: new Request(query: ['days' => 14], request: ['days' => 14], server: ['REQUEST_METHOD' => 'POST']),
        );
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertSame('batch-2026-03-13-001', $payload['batch_id']);
        self::assertFileExists($payload['path']);
        self::assertSame(14, $payload['metadata']['window_days']);
    }

    public function test_get_returns_stored_batch_json(): void
    {
        $controller = $this->buildController();
        $createResponse = $controller->create(
            query: ['days' => 14],
            httpRequest: new Request(query: ['days' => 14], request: ['days' => 14], server: ['REQUEST_METHOD' => 'POST']),
        );
        $created = json_decode($createResponse->content, true, 512, JSON_THROW_ON_ERROR);

        $response = $controller->show(['batchId' => $created['batch_id']]);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame($created['batch_id'], $payload['batch_id']);
        self::assertArrayHasKey('metadata', $payload);
        self::assertArrayHasKey('failure_summary', $payload);
        self::assertArrayHasKey('recommended_actions', $payload);
    }

    public function test_get_returns_404_when_batch_is_missing(): void
    {
        $controller = $this->buildController();

        $response = $controller->show(['batchId' => 'batch-2026-03-13-999']);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(404, $response->statusCode);
        self::assertSame('Batch not found.', $payload['error']);
    }

    private function buildController(): ModelUpdateBatchController
    {
        $this->tmpDir = sys_get_temp_dir().'/claudriel-model-batch-controller-'.bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);

        return new ModelUpdateBatchController(
            $this->buildSeededEntityTypeManager(),
            $this->tmpDir,
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
        $alphaEvent = $this->saveEvent($eventStorage, 'alpha@example.com', '2026-03-12 09:00:00', 'controller-batch-alpha');
        $betaEvent = $this->saveEvent($eventStorage, 'beta@example.com', '2026-03-13 10:00:00', 'controller-batch-beta');

        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Alpha follow-up',
            'confidence' => 0.57,
            'source_event_id' => $alphaEvent->id(),
        ]));

        $logStorage = $entityTypeManager->getStorage('commitment_extraction_log');
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $alphaEvent->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha context"}',
            'extracted_commitment_payload' => '{"title":"Need alpha details","confidence":0.31}',
            'confidence' => 0.31,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-12 10:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $betaEvent->id(),
            'raw_event_payload' => '{"from_email":"beta@example.com","subject":"Beta maybe"}',
            'extracted_commitment_payload' => '{"title":"Maybe beta","confidence":0.24}',
            'confidence' => 0.24,
            'failure_category' => 'ambiguous',
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
