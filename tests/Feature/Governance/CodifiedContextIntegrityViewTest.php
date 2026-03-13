<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Governance;

use Claudriel\Controller\Governance\CodifiedContextIntegrityController;
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

final class CodifiedContextIntegrityViewTest extends TestCase
{
    public function test_html_view_renders_expected_sections(): void
    {
        $controller = new CodifiedContextIntegrityController(
            $this->buildSeededEntityTypeManager(),
            new Environment(new FilesystemLoader('/home/fsd42/dev/claudriel/templates')),
            '/home/fsd42/dev/claudriel',
        );

        $response = $controller->index();

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Codified Context Integrity Report', $response->content);
        self::assertStringContainsString('Issue Table', $response->content);
        self::assertStringContainsString('Generated at', $response->content);
    }

    public function test_json_view_returns_expected_structure(): void
    {
        $controller = new CodifiedContextIntegrityController($this->buildSeededEntityTypeManager(), null, '/home/fsd42/dev/claudriel');

        $response = $controller->jsonView();
        $payload = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
        self::assertArrayHasKey('generated_at', $payload);
        self::assertArrayHasKey('issues', $payload);
        self::assertArrayHasKey('classifications', $payload);
        self::assertArrayHasKey('summary', $payload);
    }

    private function buildSeededEntityTypeManager(): EntityTypeManager
    {
        $today = new \DateTimeImmutable('today');
        $previousWindow = $today->sub(new \DateInterval('P18D'))->format('Y-m-d');
        $currentWindowOne = $today->sub(new \DateInterval('P2D'))->format('Y-m-d');
        $currentWindowTwo = $today->sub(new \DateInterval('P1D'))->format('Y-m-d');

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
        $previousAlpha = $this->saveEvent($eventStorage, 'alpha@example.com', $previousWindow.' 09:00:00', 'governance-prev-alpha');
        $currentAlpha = $this->saveEvent($eventStorage, 'alpha@example.com', $currentWindowOne.' 09:00:00', 'governance-current-alpha');
        $currentBeta = $this->saveEvent($eventStorage, 'beta@example.com', $currentWindowTwo.' 10:00:00', 'governance-current-beta');

        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Previous alpha commitment',
            'confidence' => 0.92,
            'source_event_id' => $previousAlpha->id(),
        ]));
        $entityTypeManager->getStorage('commitment')->save(new Commitment([
            'title' => 'Current alpha commitment',
            'confidence' => 0.56,
            'source_event_id' => $currentAlpha->id(),
        ]));

        $entityTypeManager->getStorage('commitment_extraction_log')->save(new CommitmentExtractionLog([
            'mc_event_id' => $currentBeta->id(),
            'raw_event_payload' => '{"from_email":"beta@example.com","subject":"Beta context"}',
            'extracted_commitment_payload' => '{"title":"Need beta details","confidence":0.33}',
            'confidence' => 0.33,
            'failure_category' => 'insufficient_context',
            'created_at' => $currentWindowTwo.' 12:00:00',
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
