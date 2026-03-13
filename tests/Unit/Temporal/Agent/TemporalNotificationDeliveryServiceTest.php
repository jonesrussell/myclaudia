<?php

declare(strict_types=1);

namespace Tests\Unit\Temporal\Agent;

use Claudriel\Entity\TemporalNotification;
use Claudriel\Temporal\Agent\OverrunAlertAgent;
use Claudriel\Temporal\Agent\ShiftRiskAgent;
use Claudriel\Temporal\Agent\TemporalAgentContext;
use Claudriel\Temporal\Agent\TemporalAgentEvaluationBatch;
use Claudriel\Temporal\Agent\TemporalAgentOrchestrator;
use Claudriel\Temporal\Agent\TemporalAgentRegistry;
use Claudriel\Temporal\Agent\TemporalNotificationDeliveryService;
use Claudriel\Temporal\Agent\UpcomingBlockPrepAgent;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class TemporalNotificationDeliveryServiceTest extends TestCase
{
    private EntityRepository $notificationRepository;

    private TemporalNotificationDeliveryService $service;

    protected function setUp(): void
    {
        $this->notificationRepository = new EntityRepository(
            new EntityType(id: 'temporal_notification', label: 'Temporal Notification', class: TemporalNotification::class, keys: ['id' => 'tnid', 'uuid' => 'uuid']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
        $this->service = new TemporalNotificationDeliveryService($this->notificationRepository);
    }

    public function test_deliver_persists_active_notifications_with_tenant_and_workspace_scope(): void
    {
        $batch = $this->buildPrepBatch();

        $notifications = $this->service->deliver($batch);

        self::assertCount(1, $notifications);
        self::assertSame('tenant-123', $notifications[0]->get('tenant_id'));
        self::assertSame('workspace-a', $notifications[0]->get('workspace_uuid'));
        self::assertSame('active', $notifications[0]->get('state'));
    }

    public function test_deliver_coalesces_overlapping_outputs_deterministically(): void
    {
        $context = $this->buildContext(
            nowLocal: '2026-03-13T11:15:00-04:00',
            nextBlockStart: '2026-03-13T11:20:00-04:00',
            overrunMinutes: 15,
        );
        $batch = (new TemporalAgentOrchestrator(new TemporalAgentRegistry([
            new ShiftRiskAgent(minimumOverrunMinutes: 5, riskLeadWindowMinutes: 15),
            new OverrunAlertAgent(minimumOverrunMinutes: 5),
        ])))->evaluate($context);

        $notifications = $this->service->deliver($batch);

        self::assertCount(1, $notifications);
        self::assertSame('overrun-alert', $notifications[0]->get('agent_name'));
        self::assertSame('Resolve the active overrun', $notifications[0]->get('title'));
    }

    public function test_dismiss_and_snooze_preserve_notification_state_across_future_deliveries(): void
    {
        $batch = $this->buildPrepBatch();
        $notification = $this->service->deliver($batch)[0];

        $snoozed = $this->service->snooze(
            $notification->get('uuid'),
            new \DateTimeImmutable('2026-03-13T14:40:00+00:00'),
        );
        $redelivered = $this->service->deliver($batch)[0];

        self::assertSame('snoozed', $snoozed->get('state'));
        self::assertSame($notification->get('uuid'), $redelivered->get('uuid'));
        self::assertSame('snoozed', $redelivered->get('state'));

        $dismissed = $this->service->dismiss($notification->get('uuid'));
        $redeliveredAfterDismiss = $this->service->deliver($batch)[0];

        self::assertSame('dismissed', $dismissed->get('state'));
        self::assertSame($notification->get('uuid'), $redeliveredAfterDismiss->get('uuid'));
        self::assertSame('dismissed', $redeliveredAfterDismiss->get('state'));
    }

    public function test_update_action_state_and_expire_stale_notifications(): void
    {
        $notification = $this->service->deliver($this->buildPrepBatch())[0];

        $updated = $this->service->updateActionState($notification->get('uuid'), 'open_chat', 'working');
        self::assertSame('working', $updated->get('action_states')['open_chat']);

        $this->service->expireStale(new \DateTimeImmutable('2026-03-13T14:46:00+00:00'));
        $expired = $this->notificationRepository->findBy(['uuid' => $notification->get('uuid')])[0];
        self::assertInstanceOf(TemporalNotification::class, $expired);

        self::assertSame('expired', $expired->get('state'));
    }

    private function buildPrepBatch(): TemporalAgentEvaluationBatch
    {
        return (new TemporalAgentOrchestrator(new TemporalAgentRegistry([
            new UpcomingBlockPrepAgent(leadWindowMinutes: 30),
        ])))->evaluate($this->buildContext(
            nowLocal: '2026-03-13T10:30:00-04:00',
            nextBlockStart: '2026-03-13T10:50:00-04:00',
            gapBeforeNextBlockMinutes: 10,
        ));
    }

    private function buildContext(
        string $nowLocal,
        ?string $nextBlockStart = null,
        int $gapBeforeNextBlockMinutes = 0,
        ?int $overrunMinutes = null,
    ): TemporalAgentContext {
        $local = new \DateTimeImmutable($nowLocal);
        $utc = $local->setTimezone(new \DateTimeZone('UTC'));
        $gaps = [];

        if ($nextBlockStart !== null && $gapBeforeNextBlockMinutes > 0) {
            $nextBlock = new \DateTimeImmutable($nextBlockStart);
            $gaps[] = [
                'starts_at' => $nextBlock->modify(sprintf('-%d minutes', $gapBeforeNextBlockMinutes))->format(\DateTimeInterface::ATOM),
                'ends_at' => $nextBlock->format(\DateTimeInterface::ATOM),
                'duration_minutes' => $gapBeforeNextBlockMinutes,
                'between' => [
                    'from' => 'Deep Work',
                    'to' => 'Planning',
                ],
            ];
        }

        $overruns = [];
        if ($overrunMinutes !== null) {
            $overruns[] = [
                'title' => 'Design Review',
                'ended_at' => $local->modify(sprintf('-%d minutes', $overrunMinutes))->format(\DateTimeInterface::ATOM),
                'overrun_minutes' => $overrunMinutes,
            ];
        }

        return new TemporalAgentContext(
            tenantId: 'tenant-123',
            workspaceUuid: 'workspace-a',
            timeSnapshot: new TimeSnapshot(
                $utc,
                $local,
                42,
                'America/Toronto',
            ),
            temporalAwareness: [
                'current_block' => null,
                'next_block' => $nextBlockStart !== null ? [
                    'title' => $overrunMinutes === null ? 'Planning' : 'Client Call',
                    'start_time' => $nextBlockStart,
                    'end_time' => (new \DateTimeImmutable($nextBlockStart))->modify('+45 minutes')->format(\DateTimeInterface::ATOM),
                    'source' => 'google-calendar',
                ] : null,
                'gaps' => $gaps,
                'overruns' => $overruns,
            ],
            clockHealth: [
                'provider' => 'timedatectl',
                'synchronized' => true,
                'reference_source' => 'system-wall-clock',
                'drift_seconds' => 0.0,
                'threshold_seconds' => 5,
                'state' => 'healthy',
                'safe_for_temporal_reasoning' => true,
                'retry_after_seconds' => 30,
                'fallback_mode' => 'none',
                'metadata' => [],
            ],
            scheduleMetadata: [
                'schedule' => [],
                'schedule_summary' => '',
                'has_clear_day' => false,
            ],
            timezoneContext: [
                'timezone' => 'America/Toronto',
                'source' => 'workspace',
            ],
        );
    }
}
