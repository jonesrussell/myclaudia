<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ingestion\Handler;

use Claudriel\Entity\Person;
use Claudriel\Ingestion\Handler\PersonIngestHandler;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PersonIngestHandlerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private PersonIngestHandler $handler;

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
            id: 'person',
            label: 'Person',
            class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->handler = new PersonIngestHandler($this->entityTypeManager);
    }

    public function testSupportsPersonCreated(): void
    {
        self::assertTrue($this->handler->supports('person.created'));
        self::assertFalse($this->handler->supports('commitment.detected'));
    }

    public function testCreatesNewPerson(): void
    {
        $result = $this->handler->handle([
            'source' => 'manual',
            'type'   => 'person.created',
            'payload' => [
                'email' => 'alice@example.com',
                'name'  => 'Alice',
            ],
        ]);

        self::assertSame('created', $result['status']);
        self::assertSame('person', $result['entity_type']);
        self::assertNotEmpty($result['uuid']);
    }

    public function testUpdatesExistingPerson(): void
    {
        // Create first.
        $result1 = $this->handler->handle([
            'source' => 'manual',
            'type'   => 'person.created',
            'payload' => [
                'email' => 'alice@example.com',
                'name'  => 'Alice',
            ],
        ]);

        // Upsert with same email, new name.
        $result2 = $this->handler->handle([
            'source' => 'manual',
            'type'   => 'person.created',
            'payload' => [
                'email' => 'alice@example.com',
                'name'  => 'Alice Smith',
            ],
        ]);

        self::assertSame('updated', $result2['status']);
        self::assertSame($result1['uuid'], $result2['uuid']);
    }

    public function testReturnsErrorForMissingEmail(): void
    {
        $result = $this->handler->handle([
            'source' => 'manual',
            'type'   => 'person.created',
            'payload' => ['name' => 'No Email'],
        ]);

        self::assertSame('error', $result['status']);
    }
}
