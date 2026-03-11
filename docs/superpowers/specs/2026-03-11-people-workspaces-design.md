# People + Workspaces Design Spec (v0.5)

## Summary

Enrich the Person entity, introduce a Workspace entity, redesign the sidebar around workspace cards, and integrate both into the brief and chat. This is the "Smart Briefs" milestone: making Claudriel's UI useful instead of noisy.

## Decision Log

| Decision | Choice | Rationale |
|---|---|---|
| People ↔ Workspaces relationship | Soft linking via events | No premature coupling; connection emerges from data |
| Workspace scope | Projects only (no PARA types) | YAGNI; add type field later if needed |
| Event → Workspace assignment | AI-assisted pipeline step | Already paying for AI in commitment extraction; rules miss nuance |
| Sidebar layout | Workspace cards replace At a Glance | Cards are actionable, meaningful, personalized |
| Workspace click behavior | Filter sidebar + pre-fill chat | Immediate visual feedback + conversational depth |

---

## 1. Person Entity Enrichment

### Current fields
`name`, `email`, `tier`, `uuid`

### New fields

| Field | Type | Default | Purpose |
|---|---|---|---|
| `last_interaction_at` | string (ISO 8601) | null | Updated on each event from this person; drives sidebar sorting |
| `source` | string | 'gmail' | How we first learned about them (gmail, calendar, linkedin) |
| `metadata` | string (JSON) | '{}' | Extensible bag for future fields |

### Behavior changes
- `EventHandler::upsertPerson()` updates `last_interaction_at` on every event, not just on first encounter
- `EventHandler::upsertPerson()` sets `source` from `Envelope::source` on creation

---

## 2. Workspace Entity (new)

### Fields

| Field | Type | Default | Purpose |
|---|---|---|---|
| `id` | int (auto) | — | Primary key |
| `uuid` | string | generated | External identifier |
| `name` | string | — | Display name (e.g., "GoFormX") |
| `description` | string | '' | Optional description |
| `account_id` | string | — | Multi-tenant support |
| `metadata` | string (JSON) | '{}' | Extensible bag |

### Entity definition

```php
final class Workspace extends ContentEntityBase
{
    protected string $entityTypeId = 'workspace';
    protected array $entityKeys = [
        'id' => 'wid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];
}
```

### Registration
- Register in `ClaudrielServiceProvider::register()` via `$this->entityType(new EntityType(...))`
- Wire `EntityRepository` for workspace storage

### Population
- User-created only (via chat: "Create a workspace called GoFormX")
- No auto-creation from events
- CLI command: `claudriel:workspaces` to list/manage

---

## 3. Event → Workspace Assignment

### Schema change
- Add `workspace_id` field to `McEvent` (nullable, references Workspace UUID)

### Pipeline step: WorkspaceClassificationStep

```
Position: after CommitmentExtractionStep
Input: McEvent + list of existing Workspaces
Output: StepResult with suggested workspace_id
```

**Behavior:**
- Loads all workspaces for the tenant
- If no workspaces exist, returns `StepResult::success(['workspace_id' => null])`
- Sends event source, type, subject, and body + workspace names/descriptions to AI
- AI returns the best-matching workspace name or "none"
- Maps workspace name back to UUID
- Falls back to null if confidence is low or no match

**Prompt template:**
```
Given these workspaces: [name: description, ...]
Which workspace does this event belong to?
Event source: {source}, type: {type}
Subject: {subject}
Body (first 500 chars): {body}
Reply with the workspace name or "none".
```

---

## 4. People ↔ Workspaces (Soft Linking)

No direct foreign key between Person and Workspace.

### Query pattern
"People in workspace X" = distinct persons on events where `workspace_id = X`

