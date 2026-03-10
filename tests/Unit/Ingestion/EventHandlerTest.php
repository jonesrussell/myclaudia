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
    private EntityRepository $eventRepo;
    private EntityRepository $personRepo;
    private EventHandler $handler;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher();

        $this->eventRepo = new EntityRepository(
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
            new InMemoryStorageDriver(),
            $dispatcher,
        );
        $this->personRepo = new EntityRepository(
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver(),
            $dispatcher,
        );

        $this->handler = new EventHandler($this->eventRepo, $this->personRepo);
    }

    public function test_creates_event_and_person_from_envelope(): void
    {
        $envelope = new Envelope(
            source: 'gmail',
            type: 'message.received',
            payload: ['message_id' => 'msg1', 'thread_id' => 'thread1', 'from_email' => 'jane@example.com', 'from_name' => 'Jane', 'subject' => 'Ping', 'body' => 'Can you review this?', 'date' => '2026-03-08T09:00:00+00:00'],
            timestamp: '2026-03-08T09:00:00+00:00',
            traceId: 'trace-1',
            tenantId: 'user-1',
        );

        $this->handler->handle($envelope);

        $events = $this->eventRepo->findBy([]);
        $persons = $this->personRepo->findBy([]);
        self::assertCount(1, $events);
        self::assertCount(1, $persons);
        self::assertSame('gmail', $events[0]->get('source'));
        self::assertSame('jane@example.com', $persons[0]->get('email'));
    }

    public function test_skips_duplicate_events_with_same_content_hash(): void
    {
        $payload = ['message_id' => 'msg-dup', 'thread_id' => 't1', 'from_email' => 'jane@example.com', 'from_name' => 'Jane', 'subject' => 'Hello', 'body' => 'Test', 'date' => '2026-03-08T09:00:00+00:00'];

        $first = $this->handler->handle(new Envelope(
            source: 'gmail',
            type: 'message.received',
            payload: $payload,
            timestamp: '2026-03-08T09:00:00+00:00',
            traceId: 'trace-dup-1',
            tenantId: 'user-1',
        ));

        $second = $this->handler->handle(new Envelope(
            source: 'gmail',
            type: 'message.received',
            payload: $payload,
            timestamp: '2026-03-08T09:00:00+00:00',
            traceId: 'trace-dup-2',
            tenantId: 'user-1',
        ));

        $events = $this->eventRepo->findBy([]);
        self::assertCount(1, $events);
        self::assertSame($first->get('content_hash'), $second->get('content_hash'));
    }

    public function test_sets_content_hash_on_event(): void
    {
        $this->handler->handle(new Envelope(
            source: 'gmail',
            type: 'message.received',
            payload: ['message_id' => 'msg-hash', 'from_email' => 'a@b.com', 'from_name' => 'A'],
            timestamp: '2026-03-08T09:00:00+00:00',
            traceId: 'trace-hash',
            tenantId: 'user-1',
        ));

        $events = $this->eventRepo->findBy([]);
        self::assertNotNull($events[0]->get('content_hash'));
        self::assertSame(64, strlen($events[0]->get('content_hash')));
    }

    public function test_upserts_person_with_automated_tier_for_noreply_email(): void
    {
        $this->handler->handle(new Envelope(
            source: 'gmail',
            type: 'message.received',
            payload: ['message_id' => 'msg-noreply', 'from_email' => 'noreply@github.com', 'from_name' => 'GitHub', 'subject' => 'Token expired', 'body' => 'Your PAT expired'],
            timestamp: '2026-03-08T09:00:00+00:00',
            traceId: 'trace-tier-1',
            tenantId: 'user-1',
        ));

        $persons = $this->personRepo->findBy([]);
        self::assertCount(1, $persons);
        self::assertSame('automated', $persons[0]->get('tier'));
    }

    public function test_upserts_person_with_contact_tier_for_regular_email(): void
    {
        $this->handler->handle(new Envelope(
            source: 'gmail',
            type: 'message.received',
            payload: ['message_id' => 'msg-chris', 'from_email' => 'chris@example.com', 'from_name' => 'Chris', 'subject' => 'Hey', 'body' => 'What is up'],
            timestamp: '2026-03-08T09:00:00+00:00',
            traceId: 'trace-tier-2',
            tenantId: 'user-1',
        ));

        $persons = $this->personRepo->findBy([]);
        self::assertCount(1, $persons);
        self::assertSame('contact', $persons[0]->get('tier'));
    }

    public function test_categorizes_gmail_event_as_people(): void
    {
        $this->handler->handle(new Envelope(
            source: 'gmail',
            type: 'message.received',
            payload: ['message_id' => 'msg-cat', 'from_email' => 'chris@example.com', 'from_name' => 'Chris', 'subject' => 'Lunch tomorrow?', 'body' => 'Want to grab lunch?'],
            timestamp: '2026-03-08T09:00:00+00:00',
            traceId: 'trace-cat-1',
            tenantId: 'user-1',
        ));

        $events = $this->eventRepo->findBy([]);
        self::assertSame('people', $events[0]->get('category'));
    }

    public function test_categorizes_calendar_event_as_schedule(): void
    {
        $this->handler->handle(new Envelope(
            source: 'google-calendar',
            type: 'calendar.event',
            payload: ['title' => 'Team standup', 'start_time' => '2026-03-10T09:00:00', 'calendar_id' => 'primary'],
            timestamp: '2026-03-10T09:00:00+00:00',
            traceId: 'trace-cat-2',
            tenantId: 'user-1',
        ));

        $events = $this->eventRepo->findBy([]);
        self::assertSame('schedule', $events[0]->get('category'));
    }

    public function test_categorizes_job_related_gmail_as_job_hunt(): void
    {
        $this->handler->handle(new Envelope(
            source: 'gmail',
            type: 'message.received',
            payload: ['message_id' => 'msg-job', 'from_email' => 'recruiter@company.com', 'from_name' => 'Recruiter', 'subject' => 'Your job application was received', 'body' => 'Thanks for applying'],
            timestamp: '2026-03-08T09:00:00+00:00',
            traceId: 'trace-cat-3',
            tenantId: 'user-1',
        ));

        $events = $this->eventRepo->findBy([]);
        self::assertSame('job_hunt', $events[0]->get('category'));
    }
}
