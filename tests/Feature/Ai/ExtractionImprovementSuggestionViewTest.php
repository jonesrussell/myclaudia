<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Ai;

use Claudriel\Controller\Ai\ExtractionImprovementSuggestionController;
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

final class ExtractionImprovementSuggestionViewTest extends TestCase
{
    public function test_html_view_renders_expected_sections(): void
    {
        $controller = new ExtractionImprovementSuggestionController(
            $this->buildSeededEntityTypeManager(),
            new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates')),
        );

        $response = $controller->index(query: ['days' => 14]);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Extraction Improvement Suggestions', $response->content);
        self::assertStringContainsString('Improvement Summary', $response->content);
        self::assertStringContainsString('Suggestions Table', $response->content);
        self::assertStringContainsString('Recommended Action', $response->content);
        self::assertStringContainsString('Severity', $response->content);
    }

    public function test_json_view_returns_expected_keys(): void
    {
        $controller = new ExtractionImprovementSuggestionController($this->buildSeededEntityTypeManager());

        $response = $controller->jsonView(query: ['days' => 14]);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertArrayHasKey('report', $payload);
        self::assertArrayHasKey('summary', $payload);
        self::assertArrayHasKey('suggestions', $payload['report']);
        self::assertArrayHasKey('assessment', $payload['report']);
        self::assertArrayHasKey('drift', $payload['report']);
        self::assertArrayHasKey('category', $payload['report']['suggestions'][0]);
        self::assertArrayHasKey('severity', $payload['report']['suggestions'][0]);
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
        $alphaEvent = $this->saveEvent($eventStorage, 'alpha@example.com', '2026-03-12 09:00:00', 'improvement-view-alpha');
        $betaEvent = $this->saveEvent($eventStorage, 'beta@example.com', '2026-03-13 09:00:00', 'improvement-view-beta');

        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Current alpha commitment',
            'confidence' => 0.53,
            'source_event_id' => $alphaEvent->id(),
        ]));

        $logStorage = $entityTypeManager->getStorage('commitment_extraction_log');
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $alphaEvent->id(),
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Alpha context"}',
            'extracted_commitment_payload' => '{"title":"Need alpha details","confidence":0.32}',
            'confidence' => 0.32,
            'failure_category' => 'insufficient_context',
            'created_at' => '2026-03-12 10:00:00',
        ]));
        $logStorage->save(new CommitmentExtractionLog([
            'mc_event_id' => $betaEvent->id(),
            'raw_event_payload' => '{"from_email":"beta@example.com","subject":"Beta maybe"}',
            'extracted_commitment_payload' => '{"title":"Maybe beta","confidence":0.23}',
            'confidence' => 0.23,
            'failure_category' => 'ambiguous',
            'created_at' => '2026-03-13 10:00:00',
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
