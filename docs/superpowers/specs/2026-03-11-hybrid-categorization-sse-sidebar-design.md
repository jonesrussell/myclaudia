# Hybrid Event Categorization + SSE Sidebar Updates

**Date:** 2026-03-11
**Status:** Approved
**Issues:** TBD (to be created from this spec)
**Milestone:** v0.4 / v0.5

## Problem Statement

Two structural problems in the ingestion pipeline and dashboard:

1. **Gmail categorization treats all non-job emails as "people."** Automated senders (GitHub, Stripe, North Cloud Alerts) pollute the People sidebar section. The `EventCategorizer` has no concept of service/automated emails vs. actual humans.

2. **Dashboard sidebar is static after page load.** The `pollBrief` JS function fetches `/brief` every 60s but only updates counter badges, not sidebar section items. After ingestion, the sidebar stays stale until manual page refresh.

## Design

### Part 1: Hybrid Event Categorization (Three-Tier Decision Tree)

#### Decision Tree

```
Gmail event arrives
  |
  +-- Step 1: Sender matches automated patterns?
  |     YES -> 'notification'
  |     (noreply@, alerts@, notifications@, do-not-reply@,
  |      github.com, stripe.com, patreon.com, linkedin.com, etc.)
  |
  +-- Step 2: Sender matches existing Person entity?
  |     YES -> 'people'
  |     (cross-reference from_email against Person.email)
  |
  +-- Step 3: Unknown sender
        -> 'triage'
        (new category, surfaced in brief as "N new senders need classification")
```

#### Architectural Changes

**EventCategorizer becomes a service.** Currently a static class with no dependencies. Adding Person lookup requires state. Convert to a non-static service with constructor injection:

```php
final class EventCategorizer
{
    public function __construct(
        private readonly AutomatedSenderDetector $senderDetector,
        private readonly ?EntityRepositoryInterface $personRepo = null,
    ) {}

    public function categorize(string $source, string $type, array $payload = []): string
    {
        // ... three-tier logic
    }
}
```

**New class: AutomatedSenderDetector.** Single responsibility: determine if a sender address/domain is automated.

```php
final class AutomatedSenderDetector
{
    // Automated address prefixes
    private const AUTOMATED_PREFIXES = [
        'noreply', 'no-reply', 'no_reply',
        'alerts', 'alert',
        'notifications', 'notification', 'notify',
        'do-not-reply', 'donotreply',
        'mailer-daemon', 'postmaster',
        'support', 'billing', 'info',
    ];

    // Automated sender domains (always automated regardless of prefix)
    private const AUTOMATED_DOMAINS = [
        'github.com', 'stripe.com', 'patreon.com',
        'linkedin.com', 'indeed.com', 'glassdoor.com',
        'twitch.tv', 'discord.com',
        'googleusercontent.com', 'google.com',
        'amazonses.com', 'sendgrid.net', 'mailchimp.com',
        'northcloud.one',
    ];

    public function isAutomated(string $email, string $senderName = ''): bool
    {
        // Check domain, then prefix
    }
}
```

**Both ingestion paths must be updated.** Two call sites currently use `EventCategorizer`:
- `src/Ingestion/Handler/GenericEventHandler.php` (IngestController fallback, used by API)
- `src/Ingestion/EventHandler.php` (Envelope-based pipeline, used by direct ingestion)

Both must inject the new `EventCategorizer` service instead of calling the static method.

**AutomatedSenderDetector replaces PersonTierClassifier's automated detection.** `src/Support/PersonTierClassifier.php` already contains `AUTOMATED_PATTERNS` with overlapping entries (noreply, no-reply, etc.). `AutomatedSenderDetector` becomes the single source of truth for "is this sender automated?" `PersonTierClassifier` should delegate to `AutomatedSenderDetector` for its automated check rather than maintaining its own pattern list.

**Person upsert must happen AFTER categorization.** In `EventHandler::handle()`, `upsertPerson()` currently runs before categorization. This creates Person entities for automated senders. After this change: categorize first, then only call `upsertPerson()` if the category is `people` or `triage`. Automated senders (`notification` category) must NOT create Person records.

**New 'triage' category.** Flows through the assembler into a new sidebar section. The DayBriefAssembler adds a `triage` array to its return value:

```php
return [
    'schedule' => [...],
    'people' => [...],
    'triage' => [                    // NEW
        ['person_name' => '...', 'person_email' => '...', 'summary' => '...', 'occurred' => '...'],
    ],
    'commitments' => [...],
    'counts' => [
        'job_alerts' => int,
        'messages' => int,           // existing: count of 'people' events
        'triage' => int,             // NEW: count of 'triage' events
        'due_today' => int,
        'drifting' => int,
    ],
    // ...
];
```

