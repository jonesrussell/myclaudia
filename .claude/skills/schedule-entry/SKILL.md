---
name: schedule-entry
description: "Full schedule entry lifecycle management via Claudriel's GraphQL API: create, list, update, delete schedule entries. Use when user says \"new schedule entry\", \"add to schedule\", \"schedule [thing]\", \"list schedule\", \"show schedule\", \"reschedule\", \"cancel event\", \"delete schedule entry\", or references any schedule CRUD operation."
effort-level: medium
---

# Schedule Entry Management

Full CRUD lifecycle for Claudriel ScheduleEntry entities via GraphQL API.

**Architecture**: This skill orchestrates user intent and calls the GraphQL API at `POST /graphql`. It does NOT manipulate files or storage directly. See `_templates/entity-crud.md` for the base pattern.

## Trigger

- "New schedule entry", "Add to schedule", "Schedule [thing] for [time]"
- "List schedule", "Show my schedule", "What's on my calendar?"
- "Reschedule...", "Update schedule...", "Move [event] to [time]"
- "Cancel event...", "Delete schedule entry...", "Remove from schedule"

## Operation Detection

| Signal | Operation |
|--------|-----------|
| "create", "new", "add", "schedule", "book" | **Create** |
| "list", "show", "what's on", "calendar", "schedule" (noun) | **List** |
| "update", "reschedule", "move", "change time", "edit" | **Update** |
| "delete", "remove" | **Delete** |
| "cancel" | **Update** (status to "cancelled", preserves history) |

If ambiguous, ask. "Schedule" as a noun = list; "schedule" as a verb = create.

## GraphQL Fields

```
uuid title starts_at ends_at notes source status external_id calendar_id recurring_series_id tenant_id created_at updated_at
```

---

## Intent Parsing

### For Update and Delete (resolve-first)

For **update** and **delete** operations, always resolve against existing entities **before** parsing the user's sentence for a title. Entry titles may contain conjunctions, prepositions, or full phrases.

1. Fetch existing entries via `scheduleEntryList` query before parsing
2. Match user's reference against returned entry titles using substring or fuzzy matching
3. If exactly one match, use it
4. If multiple matches, present them and ask which one
5. If no matches, say so and offer to create instead

**Do NOT split the user's input on conjunctions or heuristic word boundaries** when resolving existing entities. The entry title is whatever was stored at creation time.

### For Create (parse from sentence)

Extract entity fields from the user's sentence using heuristics:

1. **Extract the event title**: The activity or meeting name.
   - "schedule a call with Sarah at 3pm" -> title: "Call with Sarah", starts_at: today 3pm
   - "add standup and retro to the schedule" -> title: "Standup and Retro" (conjunctions within the title are preserved)

2. **Extract time information**:
   - "at [time]" -> `starts_at`
   - "from [time] to [time]" -> `starts_at`, `ends_at`
   - "on [date]" -> date component of `starts_at`
   - "tomorrow", "next Monday", "in 2 hours" -> convert relative to absolute ISO 8601

3. **Never use the full user sentence as the title.**

---

## Create

### 1. Parse Intent

Extract title and time fields from user's sentence using the create parsing rules above.

### 2. Gather Fields

| Field | GraphQL Input | Required | Default |
|-------|--------------|----------|---------|
| **title** | `title` | Yes | -- |
| **starts_at** | `starts_at` | Yes | -- |
| **ends_at** | `ends_at` | No | starts_at + 1 hour |
| **notes** | `notes` | No | `null` |
| **status** | `status` | No | `"confirmed"` |
| **source** | `source` | No | `"manual"` |

Ask for any required fields not provided.

### 3. Confirm

```
Create schedule entry?
  title: "Call with Sarah"
  starts_at: 2026-03-21T15:00:00
  ends_at: 2026-03-21T16:00:00
  status: confirmed
```

### 4. Call API

```graphql
mutation {
  createScheduleEntry(input: {
    title: "Call with Sarah",
    starts_at: "2026-03-21T15:00:00",
    ends_at: "2026-03-21T16:00:00",
    status: "confirmed",
    source: "manual"
  }) {
    uuid
    title
    starts_at
    ends_at
    status
    created_at
  }
}
```

### 5. Report Result

Show created entry with uuid, title, and times.

---

## List

```graphql
query {
  scheduleEntryList(limit: 50) {
    total
    items {
      uuid
      title
      starts_at
      ends_at
      status
      source
      created_at
    }
  }
}
```

Present as chronologically sorted table (by `starts_at`). If filter provided (e.g., "show cancelled entries"), apply as query filter or post-filter.

---

## Update

### 1. Resolve (resolve-first)

Fetch existing entries via `scheduleEntryList` query. Match the user's reference against returned entry titles using substring or fuzzy matching. If exactly one match, use it. If multiple, present options. If none, say so.

### 2. Determine Changes

From the user's request, determine which fields to change:
- "reschedule X to tomorrow at 2pm" -> update `starts_at` and `ends_at`
- "cancel X" -> update `status: "cancelled"` (not deletion)
- "change notes on X to ..." -> update `notes`
- "move X to Friday" -> update `starts_at` date, keep time

### 3. Confirm Before/After

```
Update schedule entry "Call with Sarah"?
  starts_at: 2026-03-21T15:00:00  ->  2026-03-22T14:00:00
  ends_at:   2026-03-21T16:00:00  ->  2026-03-22T15:00:00
```

### 4. Call API

```graphql
mutation {
  updateScheduleEntry(id: "uuid-here", input: {
    starts_at: "2026-03-22T14:00:00",
    ends_at: "2026-03-22T15:00:00"
  }) {
    uuid
    title
    starts_at
    ends_at
    status
    updated_at
  }
}
```

### 5. Report Result

Show updated entry with changed fields highlighted.

---

## Delete

### 1. Resolve (resolve-first)

Fetch existing entries via `scheduleEntryList` query. Match the user's reference against returned entry titles. If exactly one match, use it. If multiple, present options. If none, say so.

### 2. Show What Will Be Deleted

```
Delete schedule entry?
  title: "Call with Sarah"
  uuid: abc-123
  starts_at: 2026-03-21T15:00:00
  status: confirmed
```

### 3. Require Title Echo-Back

Ask user to type the entry title to confirm deletion: "Type 'Call with Sarah' to confirm deletion."

Do NOT accept just "yes" for delete operations.

### 4. Call API

```graphql
mutation {
  deleteScheduleEntry(id: "uuid-here") {
    success
  }
}
```

### 5. Report Result

Confirm deletion with the entry title and uuid.

---

## Judgment Points

- Confirm field values before create
- Confirm before/after on update
- Require title echo-back on delete
- Convert relative dates/times to absolute before API calls
- When "cancel" is used, prefer `status: cancelled` update over deletion (preserves history)

## Error Handling

- **GraphQL errors**: Surface the error message to the user. Do not swallow errors or retry silently.
- **Not found**: If the resolved UUID returns a not-found error, the entity may have been deleted since resolution. Say so.
- **Access denied**: If the API rejects an operation, surface "Access denied" with the operation attempted. Do not retry or work around.
- **Validation errors**: If the API returns field validation errors, show the error and ask the user to correct the input.

## Quality Checklist

- [ ] Intent parsed correctly
- [ ] Times converted to ISO 8601
- [ ] Correct GraphQL mutation/query used
- [ ] Confirmation shown before mutating operations
- [ ] "Cancel" defaults to status update, not deletion
- [ ] API errors surfaced to user
- [ ] For update/delete: resolved against existing entities first (not parsed from sentence)
- [ ] No files or directories created
