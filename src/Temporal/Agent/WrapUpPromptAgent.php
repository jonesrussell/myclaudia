<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class WrapUpPromptAgent implements TemporalAgentInterface
{
    public function __construct(
        private readonly int $postBlockWindowMinutes = 10,
        private readonly int $shiftRiskLeadWindowMinutes = 15,
        private readonly TemporalAgentSuppressionPolicy $suppressionPolicy = new TemporalAgentSuppressionPolicy,
    ) {
        if ($this->postBlockWindowMinutes <= 0 || $this->shiftRiskLeadWindowMinutes <= 0) {
            throw new \InvalidArgumentException('Wrap-up prompts require positive timing windows.');
        }
    }

    public function name(): string
    {
        return 'wrap-up-prompt';
    }

    public function evaluate(TemporalAgentContext $context): TemporalAgentDecision
    {
        $overrun = $context->temporalAwareness()['overruns'][0] ?? null;
        if (! is_array($overrun)) {
            return TemporalAgentDecision::suppress(
                agentName: $this->name(),
                kind: 'nudge',
                reasonCode: 'no_recent_block_end',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
            );
        }

        if ($overrun['overrun_minutes'] > $this->postBlockWindowMinutes) {
            return TemporalAgentDecision::suppress(
                agentName: $this->name(),
                kind: 'nudge',
                reasonCode: 'post_block_window_elapsed',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
                metadata: ['overrun_minutes' => $overrun['overrun_minutes']],
            );
        }

        $nextBlock = $context->temporalAwareness()['next_block'] ?? null;
        if (is_array($nextBlock)) {
            $minutesUntilNextBlock = (int) floor(
                ((new \DateTimeImmutable($nextBlock['start_time']))->getTimestamp() - $context->timeSnapshot()->local()->getTimestamp()) / 60,
            );

            if ($minutesUntilNextBlock <= $this->shiftRiskLeadWindowMinutes) {
                return TemporalAgentDecision::suppress(
                    agentName: $this->name(),
                    kind: 'nudge',
                    reasonCode: 'downstream_risk_requires_shift',
                    context: $context,
                    suppressionPolicy: $this->suppressionPolicy,
                    metadata: [
                        'minutes_until_next_block' => $minutesUntilNextBlock,
                        'next_block_title' => $nextBlock['title'],
                    ],
                );
            }
        }

        return TemporalAgentDecision::emit(
            agentName: $this->name(),
            kind: 'nudge',
            title: 'Wrap up the last block',
            summary: sprintf(
                '"%s" ended %d minutes ago. Capture closure before you move on.',
                $overrun['title'],
                $overrun['overrun_minutes'],
            ),
            reasonCode: 'post_block_wrap_up_window',
            context: $context,
            suppressionPolicy: $this->suppressionPolicy,
            actions: [
                new TemporalAgentAction('open_chat', 'Close it out', [
                    'prompt' => sprintf(
                        'Help me wrap up %s now that it ended %d minutes ago.',
                        $overrun['title'],
                        $overrun['overrun_minutes'],
                    ),
                    'overrun_title' => $overrun['title'],
                    'overrun_minutes' => $overrun['overrun_minutes'],
                ]),
            ],
            metadata: [
                'overrun_title' => $overrun['title'],
                'overrun_minutes' => $overrun['overrun_minutes'],
                'ended_at' => $overrun['ended_at'],
            ],
        );
    }
}
