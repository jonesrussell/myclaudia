<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class TemporalAgentOrchestrator
{
    public function __construct(
        private readonly TemporalAgentRegistry $registry,
        private readonly ?TemporalAgentEvaluationLedger $ledger = null,
    ) {}

    public function evaluate(TemporalAgentContext $context): TemporalAgentEvaluationBatch
    {
        $ledger = $this->ledger ?? new TemporalAgentEvaluationLedger;
        $decisions = [];

        foreach ($this->registry->all() as $agent) {
            $decision = $agent->evaluate($context);
            if ($decision->state() === TemporalAgentLifecycle::EMITTED) {
                $duplicateOf = $ledger->duplicateOf($decision);
                if ($duplicateOf !== null) {
                    $decision = TemporalAgentDecision::suppressDuplicate($decision, $duplicateOf);
                } else {
                    $ledger->remember($decision);
                }
            }

            $decisions[] = $decision;
        }

        return new TemporalAgentEvaluationBatch($context, $decisions);
    }
}
