---
name: commitment
description: "Full commitment lifecycle management via Claudriel's GraphQL API: create, list, update, delete commitments. Use when user says \"new commitment\", \"add commitment\", \"I owe\", \"they owe\", \"list commitments\", \"show commitments\", \"complete commitment\", \"delete commitment\", \"update commitment\", or references any commitment CRUD operation."
effort-level: medium
---

# Commitment Management

Full CRUD lifecycle for Claudriel Commitment entities via GraphQL API.

**Architecture**: This skill orchestrates user intent and calls the GraphQL API at `POST /graphql`. It does NOT manipulate files or storage directly. See `_templates/entity-crud.md` for the base pattern.

## Trigger

- "New commitment", "Add a commitment", "I owe [person] [thing]", "They owe me [thing]"
- "List commitments", "Show my commitments", "What do I owe?", "What's pending?"
- "Update commitment...", "Mark [thing] as complete", "Change due date"
- "Delete commitment...", "Remove commitment..."

## Operation Detection

| Signal | Operation |
|--------|-----------|
| "create", "new", "add", "I owe", "they owe", "commit to" | **Create** |
| "list", "show", "what commitments", "pending", "what do I owe" | **List** |
| "update", "change", "complete", "mark as", "reschedule", "edit" | **Update** |
| "delete", "remove", "cancel", "drop" | **Delete** |

If ambiguous, ask. Default assumption for bare commitment mentions is **not** delete.

## GraphQL Fields

```
uuid title status confidence direction due_date person_uuid source tenant_id created_at updated_at
```

---

## Intent Parsing

Intent parsing differs by operation type. **Update/delete resolve against existing entities first; create parses from the user's sentence.**

### For Update and Delete (resolve-first)

1. **Fetch existing commitments** via `commitmentList` query before parsing the title
2. **Match the user's reference** against the returned commitment titles using substring or fuzzy matching. Commitment titles may contain conjunctions, prepositions, or multi-word phrases.
   - "complete the proposal for Sarah" -> match against list, find "Send proposal to Sarah"
   - "mark feedback as done" -> match against list, find "Feedback on design"
   - "delete the follow up and review one" -> match against list, find "Follow up and review deliverables" (do NOT split on "and")
3. **If exactly one match**, use it. If multiple, present options. If none, say so.
4. **Do NOT split the user's input on conjunctions or prepositions** when resolving existing entities. The commitment title is whatever was stored at creation time.

### For Create (parse from sentence)

1. **Extract the commitment title/description**: The action or deliverable being committed to.
   - "I owe Sarah a proposal by Friday" -> title: "Send proposal to Sarah", direction: outbound, due_date: Friday
   - "they owe me feedback on the design" -> title: "Feedback on design", direction: inbound

2. **Extract inline field values**:
   - "I owe" -> `direction: outbound`
   - "they owe me" -> `direction: inbound`
   - "by [date]" / "due [date]" -> `due_date` field
   - Person references -> resolve to `person_uuid`

3. **Never use the full user sentence as the title.** Extract the actionable commitment.

---

## Create

### 1. Gather Fields

Ask for anything not already extracted. Keep it natural, don't interrogate:

| Field | GraphQL Input | Required | Default |
|-------|--------------|----------|---------|
| **title** | `title` | Yes | — |
| **direction** | `direction` | Yes | `"outbound"` |
| **status** | `status` | No | `"pending"` |
| **confidence** | `confidence` | No | `0.9` (manual = high confidence) |
| **due_date** | `due_date` | No | `null` |
| **person_uuid** | `person_uuid` | No | `null` |
| **source** | `source` | No | `"manual"` |

Valid statuses: `pending`, `active`, `completed`, `cancelled`
Valid directions: `outbound` (you owe them), `inbound` (they owe you)

### 2. Confirm

