<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\DayBrief;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\TriageEntry;
use Claudriel\Entity\Workspace;
use Claudriel\Support\DriftDetector;
use Claudriel\Temporal\AtomicTimeService;
use Claudriel\Temporal\Clock\MonotonicClockInterface;
use Claudriel\Temporal\Clock\WallClockInterface;
use Claudriel\Temporal\RequestTimeSnapshotStore;
use Claudriel\Temporal\TimeSnapshot;
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

    private EntityRepository $scheduleRepo;

    private EntityRepository $triageRepo;

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
        $this->scheduleRepo = new EntityRepository(
            new EntityType(id: 'schedule_entry', label: 'Schedule Entry', class: ScheduleEntry::class, keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
        $this->triageRepo = new EntityRepository(
            new EntityType(id: 'triage_entry', label: 'Triage Entry', class: TriageEntry::class, keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $this->assembler = new DayBriefAssembler(
            $this->eventRepo,
            $this->commitmentRepo,
            new DriftDetector($this->commitmentRepo),
            $this->personRepo,
            null,
            $this->scheduleRepo,
            null,
            $this->triageRepo,
            new AtomicTimeService(
                wallClock: new class implements WallClockInterface
                {
                    public function now(): \DateTimeImmutable
                    {
                        return new \DateTimeImmutable('today 08:00:00', new \DateTimeZone('UTC'));
                    }
                },
                monotonicClock: new class implements MonotonicClockInterface
                {
                    public function now(): int
                    {
                        return 1000;
                    }
                },
                snapshotStore: new RequestTimeSnapshotStore,
                defaultTimezone: 'UTC',
            ),
        );
    }

    public function test_assemble_returns_categorized_structure(): void
    {
        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertArrayHasKey('schedule', $brief);
        self::assertArrayHasKey('schedule_summary', $brief);
        self::assertArrayHasKey('temporal_awareness', $brief);
        self::assertArrayHasKey('temporal_suggestions', $brief);
        self::assertArrayHasKey('job_hunt', $brief);
        self::assertArrayHasKey('people', $brief);
        self::assertArrayHasKey('triage', $brief);
        self::assertArrayHasKey('creators', $brief);
        self::assertArrayHasKey('notifications', $brief);
        self::assertArrayHasKey('commitments', $brief);
        self::assertArrayHasKey('counts', $brief);
        self::assertArrayHasKey('generated_at', $brief);
        self::assertArrayHasKey('time_snapshot', $brief);
        self::assertArrayHasKey('pending', $brief['commitments']);
        self::assertArrayHasKey('drifting', $brief['commitments']);
        self::assertArrayHasKey('job_alerts', $brief['counts']);
        self::assertArrayHasKey('messages', $brief['counts']);
        self::assertArrayHasKey('triage', $brief['counts']);
        self::assertArrayHasKey('due_today', $brief['counts']);
        self::assertArrayHasKey('drifting', $brief['counts']);
    }

    public function test_uses_injected_snapshot_for_generated_metadata(): void
    {
        $snapshot = new TimeSnapshot(
            new \DateTimeImmutable('2026-03-14T12:00:00+00:00'),
            new \DateTimeImmutable('2026-03-14T08:00:00-04:00'),
            1234,
            'America/Toronto',
        );

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'), snapshot: $snapshot);

        self::assertSame('2026-03-14T12:00:00+00:00', $brief['generated_at']);
        self::assertSame('America/Toronto', $brief['time_snapshot']['timezone']);
        self::assertSame(1234, $brief['time_snapshot']['monotonic_ns']);
        self::assertSame('Your day is clear', $brief['schedule_summary']);
    }

    public function test_includes_temporal_awareness_for_current_and_next_blocks(): void
    {
        $this->scheduleRepo->save(new ScheduleEntry([
            'seid' => 100,
            'title' => 'Current Block',
            'starts_at' => '2026-03-14T09:00:00-04:00',
            'ends_at' => '2026-03-14T10:30:00-04:00',
            'source' => 'google-calendar',
            'tenant_id' => 'user-1',
        ]));
        $this->scheduleRepo->save(new ScheduleEntry([
            'seid' => 101,
            'title' => 'Next Block',
            'starts_at' => '2026-03-14T11:00:00-04:00',
            'ends_at' => '2026-03-14T12:00:00-04:00',
            'source' => 'google-calendar',
            'tenant_id' => 'user-1',
        ]));

        $snapshot = new TimeSnapshot(
            new \DateTimeImmutable('2026-03-14T14:15:00+00:00'),
            new \DateTimeImmutable('2026-03-14T10:15:00-04:00'),
            5678,
            'America/Toronto',
        );

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('2026-03-13T14:15:00+00:00'), snapshot: $snapshot);

        self::assertSame('Current Block', $brief['temporal_awareness']['current_block']['title']);
        self::assertSame('Next Block', $brief['temporal_awareness']['next_block']['title']);
        self::assertCount(1, $brief['temporal_awareness']['gaps']);
        self::assertSame(['wrap_up'], array_column($brief['temporal_suggestions'], 'type'));
    }

    public function test_groups_schedule_events(): void
    {
        $today = (new \DateTimeImmutable)->format('Y-m-d');
        $entry = new ScheduleEntry([
            'seid' => 1,
            'title' => 'Team standup',
            'starts_at' => $today.'T09:00:00+00:00',
            'ends_at' => $today.'T09:30:00+00:00',
            'source' => 'google-calendar',
            'tenant_id' => 'user-1',
        ]);
        $this->scheduleRepo->save($entry);

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['schedule']);
        self::assertSame('Team standup', $brief['schedule'][0]['title']);
        self::assertEmpty($brief['job_hunt']);
        self::assertEmpty($brief['people']);
    }

    public function test_deduplicates_schedule_events_with_same_title_and_time(): void
    {
        $today = (new \DateTimeImmutable)->format('Y-m-d');
        $this->scheduleRepo->save(new ScheduleEntry([
            'seid' => 1,
            'uuid' => 'sched-1',
            'title' => 'Team standup',
            'starts_at' => $today.'T09:00:00+00:00',
            'ends_at' => $today.'T09:30:00+00:00',
            'source' => 'google-calendar',
            'external_id' => 'gcal-123',
            'tenant_id' => 'user-1',
        ]));

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['schedule']);
        self::assertSame('Team standup', $brief['schedule'][0]['title']);
        self::assertStringStartsWith($today.'T09:00:00', $brief['schedule'][0]['start_time']);
    }

    public function test_schedule_only_includes_today_and_is_sorted(): void
    {
        $today = new \DateTimeImmutable('today');
        $tomorrow = $today->modify('+1 day');

        $this->scheduleRepo->save(new ScheduleEntry([
            'seid' => 2,
            'title' => 'Late Meeting',
            'starts_at' => $today->format('Y-m-d').'T16:00:00+00:00',
            'ends_at' => $today->format('Y-m-d').'T17:00:00+00:00',
            'source' => 'google-calendar',
            'tenant_id' => 'user-1',
        ]));
        $this->scheduleRepo->save(new ScheduleEntry([
            'seid' => 3,
            'title' => 'Morning Standup',
            'starts_at' => $today->format('Y-m-d').'T09:00:00+00:00',
            'ends_at' => $today->format('Y-m-d').'T09:30:00+00:00',
            'source' => 'google-calendar',
            'tenant_id' => 'user-1',
        ]));
        $this->scheduleRepo->save(new ScheduleEntry([
            'seid' => 4,
            'title' => 'Tomorrow Planning',
            'starts_at' => $tomorrow->format('Y-m-d').'T10:00:00+00:00',
            'ends_at' => $tomorrow->format('Y-m-d').'T11:00:00+00:00',
            'source' => 'google-calendar',
            'tenant_id' => 'user-1',
        ]));

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(2, $brief['schedule']);
        self::assertSame('Morning Standup', $brief['schedule'][0]['title']);
        self::assertSame('Late Meeting', $brief['schedule'][1]['title']);
    }

    public function test_legacy_schedule_events_are_normalized_to_today_view_when_schedule_store_is_empty(): void
    {
        $occurred = new \DateTimeImmutable('yesterday 21:00:00');
        $this->eventRepo->save(new McEvent([
            'eid' => 1,
            'content_hash' => 'legacy-hash-1',
            'source' => 'google-calendar',
            'type' => 'calendar.event',
            'category' => 'schedule',
            'payload' => json_encode(['subject' => 'Content Creation', 'body' => '11:30am - 2:00pm']),
            'occurred' => $occurred->format('Y-m-d H:i:s'),
            'tenant_id' => 'user-1',
        ]));

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-48 hours'));

        self::assertCount(1, $brief['schedule']);
        self::assertSame('Content Creation', $brief['schedule'][0]['title']);
        self::assertStringStartsWith((new \DateTimeImmutable('today'))->format('Y-m-d').'T11:30:00', $brief['schedule'][0]['start_time']);
        self::assertStringEndsWith('-04:00', $brief['schedule'][0]['start_time']);
    }

    public function test_shifted_legacy_schedule_entries_collapse_to_earliest_local_variant(): void
    {
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        $this->scheduleRepo->save(new ScheduleEntry([
            'seid' => 10,
            'title' => 'SaaS & Dev Projects',
            'starts_at' => $today.'T09:00:00+00:00',
            'ends_at' => $today.'T10:00:00+00:00',
            'source' => 'google-calendar',
            'raw_payload' => json_encode(['subject' => 'SaaS & Dev Projects', 'body' => '9:00am - 10:00am']),
            'tenant_id' => 'user-1',
        ]));
        $this->scheduleRepo->save(new ScheduleEntry([
            'seid' => 11,
            'title' => 'SaaS & Dev Projects',
            'starts_at' => $today.'T10:00:00+00:00',
            'ends_at' => $today.'T11:00:00+00:00',
            'source' => 'google-calendar',
            'raw_payload' => json_encode(['subject' => 'SaaS & Dev Projects', 'body' => '10:00am - 11:00am']),
            'tenant_id' => 'user-1',
        ]));

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, array_values(array_filter(
            $brief['schedule'],
            fn (array $item): bool => $item['title'] === 'SaaS & Dev Projects',
        )));
        self::assertSame('SaaS & Dev Projects', $brief['schedule'][0]['title']);
        self::assertSame($today.'T09:00:00-04:00', $brief['schedule'][0]['start_time']);
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
        $this->personRepo->save(new Person([
            'email' => 'jane@example.com',
            'name' => 'Jane',
            'latest_summary' => 'Lunch?',
            'last_interaction_at' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
            'last_inbox_category' => 'people',
            'tenant_id' => 'user-1',
        ]));

        $brief = $this->assembler->assemble('user-1', new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['people']);
        self::assertSame('Jane', $brief['people'][0]['person_name']);
        self::assertSame('Lunch?', $brief['people'][0]['summary']);
        self::assertSame(1, $brief['counts']['messages']);
    }

    public function test_groups_triage_events(): void
    {
        $this->triageRepo->save(new TriageEntry([
            'sender_email' => 'unknown@company.com',
            'sender_name' => 'Unknown Sender',
            'summary' => 'Partnership opportunity',
            'occurred_at' => (new \DateTimeImmutable('-1 hour'))->format(\DateTimeInterface::ATOM),
            'tenant_id' => 'user-1',
        ]));

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
        $this->personRepo->save(new Person([
            'email' => 'old@example.com',
            'name' => 'Old Contact',
            'latest_summary' => 'Old note',
            'last_interaction_at' => (new \DateTimeImmutable('-48 hours'))->format(\DateTimeInterface::ATOM),
            'last_inbox_category' => 'people',
            'tenant_id' => 'user-1',
        ]));

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
            $this->scheduleRepo,
            $workspaceRepo,
            $this->triageRepo,
        );

        $wsUuid = 'test-workspace-uuid-1234';
        $workspace = new Workspace([
            'wid' => 1,
            'uuid' => $wsUuid,
            'name' => 'Alpha Project',
            'description' => 'Test workspace',
            'tenant_id' => 'user-1',
        ]);
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