```php
// Pseudocode
$eventIds = $eventRepo->getQuery()
    ->condition('workspace_id', $workspaceUuid)
    ->execute();
$events = $eventRepo->loadMultiple($eventIds);
$emails = array_unique(array_map(fn($e) => json_decode($e->get('payload'), true)['from_email'] ?? '', $events));
$people = $personRepo->findBy(['email' => $emails]);
```

This avoids a junction table and lets the relationship emerge naturally from ingested data.

---

## 5. Sidebar Redesign

### Before (current)
```
AT A GLANCE (4 counter cards: Jobs, Messages, Due, Drifting)
SCHEDULE
COMMITMENTS
DRIFTING
PEOPLE
```

### After
```
WORKSPACES (card grid, replaces At a Glance)
SCHEDULE (filterable)
COMMITMENTS (filterable, absorbs Drifting)
PEOPLE (filterable)
```

### Workspace cards
- 2-column grid (same as current counter cards)
- Each card shows: workspace name + activity count
- **Activity count definition:** number of events in the last 24 hours tagged to that workspace, after deduplication
- Color: each workspace gets a rotating accent color from the palette
- Click: selects workspace (highlighted border), filters sections below, pre-fills chat input

### Filtering behavior
- When a workspace is selected:
  - Schedule shows only events with matching `workspace_id`
  - Commitments shows only commitments linked to events in that workspace
  - People shows only persons who appear in events for that workspace
- Click the selected workspace again to deselect (show all)
- Selected state persists via `localStorage` key `claudriel_workspace`

### "All" state (no workspace selected)
- Shows all items across all workspaces (current behavior)
- Workspace cards show their individual activity counts

### Drifting → Commitments merge
- Drifting commitments render inline in the Commitments section with red accent
- No separate Drifting section

---

## 6. Chat Integration

### Workspace context in system prompt

When a workspace is selected, `ChatSystemPromptBuilder` includes:

```
## Active Workspace: GoFormX
You are currently operating within the GoFormX workspace.
This workspace includes events, commitments, and people related to the GoFormX project.
```

When no workspace is selected, the prompt remains unchanged (global context).

### Workspace click → chat pre-fill
Clicking a workspace card sets `inputEl.value` to:
```
Show me everything for {workspace name}.
```
User can hit Enter, edit, or ignore.

### Person cards in chat
When the chat response mentions a person, the system can render person cards:
```
Chris Schultz messaged you about the deployment timeline.
```
This uses the existing `wrapCardsInContent()` pattern with a new `chat-card--person` type.

---

## 7. New CLI Commands

| Command | Purpose |
|---|---|
| `claudriel:workspaces` | List workspaces with activity counts |
| `claudriel:workspace:create {name}` | Create a new workspace |

---

## 8. Files to Create/Modify

### New files
- `src/Entity/Workspace.php`
- `src/Pipeline/WorkspaceClassificationStep.php`
- `src/Command/WorkspacesCommand.php`
- `src/Command/WorkspaceCreateCommand.php`
- `tests/Unit/Entity/WorkspaceTest.php`
- `tests/Unit/Pipeline/WorkspaceClassificationStepTest.php`

### Modified files
- `src/Entity/McEvent.php` — add `workspace_id` to constructor defaults
- `src/Entity/Person.php` — add `last_interaction_at`, `source`, `metadata` defaults
- `src/Ingestion/EventHandler.php` — update `upsertPerson()` to set `last_interaction_at` and `source`
- `src/Provider/ClaudrielServiceProvider.php` — register Workspace entity type + routes
- `src/Controller/DashboardController.php` — load workspaces, pass to template
- `src/DayBrief/Assembler/DayBriefAssembler.php` — workspace-aware assembly
- `src/Domain/Chat/ChatSystemPromptBuilder.php` — include workspace context
- `templates/dashboard.twig` — workspace cards, filtering JS, drifting merge

### Spec updates
- `docs/specs/entity.md` — add Workspace entity
- `docs/specs/ingestion.md` — document WorkspaceClassificationStep
- `docs/specs/workflow.md` — update milestone status
