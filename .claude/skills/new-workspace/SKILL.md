---
name: new-workspace
description: "Full workspace lifecycle management via Claudriel's GraphQL API: create, list, update, delete workspaces. Use when user says \"new workspace\", \"create workspace\", \"list workspaces\", \"show workspaces\", \"delete workspace\", \"remove workspace\", \"rename workspace\", \"update workspace\", or references any workspace CRUD operation."
effort-level: medium
---

# Workspace Management

Full CRUD lifecycle for Claudriel Workspace entities via GraphQL API.

**Architecture**: This skill orchestrates user intent and calls the GraphQL API at `POST /graphql`. It does NOT create directories, manipulate files, or bypass the entity layer. See `_templates/entity-crud.md` for the base pattern.

## Trigger

- "New workspace", "Create a workspace for...", "Set up a project"
- "List workspaces", "Show my workspaces", "What workspaces do I have?"
- "Rename workspace...", "Update workspace...", "Change workspace status"
- "Delete workspace...", "Remove workspace...", "Get rid of that workspace"

## Operation Detection

| Signal | Operation |
|--------|-----------|
| "create", "new", "set up", "start" | **Create** |
| "list", "show", "what workspaces" | **List** |
| "rename", "update", "change", "edit" | **Update** |
| "delete", "remove", "get rid of", "nuke" | **Delete** |

If ambiguous, ask. Default assumption for bare workspace mentions is **not** delete.

## GraphQL Fields

```
uuid name description account_id tenant_id metadata repo_path repo_url branch codex_model last_commit_hash ci_status project_id mode status created_at updated_at
```

---

## Intent Parsing

Intent parsing differs by operation type. **Update/delete resolve against existing entities first; create parses from the user's sentence.**

### For Update and Delete (resolve-first)

1. **Fetch existing workspaces** via `workspaceList` query before parsing the name
2. **Match the user's reference** against the returned workspace names using substring or fuzzy matching. Workspace names may contain conjunctions, prepositions, or full sentences.
   - "delete the 'and clone jonesrussell/me' workspace" → match against list, find "and clone jonesrussell/me so we can do some milestone planning"
   - "remove old test" → match against list, find "old test workspace"
3. **If exactly one match**, use it. If multiple, present options. If none, say so.
4. **Do NOT split the user's input on conjunctions or prepositions** when resolving existing entities. The entity name may contain any words.

### For Create (parse from sentence)

1. **Extract the workspace name**: The name is usually the first noun phrase or project identifier. Stop at conjunctions ("and", "so", "then") or prepositions that introduce secondary intents.
   - "create a workspace for Acme Corp and link the repo" → name: "Acme Corp"
   - "new workspace jonesrussell/me so we can plan" → name: "me" (from the repo name)
2. **Extract secondary intents** and queue them for after the primary operation:
   - `milestone planning` / `roadmap` / `planning` → open milestone planning after setup
   - `for <person/entity>` → set as contact/sponsor
   - `clone <owner/repo>` → note: repo cloning is a separate concern from workspace entity creation
3. **Never use the full user sentence as a field value.** If unsure what the name should be, ask.

---

## Create

### 1. Gather Fields

Ask for anything not already extracted. Skip fields the user already provided:

| Field | GraphQL Input | Required | Default |
|-------|--------------|----------|---------|
| **name** | `name` | Yes | — |
| **description** | `description` | No | `""` |
| **mode** | `mode` | No | `"persistent"` |
| **status** | `status` | No | `"active"` |

### 2. Confirm

Show the fields before creating:
```
Create workspace "Acme Corp"?
  description: "Client engagement for redesign project"
  mode: persistent
  status: active
```

### 3. Call API

```graphql
mutation {
  createWorkspace(input: {
    name: "Acme Corp",
    description: "Client engagement for redesign project",
    mode: "persistent",
    status: "active"
  }) {
    id
    uuid
    name
    status
    created
  }
}
```

### 4. Execute Secondary Intents

After creation, execute any queued secondary intents using the new workspace's UUID.

### 5. Report

```
Workspace created: Acme Corp
UUID: abc-123-def
Status: active

What's next?
```

---

## List

### 1. Call API

```graphql
query {
  workspaceList(limit: 50) {
    total
    items {
      id
      uuid
      name
      status
      mode
      description
      created
    }
  }
}
```

### 2. Present

| Name | Status | Mode | Created |
|------|--------|------|---------|
| Acme Corp | active | persistent | 2026-03-15 |
| Personal Site | active | persistent | 2026-03-20 |

If filters were requested (e.g., "show active workspaces"), apply them in the query or post-filter.

---

## Update

### 1. Resolve Workspace

Match the user's reference against existing workspaces:
- Search by name via `workspaceList` with filter
- If exactly one match, use it
- If multiple matches, present them and ask
- If no matches, say so

### 2. Determine Changes

Extract what the user wants to change:
- "rename X to Y" → `name` field
- "mark X as archived" → `status` field
- "update description" → `description` field

### 3. Confirm

Show before/after:
```
Update workspace "Acme Corp" (uuid: abc-123):
  name: "Acme Corp" → "Acme Corporation"
```

### 4. Call API

```graphql
mutation {
  updateWorkspace(id: "abc-123-uuid", input: {
    name: "Acme Corporation"
  }) {
    id
    uuid
    name
    status
  }
}
```

### 5. Report Result

---

## Delete

### 1. Resolve Workspace

Same resolution as Update.

### 2. Show What Will Be Deleted

```
Delete workspace "Old Test Project"?
  UUID: xyz-789
  Status: active
  Created: 2026-01-10
```

### 3. Require Explicit Confirmation

For destructive operations, require the workspace name echoed back:

"Type the workspace name to confirm deletion: **Old Test Project**"

Do NOT proceed on just "yes".

### 4. Call API

```graphql
mutation {
  deleteWorkspace(id: "xyz-789-uuid") {
    success
  }
}
```

### 5. Report Result

"Workspace 'Old Test Project' deleted."

---

## Error Handling

- **GraphQL errors**: Surface the error message to the user. Do not swallow errors or retry silently.
- **Not found**: If the resolved UUID returns a not-found error, the entity may have been deleted since resolution. Say so.
- **Access denied**: If the API rejects an operation, surface "Access denied" with the operation attempted. Do not retry or work around.
- **Validation errors**: If the API returns field validation errors, show the error and ask the user to correct the input.

## Judgment Points

- Confirm field values before create (show what will be sent)
- Confirm before/after on update
- Require name echo-back on delete
- If a workspace has linked entities (commitments, events), warn before deletion

## Quality Checklist

- [ ] Intent parsed correctly (name extracted, not full sentence)
- [ ] For update/delete: resolved against existing entities first (not parsed from sentence)
- [ ] Correct GraphQL mutation/query used
- [ ] Confirmation shown before mutating operations
- [ ] Delete requires name echo-back, not just "yes"
- [ ] Secondary intents executed after primary operation
- [ ] API errors surfaced to user, not swallowed
- [ ] No files or directories created
