---
name: judgment-rule
description: "Full judgment rule lifecycle management via Claudriel's GraphQL API: create, list, update, delete judgment rules. Use when user says \"new rule\", \"add judgment rule\", \"remember this rule\", \"list rules\", \"show rules\", \"update rule\", \"delete rule\", \"remove rule\", or references any judgment rule CRUD operation."
effort-level: medium
---

# Judgment Rule Management

Full CRUD lifecycle for Claudriel JudgmentRule entities via GraphQL API.

**Architecture**: This skill orchestrates user intent and calls the GraphQL API at `POST /graphql`. It does NOT manipulate files or storage directly. See `_templates/entity-crud.md` for the base pattern.

## Trigger

- "New rule", "Add a judgment rule", "Remember this rule", "From now on..."
- "List rules", "Show judgment rules", "What rules do I have?"
- "Update rule...", "Change rule...", "Edit rule"
- "Delete rule...", "Remove rule...", "Forget this rule"

## Operation Detection

| Signal | Operation |
|--------|-----------|
| "create", "new", "add", "remember", "from now on" | **Create** |
| "list", "show", "what rules", "rules" (noun) | **List** |
| "update", "change", "edit", "refine" | **Update** |
| "delete", "remove", "forget", "drop" | **Delete** |
| "disable", "deactivate", "turn off" | **Update** (status change, not delete) |

If ambiguous, ask. Default assumption for bare rule mentions is **not** delete.

## GraphQL Fields

```
uuid rule_text context source confidence application_count last_applied_at status tenant_id created_at updated_at
```

---

## Intent Parsing

### For Update and Delete (resolve-first)

For **update** and **delete** operations, always resolve against existing entities **before** parsing the user's sentence for a rule_text.

1. Fetch existing rules via `judgmentRuleList` query (filter `status: active` unless user specifies otherwise)
2. Match the user's reference against returned `rule_text` fields using substring or fuzzy matching
3. If exactly one match, use it
4. If multiple matches, present them and ask which one
5. If no matches, say so and offer to create instead

**Do NOT split the user's input on conjunctions or heuristic word boundaries** when resolving existing entities. The rule_text is whatever was stored at creation time.

### For Create (parse from sentence)

Extract fields from the user's sentence using these heuristics:

1. **Extract the rule text**: The behavioral guideline or decision pattern.
   - "remember: always confirm before sending emails" -> rule_text: "Always confirm before sending emails"
   - "from now on, prioritize client calls over internal meetings" -> rule_text: "Prioritize client calls over internal meetings"

2. **Extract context** (optional):
   - "when scheduling" -> `context: scheduling`
   - "for client interactions" -> `context: client interactions`

3. **Strip filler words**: Never include preamble ("from now on", "remember that", "new rule:", "add a rule to") in the rule_text. Extract the core behavioral directive.

---

## Create

### 1. Parse Intent

Extract rule_text and context from the user's sentence (see "For Create" parsing above).

### 2. Gather Fields

| Field | GraphQL Input | Required | Default |
|-------|--------------|----------|---------|
| **rule_text** | `rule_text` | Yes | -- |
| **context** | `context` | No | `null` |
| **source** | `source` | No | `"manual"` |
| **confidence** | `confidence` | No | `0.9` |
| **status** | `status` | No | `"active"` |

### 3. Check for Conflicts

Before confirming, query existing rules and check if any active rule contradicts or overlaps with the new one. If a conflict exists, surface it:

> "This may conflict with an existing rule: *[existing rule_text]*. Create anyway?"

### 4. Confirm, then call API

Show the extracted fields and ask "Create this rule?"

```graphql
mutation {
  createJudgmentRule(input: {
    rule_text: "Always confirm before sending emails",
    context: "communication",
    source: "manual",
    confidence: 0.9,
    status: "active"
  }) {
    uuid
    rule_text
    context
    status
    created_at
  }
}
```

### 5. Report Result

Show created rule with UUID and key fields.

---

## List

```graphql
query {
  judgmentRuleList(limit: 50) {
    total
    items {
      uuid
      rule_text
      context
      confidence
      application_count
      last_applied_at
      status
      created_at
    }
  }
}
```

Present as table. Default filter: `status: active`.

| Rule | Context | Confidence | Applied |
|------|---------|------------|---------|
| Always confirm before sending emails | communication | 0.9 | 12x |
| Prioritize client calls | scheduling | 0.85 | 5x |

---

## Update

### 1. Resolve Entity (resolve-first)

Fetch existing rules via `judgmentRuleList` query and match the user's reference against returned `rule_text` fields (see "For Update and Delete" parsing above).

### 2. Determine Changes

From the user's request, determine which fields to change:

Common patterns:
- "refine the email rule" -> update `rule_text`
- "disable the scheduling rule" -> `status: "inactive"` (NOT deletion)
- "boost confidence on the client rule" -> update `confidence`
- "add context to the email rule" -> update `context`

### 3. Confirm Before/After

Show the current values and proposed new values:

> **Before:** rule_text: "Always confirm before sending emails"
> **After:** rule_text: "Always confirm before sending emails or Slack messages"
>
> Apply this change?

### 4. Call API

```graphql
mutation {
  updateJudgmentRule(id: "uuid", input: {
    rule_text: "Always confirm before sending emails or Slack messages"
  }) {
    uuid
    rule_text
    context
    confidence
    status
    updated_at
  }
}
```

### 5. Report Result

Show updated rule with changed fields highlighted.

---

## Delete

### 1. Resolve Entity (resolve-first)

Fetch existing rules via `judgmentRuleList` query and match the user's reference against returned `rule_text` fields (see "For Update and Delete" parsing above).

### 2. Show Details

Display the full rule that will be deleted:

> **Rule:** "Always confirm before sending emails"
> **Context:** communication | **Confidence:** 0.9 | **Applied:** 12 times

### 3. Require Echo-back

Require the user to echo back the first few words of the rule_text to confirm deletion:

> To confirm deletion, type the first few words of the rule (e.g., "Always confirm").

### 4. Call API

```graphql
mutation {
  deleteJudgmentRule(id: "uuid") {
    success
  }
}
```

### 5. Report Result

Confirm the rule has been deleted.

---

## Judgment Points

- Confirm rule text before create (rules shape future behavior)
- Confirm before/after on update
- Require echo-back on delete
- "Disable" should map to `status: inactive`, not deletion (preserves history)
- Strip preamble ("from now on", "remember that") from rule_text
- When a rule contradicts an existing rule, surface the conflict

## Error Handling

- **GraphQL errors**: Surface the error message to the user. Do not swallow errors or retry silently.
- **Not found**: If the resolved UUID returns a not-found error, the entity may have been deleted since resolution. Say so.
- **Access denied**: If the API rejects an operation, surface "Access denied" with the operation attempted. Do not retry or work around.
- **Validation errors**: If the API returns field validation errors, show the error and ask the user to correct the input.

## Quality Checklist

- [ ] Rule text extracted cleanly (no filler words)
- [ ] Correct GraphQL mutation/query used
- [ ] Confirmation shown before mutating operations
- [ ] "Disable" maps to status update, not deletion
- [ ] Conflicts with existing rules surfaced
- [ ] API errors surfaced to user
- [ ] For update/delete: resolved against existing entities first (not parsed from sentence)
- [ ] No files or directories created
