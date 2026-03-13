<?php

declare(strict_types=1);

namespace Tests\Unit\Temporal\Agent;

use Claudriel\Temporal\Agent\TemporalAgentContext;
use Claudriel\Temporal\Agent\TemporalAgentSuppressionPolicy;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;

final class TemporalAgentSuppressionPolicyTest extends TestCase
{
    public function test_build_key_is_deterministic_for_same_agent_context_and_metadata(): void
    {
        $context = $this->buildContext(
            workspaceUuid: 'workspace-a',
            capturedAtUtc: '2026-03-13T14:00:00+00:00',
        );
        $policy = new TemporalAgentSuppressionPolicy(windowSeconds: 900);

        $first = $policy->describe('prep-agent', 'nudge', 'next_block_prep_window', $context, ['lead_minutes' => 15]);
        $second = $policy->describe('prep-agent', 'nudge', 'next_block_prep_window', $context, ['lead_minutes' => 15]);

        self::assertSame($first, $second);
        self::assertSame('2026-03-13T14:00:00+00:00', $first['window_starts_at']);
    }

    public function test_build_key_changes_when_scope_changes(): void
    {
        $policy = new TemporalAgentSuppressionPolicy(windowSeconds: 900);
        $left = $policy->buildKey('prep-agent', 'nudge', 'next_block_prep_window', $this->buildContext('workspace-a'));
        $right = $policy->buildKey('prep-agent', 'nudge', 'next_block_prep_window', $this->buildContext('workspace-b'));

        self::assertNotSame($left, $right);
    }

    private function buildContext(string $workspaceUuid = 'workspace-a', string $capturedAtUtc = '2026-03-13T14:05:00+00:00'): TemporalAgentContext
    {
        $utc = new \DateTimeImmutable($capturedAtUtc);

        return new TemporalAgentContext(
            tenantId: 'tenant-123',
            workspaceUuid: $workspaceUuid,
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
