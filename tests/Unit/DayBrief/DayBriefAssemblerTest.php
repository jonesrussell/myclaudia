<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\DayBrief;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\Workspace;
use Claudriel\Support\DriftDetector;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class DayBriefAssemblerTest extends TestCase
{
    private EntityRepository $eventRepo;

    private EntityRepository $commitmentRepo;

    private EntityRepository $personRepo;

    private DayBriefAssembler $assembler;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher;

        $this->eventRepo = new EntityRepository(
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
        $this->commitmentRepo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
        $this->personRepo = new EntityRepository(
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $this->assembler = new DayBriefAssembler(
            $this->eventRepo,
            $this->commitmentRepo,
            new DriftDetector($this->commitmentRepo),
            $this->personRepo,
        );
    }

    public function test_assemble_returns_categorized_structure(): void
    {
        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertArrayHasKey('schedule', $brief);
        self::assertArrayHasKey('job_hunt', $brief);
        self::assertArrayHasKey('people', $brief);
        self::assertArrayHasKey('triage', $brief);
        self::assertArrayHasKey('creators', $brief);
        self::assertArrayHasKey('notifications', $brief);
        self::assertArrayHasKey('commitments', $brief);
        self::assertArrayHasKey('counts', $brief);
        self::assertArrayHasKey('generated_at', $brief);
        self::assertArrayHasKey('pending', $brief['commitments']);
        self::assertArrayHasKey('drifting', $brief['commitments']);
        self::assertArrayHasKey('job_alerts', $brief['counts']);
        self::assertArrayHasKey('messages', $brief['counts']);
        self::assertArrayHasKey('triage', $brief['counts']);
        self::assertArrayHasKey('due_today', $brief['counts']);
        self::assertArrayHasKey('drifting', $brief['counts']);
    }

    public function test_groups_schedule_events(): void
    {
        $event = new McEvent([
            'source' => 'google-calendar',
            'type' => 'calendar.event',
            'category' => 'schedule',
            'payload' => json_encode(['title' => 'Team standup', 'start_time' => '2026-03-10T09:00:00']),
            'occurred' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            'tenant_id' => 'user-1',
        ]);
        $this->eventRepo->save($event);

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['schedule']);
        self::assertSame('Team standup', $brief['schedule'][0]['title']);
        self::assertEmpty($brief['job_hunt']);
        self::assertEmpty($brief['people']);
    }

    public function test_groups_job_hunt_events(): void
    {
        $event = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'category' => 'job_hunt',
            'payload' => json_encode(['subject' => 'Your application was received', 'from_name' => 'Indeed']),
            'occurred' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
            'tenant_id' => 'user-1',
        ]);
        $this->eventRepo->save($event);

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['job_hunt']);
        self::assertSame('Your application was received', $brief['job_hunt'][0]['title']);
        self::assertSame(1, $brief['counts']['job_alerts']);
    }

    public function test_groups_people_events(): void
    {
        $event = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'category' => 'people',
            'payload' => json_encode(['from_email' => 'jane@example.com', 'from_name' => 'Jane', 'subject' => 'Lunch?']),
            'occurred' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            'tenant_id' => 'user-1',
        ]);
        $this->eventRepo->save($event);

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['people']);
        self::assertSame('Jane', $brief['people'][0]['person_name']);
        self::assertSame('Lunch?', $brief['people'][0]['summary']);
        self::assertSame(1, $brief['counts']['messages']);
    }

    public function test_groups_triage_events(): void
    {
        $event = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'category' => 'triage',
            'payload' => json_encode(['from_email' => 'unknown@company.com', 'from_name' => 'Unknown Sender', 'subject' => 'Partnership opportunity']),
            'occurred' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            'tenant_id' => 'user-1',
        ]);
        $this->eventRepo->save($event);

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['triage']);
        self::assertSame('Unknown Sender', $brief['triage'][0]['person_name']);
        self::assertSame('Partnership opportunity', $brief['triage'][0]['summary']);
        self::assertSame(1, $brief['counts']['triage']);
    }

    public function test_includes_pending_commitments(): void
    {
        $commitment = new Commitment(['title' => 'Reply to Jane', 'status' => 'pending', 'confidence' => 0.85, 'tenant_id' => 'user-1']);
        $this->commitmentRepo->save($commitment);

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['commitments']['pending']);
    }

    public function test_filters_old_events(): void
    {
        $oldEvent = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'category' => 'people',
            'payload' => '{}',
            'occurred' => (new \DateTimeImmutable('-48 hours'))->format('Y-m-d H:i:s'),
            'tenant_id' => 'user-1',
        ]);
        $this->eventRepo->save($oldEvent);

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertEmpty($brief['people']);
        self::assertEmpty($brief['schedule']);
    }

    public function test_notification_events_grouped_correctly(): void
    {
        $event = new McEvent([
            'source' => 'webhook',
            'type' => 'alert',
            'category' => 'notification',
            'payload' => json_encode(['subject' => 'CI build passed']),
            'occurred' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            'tenant_id' => 'user-1',
        ]);
        $this->eventRepo->save($event);

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['notifications']);
        self::assertSame('CI build passed', $brief['notifications'][0]['title']);
    }

    public function test_assembler_includes_workspace_data_with_activity_counts(): void
    {
        $dispatcher = new EventDispatcher;

        $workspaceRepo = new EntityRepository(
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $assembler = new DayBriefAssembler(
            $this->eventRepo,
            $this->commitmentRepo,
            new DriftDetector($this->commitmentRepo),
            $this->personRepo,
            null,
            $workspaceRepo,
        );

        $wsUuid = 'test-workspace-uuid-1234';
        $workspace = new Workspace(['wid' => 1, 'uuid' => $wsUuid, 'name' => 'Alpha Project', 'description' => 'Test workspace']);
        $workspaceRepo->save($workspace);

        // 2 events belonging to the workspace
        $this->eventRepo->save(new McEvent([
            'eid' => 101,
            'content_hash' => 'hash-101',
            'source' => 'gmail',
            'type' => 'message.received',
            'category' => 'people',
            'payload' => '{}',
            'occurred' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'),
            'tenant_id' => 'user-1',
            'workspace_id' => $wsUuid,
        ]));
        $this->eventRepo->save(new McEvent([
            'eid' => 102,
            'content_hash' => 'hash-102',
            'source' => 'gmail',
            'type' => 'message.received',
            'category' => 'people',
            'payload' => '{}',
            'occurred' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'),
            'tenant_id' => 'user-1',
            'workspace_id' => $wsUuid,
        ]));

        // 1 event without workspace_id
        $this->eventRepo->save(new McEvent([
            'eid' => 103,
            'content_hash' => 'hash-103',
            'source' => 'gmail',
            'type' => 'message.received',
            'category' => 'people',
            'payload' => '{}',
            'occurred' => (new \DateTimeImmutable('-3 hours'))->format('Y-m-d H:i:s'),
            'tenant_id' => 'user-1',
        ]));

        $result = $assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertArrayHasKey('workspaces', $result);
        self::assertCount(1, $result['workspaces']);

        $entry = $result['workspaces'][0];
        self::assertSame('Alpha Project', $entry['name']);
        self::assertSame($wsUuid, $entry['uuid']);
        self::assertSame(2, $entry['activity_count']);
    }
}
