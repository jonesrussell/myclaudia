<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class TemporalAgentEvaluationLedger
{
    /** @var array<string, string> */
    private array $seen = [];

    public function duplicateOf(TemporalAgentDecision $decision): ?string
    {
        $scopeKey = $this->scopeKey($decision);

        return $this->seen[$scopeKey] ?? null;
    }

    public function remember(TemporalAgentDecision $decision): void
    {
        $this->seen[$this->scopeKey($decision)] = $decision->agentName();
    }

    private function scopeKey(TemporalAgentDecision $decision): string
    {
        return $decision->suppressionWindowStartsAt().'|'.$decision->suppressionKey();
    }
}
