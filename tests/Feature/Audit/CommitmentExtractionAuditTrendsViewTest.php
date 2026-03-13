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

final class CommitmentExtractionAuditTrendsViewTest extends TestCase
{
    public function test_trends_view_renders_expected_sections(): void
    {
        $controller = new CommitmentExtractionAuditController(
            $this->buildSeededEntityTypeManager(),
            new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates')),
        );

        $response = $controller->trends(query: ['sender_email' => 'alpha@example.com']);

        self::assertSame(200, $response->statusCode);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertStringContainsString('Commitment Extraction Trends', $response->content);
        self::assertStringContainsString('7-Day Trend', $response->content);
        self::assertStringContainsString('30-Day Trend', $response->content);
        self::assertStringContainsString('Sender Lookup', $response->content);
        self::assertStringContainsString('Open sender trend detail', $response->content);
    }

    public function test_trends_json_returns_expected_keys(): void
    {
        $controller = new CommitmentExtractionAuditController($this->buildSeededEntityTypeManager());

        $response = $controller->trendsJson(query: ['sender_email' => 'alpha@example.com']);
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertArrayHasKey('daily_trends_7', $payload);
        self::assertArrayHasKey('daily_trends_30', $payload);
        self::assertArrayHasKey('monthly_trends', $payload);
        self::assertArrayHasKey('sender_preview', $payload);
        self::assertSame('alpha@example.com', $payload['sender_preview']['sender']);
    }

    public function test_sender_view_renders_distribution_and_daily_table(): void
    {
        $controller = new CommitmentExtractionAuditController(
            $this->buildSeededEntityTypeManager(),
            new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates')),
        );

        $response = $controller->sender(['email' => rawurlencode('alpha@example.com')]);

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Sender Trend: alpha@example.com', $response->content);
        self::assertStringContainsString('Confidence Distribution', $response->content);
        self::assertStringContainsString('Daily Trend', $response->content);
        self::assertStringContainsString('Low-confidence rate', $response->content);
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
        $eventId = $eventStorage->save(new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"from_email":"alpha@example.com","subject":"Audit sender"}',
            'occurred' => '2026-03-12 10:00:00',
            'content_hash' => 'feature-audit-alpha',
        ]));

        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Ship dashboard',
            'confidence' => 0.88,
            'source_event_id' => $eventId,
        ]));

        $entityTypeManager->getStorage('commitment_extraction_log')->save(new CommitmentExtractionLog([
            'mc_event_id' => $eventId,
            'raw_event_payload' => '{"from_email":"alpha@example.com","subject":"Audit sender"}',
            'extracted_commitment_payload' => '{"title":"Maybe ship dashboard","confidence":0.51}',
            'confidence' => 0.51,
            'created_at' => '2026-03-13 10:00:00',
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
