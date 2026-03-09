<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ingestion\Handler;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use Claudriel\Ingestion\Handler\CommitmentIngestHandler;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class CommitmentIngestHandlerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private CommitmentIngestHandler $handler;

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
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        ));

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'person',
            label: 'Person',
            class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->handler = new CommitmentIngestHandler($this->entityTypeManager);
    }

    public function testSupportsCommitmentDetected(): void
    {
        self::assertTrue($this->handler->supports('commitment.detected'));
        self::assertFalse($this->handler->supports('person.created'));
    }

    public function testCreatesCommitmentAndPerson(): void
    {
        $result = $this->handler->handle([
            'source' => 'gmail',
            'type'   => 'commitment.detected',
            'payload' => [
                'title'        => 'Follow up with Bob',
                'confidence'   => 0.9,
                'person_email' => 'bob@example.com',
                'person_name'  => 'Bob',
            ],
        ]);

        self::assertSame('created', $result['status']);
        self::assertSame('commitment', $result['entity_type']);
        self::assertNotEmpty($result['uuid']);
        self::assertNotEmpty($result['person_uuid']);
    }

    public function testUpsertsSamePersonOnSecondCall(): void
    {
        $data = [
            'source' => 'gmail',
            'type'   => 'commitment.detected',
            'payload' => [
                'title'        => 'Task 1',
                'person_email' => 'bob@example.com',
                'person_name'  => 'Bob',
            ],
        ];

        $result1 = $this->handler->handle($data);

        $data['payload']['title'] = 'Task 2';
        $result2 = $this->handler->handle($data);

        // Same person UUID for both commitments.
        self::assertSame($result1['person_uuid'], $result2['person_uuid']);
        // Different commitment UUIDs.
        self::assertNotSame($result1['uuid'], $result2['uuid']);
    }
}