The dashboard template renders triage as "N new senders need classification." Future: chat can ask users to classify triage senders, promoting them to Person entities or marking as automated.

#### Impact on Existing Categories

| Source | Current behavior | New behavior |
|--------|-----------------|--------------|
| `google-calendar` | `schedule` or `job_hunt` | Unchanged |
| `gmail` (job keywords) | `job_hunt` | Unchanged |
| `gmail` (automated sender) | `people` (wrong) | `notification` |
| `gmail` (known Person) | `people` | `people` (confirmed) |
| `gmail` (unknown sender) | `people` (risky) | `triage` |
| Other sources | `notification` | Unchanged |

### Part 2: SSE-Driven Sidebar Updates

#### Architecture

```
IngestController receives POST
  -> registry->handle($data)       (saves entity)
  -> touchBriefSignal()            (existing file-based signal)
  -> broadcastSidebarUpdate()      (NEW: push to SSE clients)
```

#### SSE Endpoint

**Reuse existing `BriefStreamController` at `GET /stream/brief`.** This controller already implements the exact SSE pattern needed: file-based signal watching via `BriefSignal`, brief assembly, `brief-update` events, keepalive, and reconnection. No new SSE endpoint needed.

Enhance `BriefStreamController` to:
1. Include the full sidebar data (schedule, people, triage, commitments, workspaces) in its `brief-update` event payload
2. Use the existing `BriefSignal` file-based mechanism (already triggered by `IngestController::touchBriefSignal()`)

#### Event Format

```
event: brief-update
data: {"schedule":[...],"people":[...],"triage":[...],"commitments":{"pending":[],"drifting":[]},"counts":{"job_alerts":0,"messages":0,"triage":0,"due_today":0,"drifting":0},"workspaces":[...]}
```

Full sidebar payload per event. No granular per-section events. This avoids partial update bugs and sync complexity.

#### Client-Side

```js
// Connect to existing brief SSE (already registered in routes)
var briefSource = new EventSource('/stream/brief');

briefSource.addEventListener('brief-update', function(e) {
    var data = JSON.parse(e.data);
    rebuildSidebar(data);
});

// Reconnection on tab wake
document.addEventListener('visibilitychange', function() {
    if (!document.hidden && briefSource.readyState === EventSource.CLOSED) {
        briefSource = new EventSource('/stream/brief');
        // re-attach listener
    }
});
```

`rebuildSidebar(data)` replaces innerHTML of each sidebar section using the same structure as the Twig template.

#### Polling Demotion

Existing `pollBrief` kept as fallback only:
- Reconnection after SSE drop
- Browser tab wake events
- Interval increased from 60s to 300s (fallback, not primary)

#### Signal Mechanism

Uses the existing file-based `BriefSignal` pattern. `IngestController` already calls `touchBriefSignal()` after successful ingestion. `BriefStreamController` already watches for this signal. No new signaling infrastructure needed.

## Dependencies

- Part 1 (categorization) is independent and can ship first
- Part 2 (SSE sidebar) depends on Part 1 for the `triage` category but can be built in parallel on the UI side

## Testing Strategy

**Part 1:**
- Unit tests for `AutomatedSenderDetector` (pattern matching, domain matching, edge cases)
- Unit tests for `EventCategorizer` (three-tier flow with mocked PersonRepo)
- Integration test: ingest a gmail event from `noreply@github.com`, verify category is `notification`
- Integration test: ingest a gmail event from a known Person, verify category is `people`
- Integration test: ingest a gmail event from unknown sender, verify category is `triage`

**Part 2:**
- Unit test: `BriefStreamController` emits `brief-update` event after signal file touch
- Integration test: POST to `/api/ingest`, verify SSE client receives `brief-update` event
- Manual test: ingest event, verify sidebar updates without page refresh

## Migration

- `claudriel:recategorize-events` CLI command is the canonical migration tool (uses the framework's entity storage layer). `bin/fix-event-categories.php` is a standalone fallback for environments where the CLI can't resolve the database path.
- After deploying Part 1, update `RecategorizeEventsCommand` to use the new `EventCategorizer` service (which now includes three-tier logic), then run on production.

## Specs to Update After Implementation

- `docs/specs/ingestion.md`: reflect `EventCategorizer` becoming a service with DI, new categories, `AutomatedSenderDetector`
- `docs/specs/day-brief.md`: reflect `triage` key in assembler output
- `docs/specs/infrastructure.md`: reflect any service provider wiring changes

## Out of Scope

- AI-assisted classification of triage senders (future, uses triage as training data)
- Person auto-creation from triage (requires user confirmation flow)
- Granular SSE events per sidebar section (unnecessary complexity)
- WebSocket upgrade (SSE is sufficient for unidirectional server push)
