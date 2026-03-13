<?php

declare(strict_types=1);

namespace Claudriel\Temporal\Agent;

use Claudriel\Temporal\TimeSnapshot;

final class TemporalAgentContext
{
    /**
     * @param  array{
     *   current_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *   next_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *   gaps: list<array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}>,
     *   overruns: list<array{title: string, ended_at: string, overrun_minutes: int}>
     * }  $temporalAwareness
     * @param  array{
     *   provider: string,
     *   synchronized: bool,
     *   reference_source: string,
     *   drift_seconds: float,
     *   threshold_seconds: int,
     *   state: string,
     *   safe_for_temporal_reasoning: bool,
     *   retry_after_seconds: int,
     *   fallback_mode: string,
     *   metadata: array<string, scalar|null>
     * }  $clockHealth
     * @param  array{
     *   schedule: list<array{title: string, start_time: string, end_time: string, source: string}>,
     *   schedule_summary: string,
     *   has_clear_day: bool
     * }  $scheduleMetadata
     * @param  array{timezone: string, source: string}  $timezoneContext
     */
    public function __construct(
        private readonly string $tenantId,
        private readonly ?string $workspaceUuid,
        private readonly TimeSnapshot $timeSnapshot,
        private readonly array $temporalAwareness,
        private readonly array $clockHealth,
        private readonly array $scheduleMetadata,
        private readonly array $timezoneContext,
    ) {
        if ($this->tenantId === '') {
            throw new \InvalidArgumentException('Temporal agent contexts require a tenant ID.');
        }
    }

    public function tenantId(): string
    {
        return $this->tenantId;
    }

    public function workspaceUuid(): ?string
    {
        return $this->workspaceUuid;
    }

    public function timeSnapshot(): TimeSnapshot
    {
        return $this->timeSnapshot;
    }

    /**
     * @return array{
     *   current_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *   next_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *   gaps: list<array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}>,
     *   overruns: list<array{title: string, ended_at: string, overrun_minutes: int}>
     * }
     */
    public function temporalAwareness(): array
    {
        return $this->temporalAwareness;
    }

    /**
     * @return array{
     *   provider: string,
     *   synchronized: bool,
     *   reference_source: string,
     *   drift_seconds: float,
     *   threshold_seconds: int,
     *   state: string,
     *   safe_for_temporal_reasoning: bool,
     *   retry_after_seconds: int,
     *   fallback_mode: string,
     *   metadata: array<string, scalar|null>
     * }
     */
    public function clockHealth(): array
    {
        return $this->clockHealth;
    }

    /**
     * @return array{
     *   schedule: list<array{title: string, start_time: string, end_time: string, source: string}>,
     *   schedule_summary: string,
     *   has_clear_day: bool
     * }
     */
    public function scheduleMetadata(): array
    {
        return $this->scheduleMetadata;
    }

    /**
     * @return array{timezone: string, source: string}
     */
    public function timezoneContext(): array
    {
        return $this->timezoneContext;
    }

    /**
     * @return array{
     *   tenant_id: string,
     *   workspace_uuid: ?string,
     *   time_snapshot: array{utc: string, local: string, timezone: string, monotonic_ns: int},
     *   temporal_awareness: array{
     *     current_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *     next_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *     gaps: list<array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}>,
     *     overruns: list<array{title: string, ended_at: string, overrun_minutes: int}>
     *   },
     *   clock_health: array{
     *     provider: string,
     *     synchronized: bool,
     *     reference_source: string,
     *     drift_seconds: float,
     *     threshold_seconds: int,
     *     state: string,
     *     safe_for_temporal_reasoning: bool,
     *     retry_after_seconds: int,
     *     fallback_mode: string,
     *     metadata: array<string, scalar|null>
     *   },
     *   schedule_metadata: array{
     *     schedule: list<array{title: string, start_time: string, end_time: string, source: string}>,
     *     schedule_summary: string,
     *     has_clear_day: bool
     *   },
     *   timezone_context: array{timezone: string, source: string}
     * }
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'workspace_uuid' => $this->workspaceUuid,
            'time_snapshot' => $this->timeSnapshot->toArray(),
            'temporal_awareness' => $this->temporalAwareness,
            'clock_health' => $this->clockHealth,
            'schedule_metadata' => $this->scheduleMetadata,
            'timezone_context' => $this->timezoneContext,
        ];
    }
}
