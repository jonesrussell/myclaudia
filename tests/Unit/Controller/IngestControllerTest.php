<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\IngestController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;

final class IngestControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private IngestController $controller;
    private string $originalApiKey;

    protected function setUp(): void
    {
        $this->originalApiKey = $_ENV['MYCLAUDIA_API_KEY'] ?? '';

        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($db, $dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $db))->ensureTable();
                return new SqlEntityStorage($definition, $db, $dispatcher);
            },
        );

        foreach ($this->entityTypeDefinitions() as $type) {
            $this->entityTypeManager->registerEntityType($type);
        }

        $_ENV['MYCLAUDIA_API_KEY'] = 'test-secret-key';

        $this->controller = new IngestController($this->entityTypeManager);
    }

    protected function tearDown(): void
    {
        if ($this->originalApiKey !== '') {
            $_ENV['MYCLAUDIA_API_KEY'] = $this->originalApiKey;
        } else {
            unset($_ENV['MYCLAUDIA_API_KEY']);
        }
    }

    public function testReturns401WithoutBearerToken(): void
    {
        $request = Request::create('/api/ingest', 'POST', [], [], [], [], '{}');
        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testReturns401WithInvalidToken(): void
    {
        $request = Request::create('/api/ingest', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer wrong-key',
        ], '{}');
        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testReturns422WithMissingFields(): void
    {
        $request = Request::create('/api/ingest', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-secret-key',
        ], json_encode(['source' => 'test']));
        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(422, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('Invalid payload', $body['error']);
    }

    public function testReturns422WithNonObjectPayload(): void
    {
        $request = Request::create('/api/ingest', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-secret-key',
        ], json_encode(['source' => 'test', 'type' => 'foo', 'payload' => 'string']));
        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(422, $response->getStatusCode());
    }

    public function testCreatesGenericEventForUnknownType(): void
    {
        $request = Request::create('/api/ingest', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-secret-key',
        ], json_encode([
            'source'  => 'test-source',
            'type'    => 'some.unknown.event',
            'payload' => ['key' => 'value'],
        ]));

        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('created', $body['status']);
        self::assertSame('mc_event', $body['entity_type']);
        self::assertNotEmpty($body['uuid']);
    }

    public function testCreatesCommitmentForCommitmentDetectedType(): void
    {
        $request = Request::create('/api/ingest', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-secret-key',
        ], json_encode([
            'source'  => 'gmail',
            'type'    => 'commitment.detected',
            'payload' => [
                'title'        => 'Follow up with Bob',
                'confidence'   => 0.85,
                'person_email' => 'bob@example.com',
                'person_name'  => 'Bob',
            ],
        ]));

        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('created', $body['status']);
        self::assertSame('commitment', $body['entity_type']);
        self::assertNotEmpty($body['uuid']);
        self::assertNotEmpty($body['person_uuid']);
    }

    public function testCreatesPersonForPersonCreatedType(): void
    {
        $request = Request::create('/api/ingest', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer test-secret-key',
        ], json_encode([
            'source'  => 'manual',
            'type'    => 'person.created',
            'payload' => [
                'email' => 'alice@example.com',
                'name'  => 'Alice',
            ],
        ]));

        $response = $this->controller->handle([], [], null, $request);

        self::assertSame(201, $response->getStatusCode());
        $body = json_decode($response->getContent(), true);
        self::assertSame('created', $body['status']);
        self::assertSame('person', $body['entity_type']);
        self::assertNotEmpty($body['uuid']);
    }

    /** @return list<EntityType> */
    private function entityTypeDefinitions(): array
    {
        return [
            new EntityType(
                id: 'mc_event',
                label: 'Event',
                class: McEvent::class,
                keys: ['id' => 'eid', 'uuid' => 'uuid'],
            ),
            new EntityType(
                id: 'commitment',
                label: 'Commitment',
                class: Commitment::class,
                keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
            ),
            new EntityType(
                id: 'person',
                label: 'Person',
                class: Person::class,
                keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
            ),
        ];
    }
}
