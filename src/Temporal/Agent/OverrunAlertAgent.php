<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

final class OverrunAlertAgent implements TemporalAgentInterface
{
    public function __construct(
        private readonly int $minimumOverrunMinutes = 5,
        private readonly TemporalAgentSuppressionPolicy $suppressionPolicy = new TemporalAgentSuppressionPolicy,
    ) {
        if ($this->minimumOverrunMinutes <= 0) {
            throw new \InvalidArgumentException('Overrun alerts require a positive minute threshold.');
        }
    }

    public function name(): string
    {
        return 'overrun-alert';
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
                reasonCode: 'below_overrun_threshold',
                context: $context,
                suppressionPolicy: $this->suppressionPolicy,
                metadata: [
                    'overrun_minutes' => $overrun['overrun_minutes'],
                    'overrun_title' => $overrun['title'],
                ],
            );
        }

        return TemporalAgentDecision::emit(
            agentName: $this->name(),
            kind: 'nudge',
            title: 'Resolve the active overrun',
            summary: sprintf(
                '"%s" has run %d minutes past its scheduled end.',
                $overrun['title'],
                $overrun['overrun_minutes'],
            ),
            reasonCode: 'active_overrun',
            context: $context,
            suppressionPolicy: $this->suppressionPolicy,
            actions: [
                new TemporalAgentAction('open_chat', 'Recover in chat', [
                    'prompt' => sprintf(
                        'Help me recover from %s overrunning by %d minutes.',
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
