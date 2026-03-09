<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\ContextController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ContextControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($db, $dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $db))->ensureTable();
                return new SqlEntityStorage($definition, $db, $dispatcher);
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'mc_event',
            label: 'Event',
            class: McEvent::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid'],
        ));

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        ));
    }

    public function testShowReturnsJsonWithBriefAndContextFiles(): void
    {
        $controller = new ContextController($this->entityTypeManager, null);
        $response = $controller->show();

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);

        $body = json_decode($response->content, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('brief', $body);
        self::assertArrayHasKey('context_files', $body);

        $brief = $body['brief'];
        self::assertArrayHasKey('recent_events', $brief);
        self::assertArrayHasKey('pending_commitments', $brief);
        self::assertArrayHasKey('drifting_commitments', $brief);

        $contextFiles = $body['context_files'];
        self::assertArrayHasKey('me', $contextFiles);
        self::assertArrayHasKey('commitments', $contextFiles);
        self::assertArrayHasKey('patterns', $contextFiles);
    }

    public function testShowIncludesRecentEvents(): void
    {
        $event = new McEvent([
            'uuid'     => 'eeee0001-0001-0001-0001-eeeeeeeeeeee',
            'type'     => 'email_received',
            'source'   => 'gmail',
            'occurred' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'payload'  => json_encode(['subject' => 'Test']),
        ]);
        $this->entityTypeManager->getStorage('mc_event')->save($event);

        $controller = new ContextController($this->entityTypeManager, null);
        $response = $controller->show();

        $body = json_decode($response->content, true);
        self::assertNotEmpty($body['brief']['recent_events']);
    }

    public function testShowIncludesPendingCommitments(): void
    {
        $commitment = new Commitment([
            'title'  => 'Follow up',
            'status' => 'pending',
        ]);
        $this->entityTypeManager->getStorage('commitment')->save($commitment);

        $controller = new ContextController($this->entityTypeManager, null);
        $response = $controller->show();

        $body = json_decode($response->content, true);
        self::assertNotEmpty($body['brief']['pending_commitments']);
    }
}
