<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Entity\TemporalNotification;
use Claudriel\Routing\RequestScopeViolation;
use Claudriel\Routing\TenantWorkspaceResolver;
use Claudriel\Temporal\Agent\TemporalNotificationDeliveryService;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class TemporalNotificationApiController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function dismiss(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $body = json_decode($httpRequest->getContent(), true) ?? [];
        $notification = $this->resolveNotification((string) ($params['uuid'] ?? ''), $query, $account, $httpRequest, $body);
        if (! $notification instanceof TemporalNotification) {
            return $this->json(['error' => 'Temporal notification not found.'], 404);
        }

        $updated = $this->service()->dismiss((string) $notification->get('uuid'));

        return $this->json(['notification' => $this->serialize($updated)]);
    }

    public function snooze(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $body = json_decode($httpRequest->getContent(), true) ?? [];
        $notification = $this->resolveNotification((string) ($params['uuid'] ?? ''), $query, $account, $httpRequest, $body);
        if (! $notification instanceof TemporalNotification) {
            return $this->json(['error' => 'Temporal notification not found.'], 404);
        }

        $minutes = is_numeric($body['minutes'] ?? null) ? (int) $body['minutes'] : 15;
        if ($minutes <= 0) {
            return $this->json(['error' => 'Snooze minutes must be positive.'], 422);
        }

        $updated = $this->service()->snooze(
            (string) $notification->get('uuid'),
            new \DateTimeImmutable(sprintf('+%d minutes', $minutes)),
        );

        return $this->json(['notification' => $this->serialize($updated)]);
    }

    public function updateAction(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $body = json_decode($httpRequest->getContent(), true) ?? [];
        $notification = $this->resolveNotification((string) ($params['uuid'] ?? ''), $query, $account, $httpRequest, $body);
        if (! $notification instanceof TemporalNotification) {
            return $this->json(['error' => 'Temporal notification not found.'], 404);
        }

        $state = $body['state'] ?? null;
        if (! is_string($state) || $state === '') {
            return $this->json(['error' => 'Field "state" is required.'], 422);
        }

        try {
            $updated = $this->service()->updateActionState(
                (string) $notification->get('uuid'),
                (string) ($params['action'] ?? ''),
                $state,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        return $this->json(['notification' => $this->serialize($updated)]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function resolveNotification(
        string $notificationUuid,
        array $query,
        mixed $account,
        ?Request $httpRequest,
        array $body,
    ): ?TemporalNotification {
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);

        try {
            $workspaceUuid = is_string($body['workspace_uuid'] ?? null) ? $body['workspace_uuid'] : null;
            $scope = $resolver->resolve($query, $account, $httpRequest, $body, $workspaceUuid, $workspaceUuid !== null);
        } catch (RequestScopeViolation $exception) {
            return null;
        }

        $storage = $this->entityTypeManager->getStorage('temporal_notification');
        $ids = $storage->getQuery()->condition('uuid', $notificationUuid)->execute();
        if ($ids === []) {
            return null;
        }

        $notification = $storage->load(reset($ids));
        if (! $notification instanceof TemporalNotification) {
            return null;
        }

        if ($notification->get('tenant_id') !== $scope->tenantId) {
            return null;
        }

        if ($scope->workspaceId() !== null && $notification->get('workspace_uuid') !== $scope->workspaceId()) {
            return null;
        }

        return $notification;
    }

    private function service(): TemporalNotificationDeliveryService
    {
        return new TemporalNotificationDeliveryService(
            new StorageRepositoryAdapter($this->entityTypeManager->getStorage('temporal_notification')),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(TemporalNotification $notification): array
    {
        return [
            'uuid' => $notification->get('uuid'),
            'state' => $notification->get('state'),
            'actions' => $notification->get('actions'),
            'action_states' => $notification->get('action_states'),
            'snoozed_until' => $notification->get('snoozed_until'),
        ];
    }

    private function json(mixed $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
