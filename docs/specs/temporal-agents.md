# Temporal Agents Runtime And Operating Model

## Purpose

This document is the canonical implementation and operations reference for Claudriel's temporal agents subsystem.

It complements the UX document in [temporal-agents-proactive-guidance-v1.md](/home/fsd42/dev/claudriel/docs/design/temporal-agents-proactive-guidance-v1.md) by describing the deterministic runtime contract, evaluation order, notification delivery semantics, observability model, and validation workflow that now exist in code.

## Operator Quickstart

Use these entry points when validating or debugging the subsystem on a live branch:

- Guidance UI:
  - dashboard HTML at `/`
  - brief fallback JSON at `/stream/brief?transport=fallback`
- Notification actions:
  - dismiss endpoint
  - snooze endpoint
  - action-state endpoint
- Observability:
  - HTML dashboard at `/platform/observability`
  - JSON payload at `/platform/observability.json`
- Smoke references:
  - [TemporalGuidanceSmokeTest.php](/home/fsd42/dev/claudriel/tests/Unit/Temporal/Agent/TemporalGuidanceSmokeTest.php)
  - [v1.0-smoke-matrix.md](/home/fsd42/dev/claudriel/tests/smoke/v1.0-smoke-matrix.md)

## Main Components

- Runtime contract:
  - [TemporalAgentContext.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalAgentContext.php)
  - [TemporalAgentDecision.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalAgentDecision.php)
  - [TemporalAgentLifecycle.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalAgentLifecycle.php)
  - [TemporalAgentSuppressionPolicy.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalAgentSuppressionPolicy.php)
- Context assembly:
  - [TemporalAgentContextBuilder.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalAgentContextBuilder.php)
- Evaluation loop:
  - [TemporalAgentRegistry.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalAgentRegistry.php)
  - [TemporalAgentOrchestrator.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalAgentOrchestrator.php)
  - [TemporalAgentEvaluationBatch.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalAgentEvaluationBatch.php)
- Current agents:
  - [OverrunAlertAgent.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/OverrunAlertAgent.php)
  - [ShiftRiskAgent.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/ShiftRiskAgent.php)
  - [WrapUpPromptAgent.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/WrapUpPromptAgent.php)
  - [UpcomingBlockPrepAgent.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/UpcomingBlockPrepAgent.php)
- Delivery and user actions:
  - [TemporalNotificationDeliveryService.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalNotificationDeliveryService.php)
  - [TemporalNotificationApiController.php](/home/fsd42/dev/claudriel/src/Controller/TemporalNotificationApiController.php)
  - [TemporalGuidanceAssembler.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalGuidanceAssembler.php)
- Observability:
  - [ObservabilityDashboardController.php](/home/fsd42/dev/claudriel/src/Controller/Platform/ObservabilityDashboardController.php)

## Runtime Contract

### Input envelope

Each evaluation pass operates on one immutable `TemporalAgentContext`.

The context includes:

- `tenant_id`
- `workspace_uuid`
- `time_snapshot`
  - UTC timestamp
  - local timestamp
  - timezone
  - monotonic timestamp
- `temporal_awareness`
  - `current_block`
  - `next_block`
  - `gaps`
  - `overruns`
- `clock_health`
  - provider
  - synchronization status
  - drift
  - fallback mode
  - safe-for-reasoning flag
- `schedule_metadata`
  - normalized schedule list
  - schedule summary
  - clear-day flag
- `timezone_context`

This shape is asserted in [TemporalAgentContextTest.php](/home/fsd42/dev/claudriel/tests/Unit/Temporal/Agent/TemporalAgentContextTest.php).

### Output envelope

Each agent returns one `TemporalAgentDecision`.

The decision envelope contains:

- `agent`
- `state`
- `kind`
- `title`
- `summary`
- `reason_code`
- `actions`
- `metadata`
- `suppression`
  - deterministic suppression key
  - evaluation window start
  - window duration

This shape is asserted in [TemporalAgentDecisionTest.php](/home/fsd42/dev/claudriel/tests/Unit/Temporal/Agent/TemporalAgentDecisionTest.php).

### Lifecycle states

The stable lifecycle states are:

- `evaluated`
- `emitted`
- `suppressed`
- `dismissed`
- `snoozed`
- `expired`

The runtime uses `emitted` and `suppressed` during evaluation. Delivery and user interaction then move persisted notifications through `active`, `dismissed`, `snoozed`, and `expired` notification states while preserving the originating decision path.

## Deterministic Evaluation Model

### Single-pass guarantees

One evaluation pass uses:

- one stable `TimeSnapshot`
- one stable context payload
- one deterministic agent order
- one duplicate-suppression ledger

The orchestrator does not re-fetch time or rebuild context between agents.

### Current agent order

The current production order in [TemporalGuidanceAssembler.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalGuidanceAssembler.php) is:

1. `overrun-alert`
2. `shift-risk`
3. `wrap-up-prompt`
4. `upcoming-block-prep`

That order matters because downstream delivery coalescing prefers higher-priority agents when multiple emitted decisions overlap the same schedule scope.

### Duplicate suppression

Duplicate suppression is based on:

- agent name
- kind
- reason code
- tenant/workspace scope
- timezone
- selected schedule context
- normalized metadata

The suppression key and evaluation window are produced by [TemporalAgentSuppressionPolicy.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalAgentSuppressionPolicy.php). The orchestrator uses [TemporalAgentEvaluationLedger.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalAgentEvaluationLedger.php) to convert duplicate emits into deterministic suppressed decisions with reason code `duplicate_within_window`.

## Notification Delivery Semantics

