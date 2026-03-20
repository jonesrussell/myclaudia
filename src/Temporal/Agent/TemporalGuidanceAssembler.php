<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

use Claudriel\Entity\TemporalNotification;
use Claudriel\Support\StorageRepositoryAdapter;
use Claudriel\Temporal\AtomicTimeService;
use Claudriel\Temporal\Clock\SystemMonotonicClock;
use Claudriel\Temporal\Clock\SystemWallClock;
use Claudriel\Temporal\ClockHealthMonitor;
use Claudriel\Temporal\RequestTimeSnapshotStore;
use Claudriel\Temporal\SystemClockSyncProbe;
use Claudriel\Temporal\TimeSnapshot;
use Waaseyaa\Entity\EntityTypeManager;

final class TemporalGuidanceAssembler
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    /**
     * @param  array{
     *   schedule: list<array{title: string, start_time: string, end_time: string, source: string}>,
     *   schedule_timeline: list<array{title: string, start_time: string, end_time: string, source: string}>,
     *   schedule_summary: string,
     *   temporal_awareness: array{
     *     current_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *     next_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *     gaps: list<array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}>,
     *     overruns: list<array{title: string, ended_at: string, overrun_minutes: int}>
     *   }
     * }  $brief
     * @return array{
     *   notifications: list<array{
     *     uuid: string,
     *     agent_name: string,
     *     state: string,
     *     title: string,
     *     summary: string,
     *     reason_code: string,
     *     actions: list<array{type: string, label: string, payload: array<string, mixed>, state: string}>,
     *     metadata: array<string, mixed>
     *   }>,
     *   ambient_nudge: ?array{
     *     uuid: string,
     *     agent_name: string,
     *     state: string,
     *     title: string,
     *     summary: string,
     *     reason_code: string,
     *     actions: list<array{type: string, label: string, payload: array<string, mixed>, state: string}>,
     *     metadata: array<string, mixed>
     *   }
     * }
     */
    public function build(string $tenantId, ?string $workspaceUuid, array $brief, TimeSnapshot $snapshot): array
    {
        $notificationStorage = $this->entityTypeManager->getStorage('temporal_notification');
        $deliveryService = new TemporalNotificationDeliveryService(new StorageRepositoryAdapter($notificationStorage));

        $context = (new TemporalAgentContextBuilder)->build(
            tenantId: $tenantId,
            workspaceUuid: $workspaceUuid,
            snapshot: $snapshot,
            clockHealth: $this->clockHealthMonitor($snapshot)->assess(referenceSource: 'system-wall-clock'),
            schedule: $brief['schedule_timeline'],
            temporalAwareness: $brief['temporal_awareness'],
            relativeSchedule: [
                'schedule' => $brief['schedule'],
                'schedule_summary' => $brief['schedule_summary'],
            ],
            timezoneContext: [
                'timezone' => $snapshot->timezone(),
                'source' => 'time_snapshot',
            ],
        );

        $batch = (new TemporalAgentOrchestrator(new TemporalAgentRegistry([
            new OverrunAlertAgent,
            new ShiftRiskAgent,
            new WrapUpPromptAgent,
            new UpcomingBlockPrepAgent,
        ])))->evaluate($context);

        $notifications = array_values(array_filter(
            array_map([$this, 'serializeNotification'], $deliveryService->deliver($batch)),
            static fn (array $notification): bool => $notification['state'] === TemporalNotificationDeliveryService::STATE_ACTIVE,
        ));

        return [
            'notifications' => $notifications,
            'ambient_nudge' => $notifications[0] ?? null,
        ];
    }

    /**
     * @return array{
     *   uuid: string,
     *   agent_name: string,
     *   state: string,
     *   title: string,
     *   summary: string,
     *   reason_code: string,
     *   actions: list<array{type: string, label: string, payload: array<string, mixed>, state: string}>,
     *   metadata: array<string, mixed>
     * }
     */
    private function serializeNotification(TemporalNotification $notification): array
    {
        $actions = $notification->get('actions');
        $actionStates = $notification->get('action_states');

        return [
            'uuid' => (string) $notification->get('uuid'),
            'agent_name' => (string) $notification->get('agent_name'),
            'state' => (string) $notification->get('state'),
            'title' => (string) $notification->get('title'),
            'summary' => (string) $notification->get('summary'),
            'reason_code' => (string) $notification->get('reason_code'),
            'actions' => array_map(
                static fn (array $action): array => [
                    'type' => (string) ($action['type'] ?? ''),
                    'label' => (string) ($action['label'] ?? ''),
                    'payload' => is_array($action['payload'] ?? null) ? $action['payload'] : [],
                    'state' => is_array($actionStates) ? (string) ($actionStates[$action['type'] ?? ''] ?? 'idle') : 'idle',
                ],
                is_array($actions) ? $actions : [],
            ),
            'metadata' => is_array($notification->get('metadata')) ? $notification->get('metadata') : [],
        ];
    }

    private function clockHealthMonitor(TimeSnapshot $snapshot): ClockHealthMonitor
    {
        return new ClockHealthMonitor(
            timeService: new AtomicTimeService(
                wallClock: new SystemWallClock,
                monotonicClock: new SystemMonotonicClock,
                snapshotStore: new RequestTimeSnapshotStore,
            ),
            syncProbe: new SystemClockSyncProbe,
            referenceClock: new SystemWallClock,
        );
    }
}
