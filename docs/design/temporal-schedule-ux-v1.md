# Temporal Schedule UX v1

This document defines the schedule interaction model for the first temporal UI release.

## Goals

- Keep the full day visible at a glance.
- Make the current block unmistakable.
- Reduce visual weight for the past without hiding it.
- Keep future density compact until the user asks for detail.
- Preserve fast action-taking from the schedule surface.

## Layout

### Desktop

- The schedule renders as a vertical full-day timeline.
- Each event is anchored to its time range.
- The timeline rail remains visible even when there are few items.
- The current block expands to show title, time range, notes preview, and quick actions.

### Mobile

- The timeline stays vertically stacked in one column.
- Time labels remain pinned to each card instead of a detached side rail.
- Future collapsed items remain tappable as summary rows.

## Visual States

### Past events

- Past blocks remain visible in chronological position.
- Typography is muted and contrast is reduced.
- Event chrome is flattened so the user can scan history without distraction.

### Current block

- The current block is visually expanded by default.
- The card uses the strongest border/accent treatment on the page.
- The now indicator intersects the active block directly when applicable.

### Future events

- Future blocks render collapsed by default.
- Collapsed rows show title, start time, and duration.
- Hover on desktop or tap on mobile expands the event inline.

## Now Indicator

- A horizontal now line crosses the timeline at the exact current time.
- The line includes a small labeled chip showing the current local time.
- The line updates in place without reflowing the timeline structure.

## Current Block Emphasis

- If `start_time <= now < end_time`, that block is marked current.
- Current block metadata includes:
  - title
  - start and end time
  - duration
  - source badge
  - quick actions
- If no block is current, the now line remains visible between blocks.

## Future Collapse Model

- Default collapsed content:
  - title
  - start time
  - end time or duration
- Expanded content:
  - notes preview
  - source
  - quick actions
- Only one future block auto-expands at a time from hover/tap.

## Event Quick Actions

Quick actions live on each event card and must preserve event identity and time context.

### Core actions

- `Open`: navigate to the event details surface or focused context.
- `Ask`: start a chat prompt about the event.
- `Complete` or `Done`: mark the event or related action complete when supported.
- `Shift`: trigger a schedule-change flow for moving the event.

### Behavior

- Quick actions are always visible on the current block.
- Quick actions appear on future blocks when expanded.
- Past events may expose only non-destructive actions such as `Open` and `Ask`.

## Empty and Clear States

- If no future events remain, the schedule agenda summary reads `Your day is clear`.
- Past items can still appear in the timeline when the full-day timeline view is active.
- If the day has no events at all, the rail remains visible with a lightweight empty-state message.

## Motion

- Current block expansion animates height and opacity only.
- Future expand/collapse uses a short vertical reveal.
- The now line updates smoothly without pulsing or flashing.

## Accessibility

- Timeline ordering must match DOM ordering.
- Collapsed and expanded states must be keyboard reachable.
- The current block must expose an accessible `current` label.
- Quick actions must have explicit labels including the event title.

## Implementation Notes For #119 And #120

- The schedule API contract should preserve enough data for:
  - past/current/future state
  - now-line placement
  - quick-action targeting
- The timeline implementation should not rely on hover-only behavior.
- Past events must remain available for later observability and reasoning overlays.
