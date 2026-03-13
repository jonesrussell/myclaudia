<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class ShiftRiskAgent implements TemporalAgentInterface
{
    public function __construct(
        private readonly int $minimumOverrunMinutes = 5,
        private readonly int $riskLeadWindowMinutes = 15,
        private readonly TemporalAgentSuppressionPolicy $suppressionPolicy = new TemporalAgentSuppressionPolicy,
    ) {
        if ($this->minimumOverrunMinutes <= 0 || $this->riskLeadWindowMinutes <= 0) {
            throw new \InvalidArgumentException('Shift-risk agents require positive minute thresholds.');
        }
    }

    public function name(): string
    {
        return 'shift-risk';
    }

    public function evaluate(TemporalAgentContext $context): TemporalAgentDecision
    {
        $overrun = $context->temporalAwareness()['overruns'][0] ?? null;
        if (! is_array($overrun)) {
            return TemporalAgentDecision::suppress(
                agentName: $this->name(),
                kind: 'nudge',
                reasonCode: 'no_active_overrun',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
            );
        }

        if ($overrun['overrun_minutes'] < $this->minimumOverrunMinutes) {
            return TemporalAgentDecision::suppress(
                agentName: $this->name(),
                kind: 'nudge',
                reasonCode: 'below_shift_risk_threshold',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
                metadata: ['overrun_minutes' => $overrun['overrun_minutes']],
            );
        }

        $nextBlock = $context->temporalAwareness()['next_block'] ?? null;
        if (! is_array($nextBlock)) {
            return TemporalAgentDecision::suppress(
                agentName: $this->name(),
                kind: 'nudge',
                reasonCode: 'no_downstream_block',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
            );
        }

        $minutesUntilNextBlock = (int) floor(
            ((new \DateTimeImmutable($nextBlock['start_time']))->getTimestamp() - $context->timeSnapshot()->local()->getTimestamp()) / 60,
        );

        if ($minutesUntilNextBlock > $this->riskLeadWindowMinutes) {
            return TemporalAgentDecision::suppress(
                agentName: $this->name(),
                kind: 'nudge',
                reasonCode: 'downstream_block_not_at_risk',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
                metadata: [
                    'minutes_until_next_block' => $minutesUntilNextBlock,
                    'next_block_title' => $nextBlock['title'],
                ],
            );
        }

        return TemporalAgentDecision::emit(
            agentName: $this->name(),
            kind: 'nudge',
            title: 'Shift the next block intentionally',
            summary: sprintf(
                '"%s" is %d minutes over and "%s" starts in %d minutes.',
                $overrun['title'],
                $overrun['overrun_minutes'],
                $nextBlock['title'],
                max($minutesUntilNextBlock, 0),
            ),
            reasonCode: 'downstream_schedule_risk',
            context: $context,
            suppressionPolicy: $this->suppressionPolicy,
            actions: [
                new TemporalAgentAction('open_chat', 'Re-plan now', [
                    'prompt' => sprintf(
                        'Help me shift my schedule because %s is overrunning and %s starts in %d minutes.',
                        $overrun['title'],
                        $nextBlock['title'],
                        max($minutesUntilNextBlock, 0),
                    ),
                    'overrun_title' => $overrun['title'],
                    'next_block_title' => $nextBlock['title'],
                    'minutes_until_next_block' => $minutesUntilNextBlock,
                ]),
            ],
            metadata: [
                'overrun_title' => $overrun['title'],
                'overrun_minutes' => $overrun['overrun_minutes'],
                'next_block_title' => $nextBlock['title'],
                'minutes_until_next_block' => $minutesUntilNextBlock,
            ],
        );
    }
}
