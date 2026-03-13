<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

interface TemporalAgentInterface
{
    public function name(): string;

    public function evaluate(TemporalAgentContext $context): TemporalAgentDecision;
}