```
Create commitment?
  title: "Send proposal to Sarah"
  direction: outbound (I owe them)
  due_date: 2026-03-25
  person: Sarah Chen (uuid: abc-123)
  status: pending
```

### 3. Call API

```graphql
mutation {
  createCommitment(input: {
    title: "Send proposal to Sarah",
    direction: "outbound",
    due_date: "2026-03-25",
    person_uuid: "abc-123",
    status: "pending",
    confidence: 0.9,
    source: "manual"
  }) {
    uuid
    title
    direction
    status
    due_date
    created_at
  }
}
```

### 4. Report Result

---

## List

### 1. Call API

```graphql
query {
  commitmentList(limit: 50) {
    total
    items {
      uuid
      title
      status
      direction
      due_date
      person_uuid
      confidence
      created_at
    }
  }
}
```

### 2. Present

| Title | Direction | Status | Due Date |
|-------|-----------|--------|----------|
| Send proposal to Sarah | outbound | pending | 2026-03-25 |
| Feedback on design | inbound | active | — |

If filters requested (e.g., "show pending outbound"), apply in query or post-filter.

---

## Update

### 1. Resolve Commitment

Follow the resolve-first pattern from Intent Parsing above:
1. Fetch existing commitments via `commitmentList`
2. Match user's reference against returned titles
3. If exactly one match, use it
4. If multiple matches, present them and ask
5. If no matches, say so and offer to create

### 2. Determine Changes

Extract what the user wants to change:
- "mark X as complete" -> `status: completed`
- "reschedule X to next week" -> `due_date` field
- "change direction to inbound" -> `direction` field
- "rename to ..." -> `title` field

### 3. Confirm

```
Update commitment "Send proposal to Sarah" (uuid: abc-123):
  status: "pending" -> "completed"
```

### 4. Call API

```graphql
mutation {
  updateCommitment(id: "uuid", input: {
    status: "completed"
  }) {
    uuid
    title
    status
    direction
    due_date
  }
}
```

### 5. Report Result

---

## Delete

### 1. Resolve Commitment

Follow the resolve-first pattern from Intent Parsing above (same as Update).

### 2. Show What Will Be Deleted

```
Delete commitment "Send proposal to Sarah"?
  UUID: abc-123
  Direction: outbound
  Status: pending
  Due date: 2026-03-25
  Created: 2026-03-20
```

### 3. Require Explicit Confirmation

"Type the commitment title to confirm deletion: **Send proposal to Sarah**"

Do NOT proceed on just "yes".

### 4. Call API

```graphql
mutation {
  deleteCommitment(id: "abc-123-uuid") {
    success
  }
}
```

### 5. Report Result

"Commitment 'Send proposal to Sarah' deleted."

---

## Error Handling

- **GraphQL errors**: Surface the error message to the user. Do not swallow errors or retry silently.
- **Not found**: If the resolved UUID returns a not-found error, the entity may have been deleted since resolution. Say so.
- **Access denied**: If the API rejects an operation, surface "Access denied" with the operation attempted. Do not retry or work around.
- **Validation errors**: If the API returns field validation errors (e.g., invalid status value), show the error and ask the user to correct the input.

## Judgment Points

- Confirm field values before create
- Confirm before/after on update
- Require title echo-back on delete
- When direction is ambiguous ("commitment with Sarah"), ask: "Is this something you owe Sarah, or something Sarah owes you?"
- Convert relative dates to absolute dates before sending to API
- If a commitment has a linked person, warn before deletion

## Quality Checklist

- [ ] Intent parsed correctly (title extracted, not full sentence)
- [ ] Direction correctly inferred from "I owe" vs "they owe"
- [ ] For update/delete: resolved against existing entities first (not parsed from sentence)
- [ ] Correct GraphQL mutation/query used
- [ ] Confirmation shown before mutating operations
- [ ] Delete requires title echo-back, not just "yes"
- [ ] API errors surfaced to user, not swallowed
- [ ] No files or directories created
