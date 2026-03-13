<?php

declare(strict_types=1);

namespace Tests\Unit\Temporal\Agent;

use Claudriel\Temporal\Agent\TemporalAgentContext;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;

final class TemporalAgentContextTest extends TestCase
{
    public function test_to_array_exposes_canonical_temporal_agent_input_envelope(): void
    {
        $context = new TemporalAgentContext(
            tenantId: 'tenant-123',
            workspaceUuid: 'workspace-abc',
            timeSnapshot: new TimeSnapshot(
                new \DateTimeImmutable('2026-03-13T14:00:00+00:00'),
                new \DateTimeImmutable('2026-03-13T10:00:00-04:00'),
                4242,
                'America/Toronto',
            ),
            temporalAwareness: [
                'current_block' => [
                    'title' => 'Standup',
                    'start_time' => '2026-03-13T09:30:00-04:00',
                    'end_time' => '2026-03-13T10:30:00-04:00',
                    'source' => 'google-calendar',
                ],
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
                'drift_seconds' => 0.2,
                'threshold_seconds' => 5,
                'state' => 'healthy',
                'safe_for_temporal_reasoning' => true,
                'retry_after_seconds' => 30,
                'fallback_mode' => 'none',
                'metadata' => ['timezone' => 'America/Toronto'],
            ],
            scheduleMetadata: [
                'schedule' => [[
                    'title' => 'Standup',
                    'start_time' => '2026-03-13T09:30:00-04:00',
                    'end_time' => '2026-03-13T10:30:00-04:00',
                    'source' => 'google-calendar',
                ]],
                'schedule_summary' => '',
                'has_clear_day' => false,
            ],
            timezoneContext: [
                'timezone' => 'America/Toronto',
                'source' => 'workspace',
            ],
        );

        self::assertSame([
            'tenant_id' => 'tenant-123',
            'workspace_uuid' => 'workspace-abc',
            'time_snapshot' => [
                'utc' => '2026-03-13T14:00:00+00:00',
                'local' => '2026-03-13T10:00:00-04:00',
                'timezone' => 'America/Toronto',
                'monotonic_ns' => 4242,
            ],
            'temporal_awareness' => [
                'current_block' => [
                    'title' => 'Standup',
                    'start_time' => '2026-03-13T09:30:00-04:00',
                    'end_time' => '2026-03-13T10:30:00-04:00',
                    'source' => 'google-calendar',
                ],
                'next_block' => [
                    'title' => 'Planning',
                    'start_time' => '2026-03-13T11:00:00-04:00',
                    'end_time' => '2026-03-13T12:00:00-04:00',
                    'source' => 'google-calendar',
                ],
                'gaps' => [],
                'overruns' => [],
            ],
            'clock_health' => [
                'provider' => 'timedatectl',
                'synchronized' => true,
                'reference_source' => 'system-wall-clock',
                'drift_seconds' => 0.2,
                'threshold_seconds' => 5,
                'state' => 'healthy',
                'safe_for_temporal_reasoning' => true,
                'retry_after_seconds' => 30,
                'fallback_mode' => 'none',
                'metadata' => ['timezone' => 'America/Toronto'],
            ],
            'schedule_metadata' => [
                'schedule' => [[
                    'title' => 'Standup',
                    'start_time' => '2026-03-13T09:30:00-04:00',
                    'end_time' => '2026-03-13T10:30:00-04:00',
                    'source' => 'google-calendar',
                ]],
                'schedule_summary' => '',
                'has_clear_day' => false,
            ],
            'timezone_context' => [
                'timezone' => 'America/Toronto',
                'source' => 'workspace',
            ],
        ], $context->toArray());
    }
}
