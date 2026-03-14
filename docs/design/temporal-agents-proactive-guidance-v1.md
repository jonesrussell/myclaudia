# Temporal Agents Proactive Guidance v1

This document defines the first product-grade user experience for proactive temporal guidance.

It assumes the deterministic temporal substrate already exists and focuses on how agent-driven guidance appears, competes for attention, and remains actionable without overwhelming the schedule surface.

For the canonical runtime, delivery, observability, and validation model behind this UX, use [temporal-agents.md](/home/fsd42/dev/claudriel/docs/specs/temporal-agents.md).

## Goals

- Surface timely schedule intelligence before the user has to ask.
- Keep the schedule timeline primary while making proactive guidance impossible to miss when it matters.
- Make alert severity and actionability visually legible at a glance.
- Preserve deterministic behavior by aligning visible guidance states with explicit agent lifecycle states.
- Ensure the experience works on desktop and mobile without collapsing into separate interaction models.

## Product Surfaces

### Guidance panel

- The guidance panel is the primary home for proactive schedule intelligence.
- It sits adjacent to the schedule on desktop and above the schedule timeline on mobile.
- The panel contains the active stack of prep nudges, overrun alerts, wrap-up prompts, and passive contextual guidance.
- The panel is ordered by priority first, then time sensitivity, then recency.

### Ambient nudges

- Ambient nudges are lightweight prompts anchored near the schedule timeline or current block.
- They mirror the highest-priority actionable guidance item without duplicating the full panel payload.
- Ambient nudges should feel like contextual assistive cues, not modal interruptions.
- Only one ambient nudge is visible at a time.

### Schedule-linked cues

- Guidance items can visually point back to the relevant current block, next block, or gap on the schedule.
- Linked cues use shared accent colors and event titles so users can map advice to the schedule instantly.
- Guidance never obscures the timeline rail or current-block content.

## Information Hierarchy

### Priority order

1. Overrun alerts
2. Imminent prep nudges
3. Wrap-up prompts
4. Gap or shift suggestions
5. Passive day-clear guidance

### Visual tiers

- Critical attention:
  - Used for overruns that threaten the next block or require immediate intentional action.
  - Highest-contrast border, strongest background treatment, and persistent placement at the top of the panel.
- Active guidance:
  - Used for prep and wrap-up prompts that are timely but not disruptive.
  - Moderate emphasis with clear actions and direct time framing.
- Passive guidance:
  - Used for open gaps, clear-day states, or lower-pressure contextual nudges.
  - Lower contrast and more compact card chrome.

## Guidance Types

### Prep nudges

- Prep nudges appear before the next relevant block inside a deterministic lead window.
- They summarize what is coming next, when it starts, and the time remaining.
- They prioritize one strong action such as opening chat prep, reviewing context, or focusing the event.

### Overrun alerts

- Overrun alerts appear when the current block has crossed its scheduled end boundary.
- They explicitly name the overrun block, the overrun duration, and the downstream schedule risk.
- They expose shift-oriented actions first, then dismiss or snooze.

### Wrap-up prompts

- Wrap-up prompts appear near the end of the current block or just after the block ends.
- They frame the user toward closure rather than alarm.
- They should feel like a deliberate handoff into the next block or into open time.

### Gap and shift guidance

- Gap guidance appears when the user has intentional space between blocks.
- Shift guidance appears when schedule drift or overruns make the next block ambiguous.
- These prompts are advisory rather than urgent unless they are backed by an overrun risk.

### Clear-day guidance

- If no future events remain, the guidance panel shows a calm clear-day state.
- This state should feel intentionally empty rather than missing.
- It can still expose one lightweight action such as planning or capture.

## Desktop Layout

- The dashboard uses a two-column temporal workspace:
  - guidance panel
  - schedule timeline
- The guidance panel has a fixed width and scrolls independently from the chat surface.
- The top card can pin while the user scrolls through lower-priority guidance.
- Ambient nudges appear inline near the current schedule block, not floating globally over the page.

### Guidance card anatomy

- Status chip
- Title
- Time framing line
- One-sentence summary
- Primary action
- Secondary actions
- Dismiss and snooze controls

## Mobile Layout

- The guidance panel collapses into a stacked sheet above the schedule.
- The highest-priority item appears first in an expanded state.
- Lower-priority items appear as compact accordion rows.
- Ambient nudges become inline banners between the guidance sheet and the schedule timeline.

### Mobile constraints

- Actions must remain thumb-reachable.
- Dismiss and snooze cannot be hidden behind hover behavior.
- Long summaries collapse after two lines with an explicit expand affordance.

## Interaction Model

### Open

- Opening a guidance item expands it in place and reveals the full summary, linked schedule context, and action set.
- Open state is sticky until the user dismisses, snoozes, acts, or another higher-priority event displaces it.

### Dismiss

- Dismiss removes the guidance item from the active surface for the current lifecycle window.
- Dismiss should visually confirm that the item is intentionally cleared, not lost due to refresh.

### Snooze

- Snooze temporarily suppresses the guidance item and records the snooze horizon.
- Snooze durations must be explicit:
  - 5 minutes
  - 15 minutes
  - until next block boundary

### Action execution

- Actions should preserve event identity and time context.
- Execution states:
  - idle
  - working
  - complete
  - failed
- Completed actions collapse into a compact confirmation state rather than leaving the card visually “busy.”

## Coexistence With The Schedule Timeline

- The schedule timeline remains the authoritative day view.
- Guidance amplifies or interprets schedule state; it does not replace schedule structure.
- When multiple guidance cards relate to the same event, the schedule should show a single linked cue rather than multiple badges.
- The current block should visually align with the highest-priority relevant guidance item when one exists.

## Multiple Simultaneous Nudges

- When multiple active nudges exist:
  - only one uses critical emphasis
  - one additional item may remain expanded by default
  - the rest collapse into compact rows
- Cards referencing the same event should be merged into one surface when possible.
- If prep and overrun guidance compete, overrun wins and prep is visually subordinated.

## Motion

- Panel reorder animation should be subtle and vertical.
- New urgent guidance should fade and slide into the top slot rather than popping.
- Dismiss and snooze actions collapse cards with short height transitions.
- Ambient nudge updates should not shift the schedule layout abruptly.

## Accessibility

- Priority must be readable without color alone.
- Guidance cards need explicit labels for type, urgency, and related schedule block.
- Expand, dismiss, snooze, and action controls must be keyboard accessible.
- Mobile accordion behavior must preserve reading order and action discoverability.

## Design Tokens And Tone

- Prep:
  - optimistic accent
  - calm, anticipatory copy
- Overrun:
  - warning accent
  - direct, time-aware copy
- Wrap-up:
  - transition accent
  - concise, closure-oriented copy
- Passive:
  - muted accent
  - low-pressure assistive copy

## Implementation Guidance For Later Issues

### For #130 notification delivery

- The panel assumes stable item states for active, dismissed, snoozed, and expired guidance.
- Cards need a unique deterministic key and a user-visible state transition model.

### For #131 UI implementation

- Build the guidance panel and ambient nudges as separate but coordinated surfaces.
- The schedule timeline must remain readable when no guidance exists.

### For #132 observability

- Observability should map visible card states to agent lifecycle states and user actions.
- Suppressed or snoozed items should remain inspectable even when they are no longer visible in the live panel.
