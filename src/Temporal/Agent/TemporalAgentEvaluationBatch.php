<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class TemporalAgentEvaluationBatch
{
    /**
     * @param  list<TemporalAgentDecision>  $decisions
     */
    public function __construct(
        private readonly TemporalAgentContext $context,
        private readonly array $decisions,
    ) {}

    public function context(): TemporalAgentContext
    {
        return $this->context;
    }

    /**
     * @return list<TemporalAgentDecision>
     */
    public function decisions(): array
    {
        return $this->decisions;
    }

    /**
     * @return array{
     *   tenant_id: string,
     *   workspace_uuid: ?string,
     *   evaluated_at: string,
     *   timezone: string,
     *   emitted_count: int,
     *   suppressed_count: int,
     *   decisions: list<array{
     *     agent: string,
     *     state: string,
     *     kind: string,
     *     title: string,
     *     summary: string,
     *     reason_code: string,
     *     actions: list<array{
     *       type: string,
     *       label: string,
     *       payload: array<string, scalar|array<array-key, scalar>|null>
     *     }>,
     *     metadata: array<string, scalar|array<array-key, scalar>|null>,
     *     suppression: array{key: string, window_starts_at: string, window_seconds: int}
     *   }>
     * }
     */
    public function toArray(): array
    {
        $serialized = array_map(
            static fn (TemporalAgentDecision $decision): array => $decision->toArray(),
            $this->decisions,
        );

        $emittedCount = count(array_filter(
            $serialized,
            static fn (array $decision): bool => $decision['state'] === TemporalAgentLifecycle::EMITTED,
        ));

        return [
            'tenant_id' => $this->context->tenantId(),
            'workspace_uuid' => $this->context->workspaceUuid(),
            'evaluated_at' => $this->context->timeSnapshot()->utc()->format(\DateTimeInterface::ATOM),
            'timezone' => $this->context->timezoneContext()['timezone'],
            'emitted_count' => $emittedCount,
            'suppressed_count' => count($serialized) - $emittedCount,
            'decisions' => $serialized,
        ];
    }
}
