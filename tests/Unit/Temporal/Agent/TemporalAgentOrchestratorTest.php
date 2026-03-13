<?php

declare(strict_types=1);

namespace Tests\Unit\Temporal\Agent;

use Claudriel\Temporal\Agent\TemporalAgentAction;
use Claudriel\Temporal\Agent\TemporalAgentContext;
use Claudriel\Temporal\Agent\TemporalAgentDecision;
use Claudriel\Temporal\Agent\TemporalAgentEvaluationLedger;
use Claudriel\Temporal\Agent\TemporalAgentInterface;
use Claudriel\Temporal\Agent\TemporalAgentLifecycle;
use Claudriel\Temporal\Agent\TemporalAgentOrchestrator;
use Claudriel\Temporal\Agent\TemporalAgentRegistry;
use Claudriel\Temporal\Agent\TemporalAgentSuppressionPolicy;
use Claudriel\Temporal\TimeSnapshot;
use PHPUnit\Framework\TestCase;

final class TemporalAgentOrchestratorTest extends TestCase
{
    public function test_registry_preserves_deterministic_agent_sequence(): void
    {
        $registry = new TemporalAgentRegistry([
            new StubTemporalAgent('prep-agent'),
            new StubTemporalAgent('wrap-up-agent'),
        ]);

        self::assertSame(['prep-agent', 'wrap-up-agent'], array_map(
            static fn (TemporalAgentInterface $agent): string => $agent->name(),
            $registry->all(),
        ));
    }

    public function test_evaluate_uses_one_stable_context_for_all_agents_and_returns_batch(): void
    {
        $tracker = new \ArrayObject;
        $context = $this->buildContext();
        $orchestrator = new TemporalAgentOrchestrator(new TemporalAgentRegistry([
            new TrackingTemporalAgent('prep-agent', $tracker),
            new TrackingTemporalAgent('wrap-up-agent', $tracker),
        ]));

        $batch = $orchestrator->evaluate($context)->toArray();

        self::assertSame(2, $batch['emitted_count']);
        self::assertSame(0, $batch['suppressed_count']);
        self::assertSame([
            spl_object_id($context),
            spl_object_id($context),
        ], $tracker->getArrayCopy());
    }

    public function test_duplicate_emissions_are_suppressed_within_same_evaluation_window(): void
    {
        $context = $this->buildContext();
        $ledger = new TemporalAgentEvaluationLedger;
        $orchestrator = new TemporalAgentOrchestrator(
            new TemporalAgentRegistry([
                new DuplicateTemporalAgent('prep-agent'),
            ]),
            $ledger,
        );

        $first = $orchestrator->evaluate($context)->toArray();
        $second = $orchestrator->evaluate($context)->toArray();

        self::assertSame(TemporalAgentLifecycle::EMITTED, $first['decisions'][0]['state']);
        self::assertSame(TemporalAgentLifecycle::SUPPRESSED, $second['decisions'][0]['state']);
        self::assertSame('duplicate_within_window', $second['decisions'][0]['reason_code']);
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

final class StubTemporalAgent implements TemporalAgentInterface
{
    public function __construct(
        private readonly string $name,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function evaluate(TemporalAgentContext $context): TemporalAgentDecision
    {
        return TemporalAgentDecision::emit(
            agentName: $this->name,
            kind: 'nudge',
            title: 'Stub',
            summary: 'Stub decision.',
            reasonCode: 'stub',
            context: $context,
            suppressionPolicy: new TemporalAgentSuppressionPolicy,
        );
    }
}

final class TrackingTemporalAgent implements TemporalAgentInterface
{
    public function __construct(
        private readonly string $name,
        private readonly \ArrayObject $tracker,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function evaluate(TemporalAgentContext $context): TemporalAgentDecision
    {
        $this->tracker->append(spl_object_id($context));

        return TemporalAgentDecision::emit(
            agentName: $this->name,
            kind: 'nudge',
            title: 'Tracked',
            summary: 'Tracked decision.',
            reasonCode: 'tracked',
            context: $context,
            suppressionPolicy: new TemporalAgentSuppressionPolicy,
            actions: [new TemporalAgentAction('open_chat', 'Ask', ['prompt' => 'Help'])],
        );
    }
}

final class DuplicateTemporalAgent implements TemporalAgentInterface
{
    public function __construct(
        private readonly string $name,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function evaluate(TemporalAgentContext $context): TemporalAgentDecision
    {
        return TemporalAgentDecision::emit(
            agentName: $this->name,
            kind: 'nudge',
            title: 'Prepare for Planning',
            summary: 'Planning starts in 15 minutes.',
            reasonCode: 'next_block_prep_window',
            context: $context,
            suppressionPolicy: new TemporalAgentSuppressionPolicy(windowSeconds: 900),
        );
    }
}
