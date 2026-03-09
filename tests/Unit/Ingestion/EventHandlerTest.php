<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ingestion;

use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Ingestion\EventHandler;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\Ingestion\Envelope;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class EventHandlerTest extends TestCase
{
    public function testCreatesEventAndPersonFromEnvelope(): void
    {
        $driver = new InMemoryStorageDriver();
        $dispatcher = new EventDispatcher();

        $eventRepo = new EntityRepository(
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']),
            $driver,
            $dispatcher,
        );
        $personRepo = new EntityRepository(
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver(),
            $dispatcher,
        );

        $handler = new EventHandler($eventRepo, $personRepo);
        $envelope = new Envelope(
            source: 'gmail',
            type: 'message.received',
            payload: ['message_id' => 'msg1', 'thread_id' => 'thread1', 'from_email' => 'jane@example.com', 'from_name' => 'Jane', 'subject' => 'Ping', 'body' => 'Can you review this?', 'date' => '2026-03-08T09:00:00+00:00'],
            timestamp: '2026-03-08T09:00:00+00:00',
            traceId: 'trace-1',
            tenantId: 'user-1',
        );

        $handler->handle($envelope);

        $events  = $eventRepo->findBy([]);
        $persons = $personRepo->findBy([]);
        self::assertCount(1, $events);
        self::assertCount(1, $persons);
        self::assertSame('gmail', $events[0]->get('source'));
        self::assertSame('jane@example.com', $persons[0]->get('email'));
    }
}
