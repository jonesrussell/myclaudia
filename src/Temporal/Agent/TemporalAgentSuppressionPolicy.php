<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class TemporalAgentSuppressionPolicy
{
    public function __construct(
        private readonly int $windowSeconds = 900,
    ) {
        if ($this->windowSeconds <= 0) {
            throw new \InvalidArgumentException('Suppression windows must be greater than zero seconds.');
        }
    }

    public function windowSeconds(): int
    {
        return $this->windowSeconds;
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar>|null>  $metadata
     */
    public function buildKey(
        string $agentName,
        string $kind,
        string $reasonCode,
        TemporalAgentContext $context,
        array $metadata = [],
    ): string {
        $payload = [
            'agent' => $agentName,
            'kind' => $kind,
            'reason_code' => $reasonCode,
            'tenant_id' => $context->tenantId(),
            'workspace_uuid' => $context->workspaceUuid(),
            'timezone' => $context->timezoneContext()['timezone'],
            'current_block' => $context->temporalAwareness()['current_block']['title'] ?? null,
            'next_block' => $context->temporalAwareness()['next_block']['title'] ?? null,
            'schedule_summary' => $context->scheduleMetadata()['schedule_summary'],
            'metadata' => $this->normalizeMetadata($metadata),
        ];

        return sha1(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    public function windowStart(\DateTimeImmutable $capturedAtUtc): string
    {
        $windowStart = (int) floor($capturedAtUtc->getTimestamp() / $this->windowSeconds) * $this->windowSeconds;

        return (new \DateTimeImmutable('@'.$windowStart))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format(\DateTimeInterface::ATOM);
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar>|null>  $metadata
     * @return array{key: string, window_starts_at: string, window_seconds: int}
     */
    public function describe(
        string $agentName,
        string $kind,
        string $reasonCode,
        TemporalAgentContext $context,
        array $metadata = [],
    ): array {
        return [
            'key' => $this->buildKey($agentName, $kind, $reasonCode, $context, $metadata),
            'window_starts_at' => $this->windowStart($context->timeSnapshot()->utc()),
            'window_seconds' => $this->windowSeconds,
        ];
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar>|null>  $metadata
     * @return array<string, scalar|array<array-key, scalar>|null>
     */
    private function normalizeMetadata(array $metadata): array
    {
        ksort($metadata);

        return $metadata;
    }
}
