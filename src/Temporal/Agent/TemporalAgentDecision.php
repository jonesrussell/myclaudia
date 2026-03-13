<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class TemporalAgentDecision
{
    /**
     * @param  list<TemporalAgentAction>  $actions
     * @param  array<string, scalar|array<array-key, scalar>|null>  $metadata
     * @param  array{key: string, window_starts_at: string, window_seconds: int}  $suppression
     */
    private function __construct(
        private readonly string $agentName,
        private readonly string $state,
        private readonly string $kind,
        private readonly string $title,
        private readonly string $summary,
        private readonly string $reasonCode,
        private readonly array $actions,
        private readonly array $metadata,
        private readonly array $suppression,
    ) {
        TemporalAgentLifecycle::assertValid($this->state);

        if ($this->agentName === '' || $this->kind === '' || $this->reasonCode === '') {
            throw new \InvalidArgumentException('Temporal agent decisions require an agent name, kind, and reason code.');
        }
    }

    /**
     * @param  list<TemporalAgentAction>  $actions
     * @param  array<string, scalar|array<array-key, scalar>|null>  $metadata
     */
    public static function emit(
        string $agentName,
        string $kind,
        string $title,
        string $summary,
        string $reasonCode,
        TemporalAgentContext $context,
        TemporalAgentSuppressionPolicy $suppressionPolicy,
        array $actions = [],
        array $metadata = [],
    ): self {
        return new self(
            $agentName,
            TemporalAgentLifecycle::EMITTED,
            $kind,
            $title,
            $summary,
            $reasonCode,
            $actions,
            $metadata,
            $suppressionPolicy->describe($agentName, $kind, $reasonCode, $context, $metadata),
        );
    }

    /**
     * @param  array<string, scalar|array<array-key, scalar>|null>  $metadata
     */
    public static function suppress(
        string $agentName,
        string $kind,
        string $reasonCode,
        TemporalAgentContext $context,
        TemporalAgentSuppressionPolicy $suppressionPolicy,
        array $metadata = [],
    ): self {
        return new self(
            $agentName,
            TemporalAgentLifecycle::SUPPRESSED,
            $kind,
            'Suppressed',
            'Temporal agent output was suppressed deterministically for the current evaluation window.',
            $reasonCode,
            [],
            $metadata,
            $suppressionPolicy->describe($agentName, $kind, $reasonCode, $context, $metadata),
        );
    }

    public static function suppressDuplicate(self $decision, string $duplicateOf): self
    {
        return new self(
            $decision->agentName,
            TemporalAgentLifecycle::SUPPRESSED,
            $decision->kind,
            'Suppressed',
            'Temporal agent output was suppressed deterministically for the current evaluation window.',
            'duplicate_within_window',
            [],
            ['duplicate_of' => $duplicateOf] + $decision->metadata,
            $decision->suppression,
        );
    }

    public function agentName(): string
    {
        return $this->agentName;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function suppressionKey(): string
    {
        return $this->suppression['key'];
    }

    public function suppressionWindowStartsAt(): string
    {
        return $this->suppression['window_starts_at'];
    }

    /**
     * @return array{
     *   agent: string,
     *   state: string,
     *   kind: string,
     *   title: string,
     *   summary: string,
     *   reason_code: string,
     *   actions: list<array{
     *     type: string,
     *     label: string,
     *     payload: array<string, scalar|array<array-key, scalar>|null>
     *   }>,
     *   metadata: array<string, scalar|array<array-key, scalar>|null>,
     *   suppression: array{key: string, window_starts_at: string, window_seconds: int}
     * }
     */
    public function toArray(): array
    {
        return [
            'agent' => $this->agentName,
            'state' => $this->state,
            'kind' => $this->kind,
            'title' => $this->title,
            'summary' => $this->summary,
            'reason_code' => $this->reasonCode,
            'actions' => array_map(
                static fn (TemporalAgentAction $action): array => $action->toArray(),
                $this->actions,
            ),
            'metadata' => $this->metadata,
            'suppression' => $this->suppression,
        ];
    }
}
