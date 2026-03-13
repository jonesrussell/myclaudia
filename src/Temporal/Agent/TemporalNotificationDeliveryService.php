<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

use Claudriel\Entity\TemporalNotification;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class TemporalNotificationDeliveryService
{
    public const STATE_ACTIVE = 'active';

    public const STATE_DISMISSED = 'dismissed';

    public const STATE_SNOOZED = 'snoozed';

    public const STATE_EXPIRED = 'expired';

    /** @var array<string, int> */
    private const PRIORITY_BY_AGENT = [
        'overrun-alert' => 400,
        'shift-risk' => 300,
        'wrap-up-prompt' => 200,
        'upcoming-block-prep' => 100,
    ];

    /** @var list<string> */
    private const ACTION_STATES = ['idle', 'working', 'complete', 'failed'];

    public function __construct(
        private readonly EntityRepositoryInterface $notificationRepository,
    ) {}

    /**
     * @return list<TemporalNotification>
     */
    public function deliver(TemporalAgentEvaluationBatch $batch): array
    {
        $evaluatedAt = new \DateTimeImmutable($batch->toArray()['evaluated_at']);
        $this->expireStale($evaluatedAt);

        $notifications = [];

        foreach ($this->coalesce($batch->decisions()) as $decision) {
            $existing = $this->findByDeliveryKey(
                tenantId: $batch->context()->tenantId(),
                workspaceUuid: $batch->context()->workspaceUuid(),
                deliveryKey: $decision->suppressionKey(),
            );

            if ($existing instanceof TemporalNotification) {
                $notifications[] = $this->refreshExistingNotification($existing, $decision, $evaluatedAt);

                continue;
            }

            $notification = new TemporalNotification($this->notificationPayload($batch, $decision, $evaluatedAt));
            $this->notificationRepository->save($notification);
            $notifications[] = $notification;
        }

        return $notifications;
    }

    public function dismiss(string $notificationUuid): TemporalNotification
    {
        $notification = $this->requireNotification($notificationUuid);
        $notification->set('state', self::STATE_DISMISSED);
        $notification->set('dismissed_at', (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM));
        $this->notificationRepository->save($notification);

        return $notification;
    }

    public function snooze(string $notificationUuid, \DateTimeImmutable $until): TemporalNotification
    {
        $notification = $this->requireNotification($notificationUuid);
        $notification->set('state', self::STATE_SNOOZED);
        $notification->set('snoozed_until', $until->format(\DateTimeInterface::ATOM));
        $this->notificationRepository->save($notification);

        return $notification;
    }

    public function updateActionState(string $notificationUuid, string $actionType, string $state): TemporalNotification
    {
        if (! in_array($state, self::ACTION_STATES, true)) {
            throw new \InvalidArgumentException(sprintf('Invalid temporal notification action state "%s".', $state));
        }

        $notification = $this->requireNotification($notificationUuid);
        $actionStates = $notification->get('action_states') ?? [];
        $actionStates[$actionType] = $state;
        $notification->set('action_states', $actionStates);
        $this->notificationRepository->save($notification);

        return $notification;
    }

    public function expireStale(\DateTimeImmutable $now): void
    {
        foreach ($this->notificationRepository->findBy([]) as $notification) {
            if (! $notification instanceof TemporalNotification) {
                continue;
            }

            $expiresAt = $notification->get('expires_at');
            if (! is_string($expiresAt)) {
                continue;
            }

            if ((new \DateTimeImmutable($expiresAt)) > $now) {
                continue;
            }

            if ($notification->get('state') === self::STATE_EXPIRED) {
                continue;
            }

            $notification->set('state', self::STATE_EXPIRED);
            $this->notificationRepository->save($notification);
        }
    }

    /**
     * @param  list<TemporalAgentDecision>  $decisions
     * @return list<TemporalAgentDecision>
     */
    private function coalesce(array $decisions): array
    {
        $emitted = array_values(array_filter(
            $decisions,
            static fn (TemporalAgentDecision $decision): bool => $decision->state() === TemporalAgentLifecycle::EMITTED,
        ));

        usort($emitted, function (TemporalAgentDecision $left, TemporalAgentDecision $right): int {
            $priorityComparison = $this->priority($right->agentName()) <=> $this->priority($left->agentName());
            if ($priorityComparison !== 0) {
                return $priorityComparison;
            }

            return strcmp($left->agentName(), $right->agentName());
        });

        $coalesced = [];

        foreach ($emitted as $decision) {
            $scope = $this->overlapScope($decision);
            if (isset($coalesced[$scope])) {
                continue;
            }

            $coalesced[$scope] = $decision;
        }

        return array_values($coalesced);
    }

    private function priority(string $agentName): int
    {
        return self::PRIORITY_BY_AGENT[$agentName] ?? 0;
    }

    private function overlapScope(TemporalAgentDecision $decision): string
    {
        $serialized = $decision->toArray();
        $metadata = $serialized['metadata'];

        if (is_string($metadata['overrun_title'] ?? null)) {
            return 'overrun:'.$metadata['overrun_title'];
        }

        if (is_string($metadata['next_block_title'] ?? null)) {
            return 'next-block:'.$metadata['next_block_title'].':'.(string) ($metadata['next_block_starts_at'] ?? 'unknown');
        }

        return 'decision:'.$decision->suppressionKey();
    }

    private function refreshExistingNotification(
        TemporalNotification $notification,
        TemporalAgentDecision $decision,
        \DateTimeImmutable $evaluatedAt,
    ): TemporalNotification {
        $state = $notification->get('state');
        if ($state === self::STATE_DISMISSED) {
            return $notification;
        }

        if ($state === self::STATE_SNOOZED) {
            $snoozedUntil = $notification->get('snoozed_until');
            if (is_string($snoozedUntil) && (new \DateTimeImmutable($snoozedUntil)) > $evaluatedAt) {
                return $notification;
            }
        }

        foreach ($this->notificationPayloadFromExisting($notification, $decision, $evaluatedAt) as $field => $value) {
            $notification->set($field, $value);
        }

        $this->notificationRepository->save($notification);

        return $notification;
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationPayload(
        TemporalAgentEvaluationBatch $batch,
        TemporalAgentDecision $decision,
        \DateTimeImmutable $evaluatedAt,
    ): array {
        $serialized = $decision->toArray();
        $expiresAt = (new \DateTimeImmutable($serialized['suppression']['window_starts_at']))
            ->modify(sprintf('+%d seconds', $serialized['suppression']['window_seconds']));

        return [
            'uuid' => bin2hex(random_bytes(16)),
            'tenant_id' => $batch->context()->tenantId(),
            'workspace_uuid' => $batch->context()->workspaceUuid(),
            'delivery_key' => $serialized['suppression']['key'],
            'overlap_scope' => $this->overlapScope($decision),
            'agent_name' => $serialized['agent'],
            'kind' => $serialized['kind'],
            'title' => $serialized['title'],
            'summary' => $serialized['summary'],
            'reason_code' => $serialized['reason_code'],
            'actions' => $serialized['actions'],
            'action_states' => $this->initialActionStates($serialized['actions']),
            'metadata' => $serialized['metadata'],
            'delivered_at' => $evaluatedAt->format(\DateTimeInterface::ATOM),
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
            'state' => self::STATE_ACTIVE,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function notificationPayloadFromExisting(
        TemporalNotification $notification,
        TemporalAgentDecision $decision,
        \DateTimeImmutable $evaluatedAt,
    ): array {
        $serialized = $decision->toArray();
        $expiresAt = (new \DateTimeImmutable($serialized['suppression']['window_starts_at']))
            ->modify(sprintf('+%d seconds', $serialized['suppression']['window_seconds']));

        return [
            'overlap_scope' => $this->overlapScope($decision),
            'agent_name' => $serialized['agent'],
            'kind' => $serialized['kind'],
            'title' => $serialized['title'],
            'summary' => $serialized['summary'],
            'reason_code' => $serialized['reason_code'],
            'actions' => $serialized['actions'],
            'action_states' => $notification->get('action_states') ?: $this->initialActionStates($serialized['actions']),
            'metadata' => $serialized['metadata'],
            'delivered_at' => $evaluatedAt->format(\DateTimeInterface::ATOM),
            'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
            'snoozed_until' => null,
            'state' => self::STATE_ACTIVE,
        ];
    }

    /**
     * @param  list<array{type: string, label: string, payload: array<string, scalar|array<array-key, scalar>|null>}>  $actions
     * @return array<string, string>
     */
    private function initialActionStates(array $actions): array
    {
        $states = [];

        foreach ($actions as $action) {
            $states[$action['type']] = 'idle';
        }

        return $states;
    }

    private function requireNotification(string $notificationUuid): TemporalNotification
    {
        $match = $this->notificationRepository->findBy(['uuid' => $notificationUuid])[0] ?? null;

        if (! $match instanceof TemporalNotification) {
            throw new \InvalidArgumentException(sprintf('Temporal notification "%s" was not found.', $notificationUuid));
        }

        return $match;
    }

    private function findByDeliveryKey(string $tenantId, ?string $workspaceUuid, string $deliveryKey): ?TemporalNotification
    {
        foreach ($this->notificationRepository->findBy(['tenant_id' => $tenantId]) as $notification) {
            if (! $notification instanceof TemporalNotification) {
                continue;
            }

            if ($notification->get('workspace_uuid') !== $workspaceUuid) {
                continue;
            }

            if ($notification->get('delivery_key') !== $deliveryKey) {
                continue;
            }

            return $notification;
        }

        return null;
    }
}
