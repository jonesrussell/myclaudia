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

final class CommitmentExtractionFailureCategoryViewTest extends TestCase
{
    public function test_index_view_renders_failure_category_table(): void
    {
        $controller = new CommitmentExtractionAuditController(
            $this->buildSeededEntityTypeManager(),
            new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates')),
        );

        $response = $controller->index(query: ['page' => 1, 'per_page' => 10]);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Failure Categories', $response->content);
        self::assertStringContainsString('insufficient_context', $response->content);
        self::assertStringContainsString('Failure category', $response->content);
    }

    public function test_trends_and_sender_views_render_failure_breakdowns(): void
    {
        $controller = new CommitmentExtractionAuditController(
            $this->buildSeededEntityTypeManager(),
            new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates')),
        );

        $trendsResponse = $controller->trends(query: ['sender_email' => 'alpha@example.com']);
        self::assertStringContainsString('Failure Category Distribution', $trendsResponse->content);
        self::assertStringContainsString('Open sender trend detail', $trendsResponse->content);

        $senderResponse = $controller->sender(['email' => rawurlencode('alpha@example.com')]);
        self::assertStringContainsString('Failure Category Breakdown', $senderResponse->content);
        self::assertStringContainsString('ambiguous', $senderResponse->content);
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
            'payload' => '{"from_email":"alpha@example.com","subject":"Audit sender"}',
            'occurred' => '2026-03-12 10:00:00',
            'content_hash' => 'failure-view-alpha',
        ]);
        $eventStorage->save($alphaEvent);

        $entityTypeManager->getStorage('commitment_extraction_log')->save(new CommitmentExtractionLog([
            'mc_event_id' => $alphaEvent->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Audit sender"}',
            'extracted_commitment_payload' => '{"title":"Maybe ship dashboard","confidence":0.22}',
            'confidence' => 0.22,
            'failure_category' => 'ambiguous',
            'created_at' => '2026-03-13 10:00:00',
        ]));
        $entityTypeManager->getStorage('commitment_extraction_log')->save(new CommitmentExtractionLog([
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Audit sender 2"}',
            'extracted_commitment_payload' => '{"title":"Send dashboard note","confidence":0.51}',
            'confidence' => 0.51,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-13 11:00:00',
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
