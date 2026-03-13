<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Audit;

use Claudriel\Controller\Audit\CommitmentExtractionAuditController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class CommitmentExtractionAuditViewTest extends TestCase
{
    public function test_index_view_renders_expected_structures(): void
    {
        ['entity_type_manager' => $entityTypeManager] = $this->seedEntityTypeManager();
        $twig = new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates'));
        $controller = new CommitmentExtractionAuditController($entityTypeManager, $twig);

        $response = $controller->index(query: ['page' => 1, 'per_page' => 10]);

        self::assertSame(200, $response->statusCode);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertStringContainsString('Commitment Extraction Audit', $response->content);
        self::assertStringContainsString('Confidence Histogram', $response->content);
        self::assertStringContainsString('Top Senders by Low-Confidence Rate', $response->content);
        self::assertStringContainsString('Inspect log', $response->content);
    }

    public function test_show_view_renders_log_payload_sections(): void
    {
        ['entity_type_manager' => $entityTypeManager, 'log_id' => $logId] = $this->seedEntityTypeManager();
        $twig = new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates'));
        $controller = new CommitmentExtractionAuditController($entityTypeManager, $twig);

        $response = $controller->show(['id' => $logId]);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Raw Event Payload', $response->content);
        self::assertStringContainsString('Extracted Commitment Payload', $response->content);
        self::assertStringContainsString('alpha@example.com', $response->content);
    }

    /**
     * @return array{entity_type_manager: EntityTypeManager, log_id: int}
     */
    private function seedEntityTypeManager(): array
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

        $eventId = $entityTypeManager->getStorage('mc_event')->save(new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"alpha@example.com","subject":"Audit sender"}',
        ]));

        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Ship dashboard',
            'confidence' => 0.88,
            'source_event_id' => $eventId,
        ]));

        $logId = $entityTypeManager->getStorage('commitment_extraction_log')->save(new CommitmentExtractionLog([
            'mc_event_id' => $eventId,
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Audit sender"}',
            'extracted_commitment_payload' => '{"title":"Maybe ship dashboard","confidence":0.51}',
            'confidence' => 0.51,
            'created_at' => '2026-03-12 10:00:00',
        ]));

        return [
            'entity_type_manager' => $entityTypeManager,
            'log_id' => $logId,
        ];
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
