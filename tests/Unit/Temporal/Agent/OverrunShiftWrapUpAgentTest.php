<?php

declare(strict_types=1);

namespace Tests\Unit\Temporal\Agent;

use Claudriel\Temporal\Agent\OverrunAlertAgent;
use Claudriel\Temporal\Agent\ShiftRiskAgent;
use Claudriel\Temporal\Agent\TemporalAgentContext;
use Claudriel\Temporal\Agent\TemporalAgentEvaluationLedger;
use Claudriel\Temporal\Agent\TemporalAgentOrchestrator;
use Claudriel\Temporal\Agent\TemporalAgentRegistry;
use Claudriel\Temporal\Agent\WrapUpPromptAgent;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;

final class OverrunShiftWrapUpAgentTest extends TestCase
{
    public function test_overrun_alert_emits_for_material_overrun(): void
    {
        $decision = (new OverrunAlertAgent(minimumOverrunMinutes: 5))->evaluate($this->buildContext(
            nowLocal: '2026-03-13T11:15:00-04:00',
            overrunMinutes: 15,
        ))->toArray();

        self::assertSame('emitted', $decision['state']);
        self::assertSame('active_overrun', $decision['reason_code']);
        self::assertSame('Resolve the active overrun', $decision['title']);
        self::assertSame(15, $decision['metadata']['overrun_minutes']);
    }

    public function test_shift_risk_emits_when_next_block_is_at_risk(): void
    {
        $decision = (new ShiftRiskAgent(minimumOverrunMinutes: 5, riskLeadWindowMinutes: 15))->evaluate($this->buildContext(
            nowLocal: '2026-03-13T11:15:00-04:00',
            overrunMinutes: 15,
            nextBlockStart: '2026-03-13T11:20:00-04:00',
        ))->toArray();

        self::assertSame('emitted', $decision['state']);
        self::assertSame('downstream_schedule_risk', $decision['reason_code']);
        self::assertSame('Client Call', $decision['metadata']['next_block_title']);
        self::assertSame(5, $decision['metadata']['minutes_until_next_block']);
    }

    public function test_wrap_up_prompt_emits_in_post_block_window_without_downstream_risk(): void
    {
        $decision = (new WrapUpPromptAgent(postBlockWindowMinutes: 10, shiftRiskLeadWindowMinutes: 15))->evaluate($this->buildContext(
            nowLocal: '2026-03-13T11:05:00-04:00',
            overrunMinutes: 5,
            nextBlockStart: '2026-03-13T11:40:00-04:00',
        ))->toArray();

        self::assertSame('emitted', $decision['state']);
        self::assertSame('post_block_wrap_up_window', $decision['reason_code']);
        self::assertStringContainsString('ended 5 minutes ago', $decision['summary']);
    }

    public function test_wrap_up_prompt_suppresses_when_shift_risk_is_immediate(): void
    {
        $decision = (new WrapUpPromptAgent(postBlockWindowMinutes: 10, shiftRiskLeadWindowMinutes: 15))->evaluate($this->buildContext(
            nowLocal: '2026-03-13T11:05:00-04:00',
            overrunMinutes: 5,
            nextBlockStart: '2026-03-13T11:15:00-04:00',
        ))->toArray();

        self::assertSame('suppressed', $decision['state']);
        self::assertSame('downstream_risk_requires_shift', $decision['reason_code']);
    }

    public function test_overrun_and_wrap_up_emissions_are_suppressed_as_duplicates_within_same_window(): void
    {
        $context = $this->buildContext(
            nowLocal: '2026-03-13T11:15:00-04:00',
            overrunMinutes: 15,
            nextBlockStart: '2026-03-13T11:45:00-04:00',
        );
        $orchestrator = new TemporalAgentOrchestrator(
            new TemporalAgentRegistry([
                new OverrunAlertAgent(minimumOverrunMinutes: 5),
                new WrapUpPromptAgent(postBlockWindowMinutes: 20, shiftRiskLeadWindowMinutes: 15),
            ]),
            new TemporalAgentEvaluationLedger,
        );

        $first = $orchestrator->evaluate($context)->toArray();
        $second = $orchestrator->evaluate($context)->toArray();

        self::assertSame('emitted', $first['decisions'][0]['state']);
        self::assertSame('emitted', $first['decisions'][1]['state']);
        self::assertSame('suppressed', $second['decisions'][0]['state']);
        self::assertSame('duplicate_within_window', $second['decisions'][0]['reason_code']);
        self::assertSame('suppressed', $second['decisions'][1]['state']);
        self::assertSame('duplicate_within_window', $second['decisions'][1]['reason_code']);
    }

    private function buildContext(
        string $nowLocal,
        int $overrunMinutes,
        ?string $nextBlockStart = null,
    ): TemporalAgentContext {
        $local = new \DateTimeImmutable($nowLocal);
        $utc = $local->setTimezone(new \DateTimeZone('UTC'));
        $overrunEndedAt = $local->modify(sprintf('-%d minutes', $overrunMinutes));

        return new TemporalAgentContext(
            tenantId: 'tenant-123',
            workspaceUuid: 'workspace-a',
            timeSnapshot: new TimeSnapshot(
                $utc,
                $local,
                42,
                'America/Toronto',
            ),
            temporalAwareness: [
                'current_block' => null,
                'next_block' => $nextBlockStart !== null ? [
                    'title' => 'Client Call',
                    'start_time' => $nextBlockStart,
                    'end_time' => (new \DateTimeImmutable($nextBlockStart))->modify('+45 minutes')->format(\DateTimeInterface::ATOM),
                    'source' => 'google-calendar',
                ] : null,
                'gaps' => [],
                'overruns' => [[
                    'title' => 'Design Review',
                    'ended_at' => $overrunEndedAt->format(\DateTimeInterface::ATOM),
                    'overrun_minutes' => $overrunMinutes,
                ]],
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
