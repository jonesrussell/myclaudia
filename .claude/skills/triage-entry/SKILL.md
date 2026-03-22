---
name: triage-entry
description: "Full triage entry lifecycle management via Claudriel's GraphQL API: create, list, update, delete triage entries. Not to be confused with triage-issues (GitHub issue triage). Use when user says \"new triage entry\", \"triage this\", \"list triage\", \"show triage queue\", \"dismiss triage\", \"process triage\", \"delete triage entry\", or references any triage CRUD operation."
effort-level: medium
---

# Triage Entry Management

Full CRUD lifecycle for Claudriel TriageEntry entities via GraphQL API.

**Architecture**: This skill orchestrates user intent and calls the GraphQL API at `POST /graphql`. It does NOT manipulate files or storage directly. See `_templates/entity-crud.md` for the base pattern.

## Trigger

- "New triage entry", "Triage this", "Add to triage queue"
- "List triage", "Show triage queue", "What needs triaging?", "Pending triage"
- "Process triage...", "Dismiss triage...", "Update triage entry"
- "Delete triage entry...", "Remove from triage"

## Operation Detection

| Signal | Operation |
|--------|-----------|
| "create", "new", "add", "triage this" | **Create** |
| "list", "show", "queue", "pending", "what needs" | **List** |
| "update", "process", "dismiss", "defer", "act on" | **Update** |
| "delete", "remove", "purge" | **Delete** |

If ambiguous, ask. Default assumption for bare triage mentions is **not** delete.

## GraphQL Fields

```
uuid sender_name sender_email summary status source tenant_id occurred_at external_id content_hash raw_payload created_at updated_at
```

---

## Intent Parsing

### For Update and Delete (resolve-first)

1. **Fetch existing entries** via `triageEntryList` query BEFORE parsing the user's sentence for a name.
2. **Match** the user's reference against returned `sender_name` AND `summary` fields using substring/fuzzy matching.
3. If exactly one match, use it. If multiple matches, present options and ask. If no matches, say so (and offer to create for update, or report not found for delete).
4. **Do NOT split input on conjunctions** when resolving existing entities. The sender_name and summary are whatever was stored at creation time.

### For Create (parse from sentence)

1. **Extract sender_name**: the person or source the triage entry is about.
   - "triage this email from Sarah about the proposal" -> sender_name: "Sarah", summary: "about the proposal"
   - "new triage entry: meeting request from James Wright" -> sender_name: "James Wright", summary: "meeting request"
2. **Extract summary**: the subject or topic, separate from the sender.
3. **Extract status intent** (if provided):
   - "dismiss" -> `status: dismissed`
   - "process" / "act on" -> `status: processed`
   - "defer" -> `status: deferred`
   - Default for create: `status: pending`
4. **Never use the full sentence as the summary or sender_name.**

---

## Create

### 1. Gather Fields

| Field | GraphQL Input | Required | Default |
|-------|--------------|----------|---------|
| **sender_name** | `sender_name` | Yes | -- |
| **sender_email** | `sender_email` | No | `null` |
| **summary** | `summary` | Yes | -- |
| **status** | `status` | No | `"pending"` |
| **source** | `source` | No | `"manual"` |
| **occurred_at** | `occurred_at` | No | now |

### 2. Confirm, then call API

Show the extracted fields and ask "Create this triage entry?"

```graphql
mutation {
  createTriageEntry(input: {
    sender_name: "Sarah Chen",
    sender_email: "sarah@acme.com",
    summary: "Proposal review request",
    status: "pending",
    source: "manual"
  }) {
    uuid
    sender_name
    summary
    status
    created_at
  }
}
```

### 3. Report result

Show created entry with UUID and status.

---

## List

```graphql
query {
  triageEntryList(limit: 50) {
    total
    items {
      uuid
      sender_name
      sender_email
      summary
      status
      occurred_at
      created_at
    }
  }
}
```

Present as table sorted by occurred_at. Default filter: `status: pending`.

---

## Update

### 1. Resolve entity (resolve-first)

Fetch existing entries via `triageEntryList` query. Match user's reference against `sender_name` and `summary` fields.

```graphql
query {
  triageEntryList(limit: 50) {
    total
    items {
      uuid
      sender_name
      summary
      status
    }
  }
}
```

- If exactly one match, use it.
- If multiple matches, present options with sender_name, summary, and status. Ask user to pick.
- If no matches, say so and offer to create instead.

### 2. Determine changes

Common patterns:
- "dismiss the triage from Sarah" -> `status: dismissed`
- "process the proposal triage" -> `status: processed`
- "defer the meeting request" -> `status: deferred`
- "update sender email on..." -> field-level update

### 3. Confirm before/after

Show the current values and the proposed changes side by side.

### 4. Call API

```graphql
mutation {
  updateTriageEntry(id: "resolved-uuid", input: {
    status: "dismissed"
  }) {
    uuid
    sender_name
    summary
    status
    updated_at
  }
}
```

### 5. Report result

Show updated entry confirming the change.

---

## Delete

### 1. Resolve entity (resolve-first)

Fetch existing entries via `triageEntryList` query. Match user's reference against `sender_name` and `summary` fields.

### 2. Show what will be deleted

Present the matched entry's sender_name, summary, status, and UUID.

### 3. Require echo-back confirmation

Require the user to echo back the sender_name AND summary (not just "yes"). Example:

> This will permanently delete the triage entry from **Sarah Chen** about **Proposal review request**. To confirm, reply with the sender name and summary.

### 4. Call API

```graphql
mutation {
  deleteTriageEntry(id: "resolved-uuid") {
    success
  }
}
```

### 5. Report result

Confirm the entry was deleted with sender_name and summary for clarity.

---

## Error Handling

- **GraphQL errors**: Surface the error message to the user. Do not swallow errors or retry silently.
- **Not found**: If the resolved UUID returns a not-found error, the entity may have been deleted since resolution. Say so.
- **Access denied**: If the API rejects an operation, surface "Access denied" with the operation attempted. Do not retry or work around.
- **Validation errors**: If the API returns field validation errors, show the error and ask the user to correct the input.

---

## Judgment Points

- Confirm field values before create
- Confirm before/after on update
- Require identifying details echo-back on delete
- Default list filter should be pending items (most useful view)
- "Dismiss" and "process" are status updates, not deletions

## Quality Checklist

- [ ] Intent parsed correctly
- [ ] Correct GraphQL mutation/query used
- [ ] Confirmation shown before mutating operations
- [ ] Delete requires echo-back confirmation
- [ ] "Dismiss" maps to status update, not deletion
- [ ] API errors surfaced to user
- [ ] For update/delete: resolved against existing entities first (not parsed from sentence)
- [ ] No files or directories created
