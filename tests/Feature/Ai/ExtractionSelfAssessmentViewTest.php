<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Ai;

use Claudriel\Controller\Ai\ExtractionSelfAssessmentController;
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

final class ExtractionSelfAssessmentViewTest extends TestCase
{
    public function test_html_view_renders_expected_sections(): void
    {
        $controller = new ExtractionSelfAssessmentController(
            $this->buildSeededEntityTypeManager(),
            new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates')),
        );

        $response = $controller->index(query: ['days' => 7]);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Extraction Self-Assessment', $response->content);
        self::assertStringContainsString('Overall Score', $response->content);
        self::assertStringContainsString('Failure Category Distribution', $response->content);
        self::assertStringContainsString('Recommended Focus Areas', $response->content);
        self::assertStringContainsString('Sender Hotspots', $response->content);
        self::assertStringContainsString('Self-Assessment Summary', $response->content);
    }

    public function test_json_view_returns_expected_keys(): void
    {
        $controller = new ExtractionSelfAssessmentController($this->buildSeededEntityTypeManager());

        $response = $controller->jsonView(query: ['days' => 7]);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertArrayHasKey('assessment', $payload);
        self::assertArrayHasKey('focus_summary', $payload);
        self::assertArrayHasKey('overall_score', $payload['assessment']);
        self::assertArrayHasKey('recommended_focus_areas', $payload['assessment']);
        self::assertArrayHasKey('sender_hotspots', $payload['assessment']);
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
            'content_hash' => 'assessment-view-prev-alpha',
        ]);
        $eventStorage->save($previousAlphaEvent);

        $currentAlphaEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"alpha@example.com","subject":"Current alpha"}',
            'occurred' => '2026-03-12 09:00:00',
            'content_hash' => 'assessment-view-current-alpha',
        ]);
        $eventStorage->save($currentAlphaEvent);

        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Previous alpha commitment',
            'confidence' => 0.94,
            'source_event_id' => $previousAlphaEvent->id(),
        ]));
        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Current alpha commitment',
            'confidence' => 0.82,
            'source_event_id' => $currentAlphaEvent->id(),
        ]));

        $entityTypeManager->getStorage('commitment_extraction_log')->save(new CommitmentExtractionLog([
            'mc_event_id' => $previousAlphaEvent->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Prev alpha"}',
            'extracted_commitment_payload' => '{"title":"Send previous alpha note","confidence":0.61}',
            'confidence' => 0.61,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-04 10:00:00',
        ]));
        $entityTypeManager->getStorage('commitment_extraction_log')->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentAlphaEvent->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Current alpha"}',
            'extracted_commitment_payload' => '{"title":"Maybe current alpha","confidence":0.22}',
            'confidence' => 0.22,
            'failure_category' => 'ambiguous',
            'created_at' => '2026-03-12 10:00:00',
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