### Delivery path

The user-facing proactive guidance flow is:

1. Build a deterministic context.
2. Evaluate the registered agents.
3. Coalesce overlapping emitted decisions by priority.
4. Persist or refresh `temporal_notification` entities.
5. Return active notifications and the top ambient nudge.

This path is implemented in [TemporalGuidanceAssembler.php](/home/fsd42/dev/claudriel/src/Temporal/Agent/TemporalGuidanceAssembler.php).

### Coalescing rules

Delivery currently prioritizes agents in this order:

1. `overrun-alert`
2. `shift-risk`
3. `wrap-up-prompt`
4. `upcoming-block-prep`

If multiple emitted decisions overlap the same schedule scope, the highest-priority one wins for live delivery.

### Persisted notification states

`TemporalNotificationDeliveryService` persists proactive notifications with these runtime states:

- `active`
- `dismissed`
- `snoozed`
- `expired`

Important behavior:

- `dismissed` notifications stay suppressed for the current lifecycle window.
- `snoozed` notifications stay suppressed until `snoozed_until`.
- `expired` is derived from the suppression window horizon.
- action state is tracked independently per action.

### User action state

Actions currently use:

- `idle`
- `working`
- `complete`
- `failed`

The API endpoints are:

- `POST /api/temporal-notifications/{uuid}/dismiss`
- `POST /api/temporal-notifications/{uuid}/snooze`
- `POST /api/temporal-notifications/{uuid}/actions/{action}`

These are handled by [TemporalNotificationApiController.php](/home/fsd42/dev/claudriel/src/Controller/TemporalNotificationApiController.php).

## Observability Model

### Dashboard representation

The observability dashboard now replays the temporal agent pipeline under a stable snapshot and renders a dedicated `Proactive agent execution` subtree.

That subtree includes:

- one node per agent
- decision envelope summary
- suppression vs emission state
- notification delivery linkage when a persisted notification matches the decision
- action-state nodes such as `Action: open_chat`

### Status mapping

The call-chain uses these practical mappings:

- emitted decision: `success`
- suppressed decision: usually `fallback`
- duplicate-within-window suppression: `retry`
- active notification: `success`
- snoozed notification: `retry`
- dismissed or expired notification: `fallback`
- failed action: `error`

This keeps proactive guidance inspectable even when it is not currently visible in the dashboard guidance panel.

### Failure mode

If observability replay fails, the dashboard emits an explicit `Evaluation replay failure` error node rather than hiding the subsystem.

## Validation Workflow

### Core unit coverage

The runtime contract and orchestration are covered by:

- [TemporalAgentContextTest.php](/home/fsd42/dev/claudriel/tests/Unit/Temporal/Agent/TemporalAgentContextTest.php)
- [TemporalAgentDecisionTest.php](/home/fsd42/dev/claudriel/tests/Unit/Temporal/Agent/TemporalAgentDecisionTest.php)
- [TemporalAgentContextBuilderTest.php](/home/fsd42/dev/claudriel/tests/Unit/Temporal/Agent/TemporalAgentContextBuilderTest.php)
- [TemporalAgentOrchestratorTest.php](/home/fsd42/dev/claudriel/tests/Unit/Temporal/Agent/TemporalAgentOrchestratorTest.php)
- [TemporalNotificationDeliveryServiceTest.php](/home/fsd42/dev/claudriel/tests/Unit/Temporal/Agent/TemporalNotificationDeliveryServiceTest.php)
- [OverrunShiftWrapUpAgentTest.php](/home/fsd42/dev/claudriel/tests/Unit/Temporal/Agent/OverrunShiftWrapUpAgentTest.php)

### Smoke-style validation

The current smoke-style proactive validation lives in:

- [TemporalGuidanceSmokeTest.php](/home/fsd42/dev/claudriel/tests/Unit/Temporal/Agent/TemporalGuidanceSmokeTest.php)
- [v1.0-smoke-matrix.md](/home/fsd42/dev/claudriel/tests/smoke/v1.0-smoke-matrix.md)

Those checks cover:

- upcoming-block prep delivery
- snooze and dismiss transitions
- deterministic overrun and wrap-up evaluation
- observability trace presence

### Local check sequence

For temporal-agent changes that affect runtime behavior, run at minimum:

- `composer test -- tests/Unit/Temporal/Agent/TemporalGuidanceSmokeTest.php`
- `composer test -- tests/Unit/Platform/ObservabilityDashboardAggregationTest.php`
- `composer test -- tests/Unit/Controller/TemporalNotificationApiControllerTest.php`

If the change alters agent contract or orchestration internals, also run the broader temporal-agent unit suite.

## Operational Notes

- The subsystem is tenant-aware and may be workspace-aware. Scope is part of the deterministic contract, not a presentation detail.
- Timezone is part of suppression-key generation. Seemingly equivalent snapshots like `UTC` and `+00:00` can generate different keys if the timezone context changes.
- Observability replay uses the same agent registry and context builder concepts as live delivery and should stay aligned with production evaluation order.
- New agents should be added only after defining:
  - deterministic reason codes
  - suppression metadata
  - overlap priority expectations
  - observability readability impact
  - smoke coverage expectations

## Debugging Sequence

When a proactive guidance issue is reported, the fastest deterministic debugging order is:

1. Confirm the source schedule and time snapshot in the brief or schedule payload.
2. Confirm the expected notification exists in the fallback brief payload.
3. Confirm persisted `temporal_notification` state and `action_states`.
4. Inspect `/platform/observability.json` for the matching `Proactive agent execution` path.
5. Re-run the smoke-style temporal tests before widening the investigation.
