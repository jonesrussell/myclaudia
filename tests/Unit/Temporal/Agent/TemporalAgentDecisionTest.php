<?php

declare(strict_types=1);

namespace Tests\Unit\Temporal\Agent;

use Claudriel\Temporal\Agent\TemporalAgentAction;
use Claudriel\Temporal\Agent\TemporalAgentContext;
use Claudriel\Temporal\Agent\TemporalAgentDecision;
use Claudriel\Temporal\Agent\TemporalAgentSuppressionPolicy;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;

final class TemporalAgentDecisionTest extends TestCase
{
    public function test_emit_supports_nudges_suggested_actions_and_machine_readable_reason_codes(): void
    {
        $context = $this->buildContext();
        $decision = TemporalAgentDecision::emit(
            agentName: 'prep-agent',
            kind: 'nudge',
            title: 'Prepare for Planning',
            summary: 'Planning starts in 15 minutes.',
            reasonCode: 'next_block_prep_window',
            context: $context,
            suppressionPolicy: new TemporalAgentSuppressionPolicy(windowSeconds: 900),
            actions: [
                new TemporalAgentAction('open_chat', 'Open planning prep', ['prompt' => 'Prep me for Planning']),
            ],
            metadata: ['lead_minutes' => 15],
        );

        self::assertSame([
            'agent' => 'prep-agent',
            'state' => 'emitted',
            'kind' => 'nudge',
            'title' => 'Prepare for Planning',
            'summary' => 'Planning starts in 15 minutes.',
            'reason_code' => 'next_block_prep_window',
            'actions' => [[
                'type' => 'open_chat',
                'label' => 'Open planning prep',
                'payload' => ['prompt' => 'Prep me for Planning'],
            ]],
            'metadata' => ['lead_minutes' => 15],
            'suppression' => [
                'key' => $decision->toArray()['suppression']['key'],
                'window_starts_at' => '2026-03-13T14:00:00+00:00',
                'window_seconds' => 900,
            ],
        ], $decision->toArray());
    }

    public function test_suppressed_decision_uses_suppression_lifecycle_state(): void
    {
        $decision = TemporalAgentDecision::suppress(
            agentName: 'prep-agent',
            kind: 'nudge',
            reasonCode: 'duplicate_within_window',
            context: $this->buildContext(),
            suppressionPolicy: new TemporalAgentSuppressionPolicy(windowSeconds: 900),
            metadata: ['duplicate_of' => 'prep-agent'],
        );

        self::assertSame('suppressed', $decision->toArray()['state']);
        self::assertSame('duplicate_within_window', $decision->toArray()['reason_code']);
        self::assertSame([], $decision->toArray()['actions']);
    }

    private function buildContext(): TemporalAgentContext
    {
        $utc = new \DateTimeImmutable('2026-03-13T14:10:00+00:00');

        return new TemporalAgentContext(
            tenantId: 'tenant-123',
            workspaceUuid: 'workspace-a',
            timeSnapshot: new TimeSnapshot(
                $utc,
                $utc->setTimezone(new \DateTimeZone('America/Toronto')),
                42,
                'America/Toronto',
            ),
            temporalAwareness: [
                'current_block' => null,
                'next_block' => [
                    'title' => 'Planning',
                    'start_time' => '2026-03-13T11:00:00-04:00',
                    'end_time' => '2026-03-13T12:00:00-04:00',
                    'source' => 'google-calendar',
                ],
                'gaps' => [],
                'overruns' => [],
            ],
            clockHealth: [
                'provider' => 'timedatectl',
                'synchronized' => true,
                'reference_source' => 'system-wall-clock',
                'drift_seconds' => 0.0,
                'threshold_seconds' => 5,
                'state' => 'healthy',
                'safe_for_temporal_reasoning' => true,
                'retry_after_seconds' => 30,
                'fallback_mode' => 'none',
                'metadata' => [],
            ],
            scheduleMetadata: [
                'schedule' => [],
                'schedule_summary' => '',
                'has_clear_day' => false,
            ],
            timezoneContext: [
                'timezone' => 'America/Toronto',
                'source' => 'workspace',
            ],
        );
    }
}
