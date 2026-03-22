# LLM-Judge and Trajectory Evals — Design Spec

**Date:** 2026-03-22
**Issue:** #445
**Milestone:** Agent Reliability & Architecture v1
**Phase:** 1 (continued), builds on #444 (deterministic eval framework)
**Status:** Draft

---

## 1. Problem Statement

The deterministic eval framework (#444) validates eval YAML structure and schema consistency. It catches structural regressions but cannot assess qualitative behavior: is the confirmation natural? Is the error message helpful? Did the skill correctly disambiguate? Did a multi-step flow complete correctly?

Issue #445 adds the LLM-judge and trajectory eval layers to complete the eval stack.

## 2. Goals

1. Define LLM-judge rubrics for all 6 entity CRUD skills with numeric scoring (0-5).
2. Build a promptfoo-based eval runner with a custom Anthropic API provider that simulates skill execution with tool schemas.
3. Support trajectory evals (full create-list-update-delete lifecycle per entity type).
4. Support multi-turn evals (conversation context preservation: pronoun resolution, follow-ups).
5. Integrate scoring into the coverage report from #444.
6. Keep cost manageable: Sonnet for subject, Haiku for judge, run 3x and average.

## 3. Non-Goals

- CI integration for LLM evals (deferred to #446).
- GraphQL schema drift detection (deferred to #447).
- Model routing table (deferred to #449).
- Modifying the deterministic validator or existing `basic.yaml` eval files.
- Running evals on every PR (LLM evals run nightly only).

## 4. Execution Model

### 4.1 Architecture

```
promptfoo test runner
  → claudriel-skill-provider.js (custom provider)
    → reads .claude/skills/<skill>/SKILL.md (path resolved from test file's `skill` field)
    → reads evals/schemas/<skill>.json (relative to project root)
    → calls Anthropic Messages API with:
        - system: [skill instructions + entity-crud template]
        - tools: [GraphQL mutation/query schemas as tool definitions]
        - messages: [user input from test case]
    → for multi-turn: maintains message array, injects mock_response as tool results
    → returns: {
        output: assistant response,
        tool_calls: parsed tool call array,
        metadata: { model, skill, turns }
      }
  → promptfoo assertion layer
    → deterministic assertions (contains, tool_call checks)
    → llm-rubric assertions (Haiku judges output against rubric)
```

### 4.2 Model Configuration

Models are configurable per-skill in the eval YAML, with global defaults in `promptfooconfig.yaml`.

| Role | Default Model | Purpose |
|------|--------------|---------|
| Subject | `claude-sonnet-4-6` | Executes the skill (matches production behavior) |
| Judge | `claude-haiku-4-5-20251001` | Scores output against rubric (cheap, fast) |

Per-skill override in eval files:

```yaml
subject_model: sonnet
judge_model: haiku
```

**Alias lookup table** (resolved by the provider):

| Alias | Full Model ID |
|-------|--------------|
| `sonnet` | `claude-sonnet-4-6` |
| `haiku` | `claude-haiku-4-5-20251001` |
| `opus` | `claude-opus-4-6` |

Full model IDs are also accepted directly.

### 4.3 Tool Schema Injection

Each skill's GraphQL operations are defined as Anthropic tool schemas in `evals/schemas/<skill>.json`. These are static JSON files that describe the mutations and queries the skill can call. The model produces tool call requests; no actual GraphQL execution happens.

Example tool definition for commitment:

```json
{
  "name": "createCommitment",
  "description": "Create a new commitment entity",
  "input_schema": {
    "type": "object",
    "properties": {
      "title": { "type": "string" },
      "direction": { "type": "string", "enum": ["outbound", "inbound"] },
      "status": { "type": "string", "enum": ["active", "pending", "completed"] },
      "due_date": { "type": "string", "format": "date" },
      "person_uuid": { "type": "string" }
    },
    "required": ["title"]
  }
}
```

### 4.4 Mock Tool Responses

For trajectory and multi-turn evals, the provider needs to return synthetic tool results so the model can continue the conversation. Mock responses are defined per-turn in the eval YAML (`mock_response` field) and also have skill-level defaults in `evals/mocks/<skill>.json`.

Per-turn mocks take precedence over skill-level defaults.

### 4.5 Multi-Turn Provider Behavior

For `eval_type: trajectory` and `eval_type: multi-turn`, the provider:

1. Reads all `turns[]` from the test case
2. For each turn:
   a. Appends the user message to the conversation
   b. Calls the Anthropic API with the full message history
   c. Parses the assistant response and any tool calls
   d. If the model made a tool call, looks up the `mock_response` for that turn
   e. Appends the tool result to the conversation
   f. If the model needs to continue (tool result received), calls the API again (max 3 API calls per turn to prevent unbounded inner loops)
3. After all turns, returns the full conversation + metadata
4. Aborts if user turn count exceeds `max_turns` (default: 10) or if any single turn exceeds 3 API round-trips

## 5. LLM-Judge Rubric Format

### 5.1 Rubric File Structure

Rubric files live at `evals/rubrics/<skill>.yaml`.

```yaml
version: "1.0"
skill: commitment
inherits: _base

# Inheritance: provider loads _base.yaml first, then merges this file's criteria array.
# If a skill-specific criterion has the same `name` as a base criterion, it overrides the base version.
# Otherwise, skill-specific criteria are appended to the base list.

criteria:
  # Skill-specific criteria (in addition to inherited base criteria)
  - name: direction-detection
    weight: 1
    description: "Did the skill correctly detect outbound vs inbound direction from context clues?"
    scoring:
      5: "Correct direction detected from natural language cues (I owe = outbound, they owe = inbound)."
      3: "Correct direction but only after explicit prompt, not from initial input."
      1: "Wrong direction assigned."
      0: "Direction field omitted entirely."
```

### 5.2 Base Rubric (`_base.yaml`)

All 6 CRUD skills inherit these 5 criteria:

| Criterion | Weight | What it measures |
|-----------|--------|-----------------|
| `intent-extraction` | 2 | Correct operation and field extraction from natural language |
| `confirmation-quality` | 1 | Natural, complete confirmation before mutations |
| `resolve-first-correctness` | 2 | For update/delete: fetches list before parsing, correct matching |
| `error-handling` | 1 | Clear error messages, offers alternatives |
| `tool-call-correctness` | 2 | Correct GraphQL operation with correct arguments |

Each criterion is scored 0-5 per the rubric's scoring guide.

### 5.3 Skill-Specific Criteria

| Skill | Extra Criteria |
|-------|---------------|
| commitment | `direction-detection` (outbound vs inbound) |
| judgment-rule | `filler-stripping` (removes "from now on", "remember that") |
| triage-entry | `dismiss-vs-delete-distinction` (maps "dismiss" to status update) |
| new-workspace | (base only) |
| new-person | (base only) |
| schedule-entry | (base only) |

### 5.4 Scoring

- Haiku judges each criterion independently, returning a 0-5 score
- Composite score = sum(score_i * weight_i) / sum(weight_i). For base-only skills (total weight 8), a perfect score is 5.0. For skills with extras (e.g., commitment, total weight 9), the denominator adjusts automatically.
- Each eval runs 3 times; scores are averaged across runs for consistency
- **Pass threshold:** composite >= 3.5
- **Regression threshold:** score drop > 15% from baseline triggers a warning

## 6. Eval YAML Extensions

### 6.1 New `eval_type` Field

The `eval_type` field distinguishes eval categories:

| eval_type | Runner | Description |
|-----------|--------|-------------|
| `basic` (or absent) | `php bin/eval-validate` | Deterministic schema validation (existing) |
| `trajectory` | promptfoo + custom provider | Full CRUD lifecycle flows |
| `multi-turn` | promptfoo + custom provider | Conversation context preservation |

### 6.2 Trajectory Eval Format

```yaml
schema_version: "1.0"
skill: commitment
entity_type: commitment
eval_type: trajectory
max_turns: 10

subject_model: sonnet
judge_model: haiku

tests:
  - name: full-crud-lifecycle
    description: "Create, list, update, delete a commitment"
    turns:
      - input: "I owe Sarah a proposal by Friday"
        operation: create
        assertions:
          - type: graphql_operation
            operation: createCommitment
          - type: confirmation_shown
          - type: direction_detected
            direction: outbound
        mock_response:
          createCommitment:
            uuid: "test-001"
            title: "Send proposal to Sarah"
            direction: outbound
            status: active

      - input: "show my commitments"
        operation: list
        assertions:
          - type: graphql_operation
            operation: commitmentList
            mutation: false
        mock_response:
          commitmentList:
            items:
              - uuid: "test-001"
                title: "Send proposal to Sarah"
                direction: outbound
                status: active

      - input: "mark the proposal as complete"
        operation: update
        assertions:
          - type: resolve_first
          - type: before_after_shown
          - type: graphql_operation
            operation: updateCommitment
        mock_response:
          updateCommitment:
            uuid: "test-001"
            status: completed

      - input: "delete the proposal commitment"
        operation: delete
        assertions:
          - type: resolve_first
          - type: echo_back_required
            field: title
          - type: graphql_operation
            operation: deleteCommitment
        mock_response:
          deleteCommitment:
            success: true

    rubric: commitment
    tags: [trajectory, lifecycle]
```

### 6.3 Multi-Turn Eval Format

```yaml
schema_version: "1.0"
skill: new-person
entity_type: person
eval_type: multi-turn
max_turns: 10

subject_model: sonnet
judge_model: haiku

tests:
  - name: pronoun-resolution
    description: "Create a person, then update using pronouns"
    turns:
      - input: "add Sarah Chen, she's VP of Engineering at Acme"
        operation: create
        assertions:
          - type: graphql_operation
            operation: createPerson
          - type: field_extraction
            field: name
            should_match: "Sarah Chen"
        mock_response:
          createPerson:
            uuid: "test-002"
            name: "Sarah Chen"

      - input: "change her email to sarah@newco.com"
        operation: update
        assertions:
          - type: resolve_first
          - type: graphql_operation
            operation: updatePerson
          - type: field_extraction
            field: email
            should_match: "sarah@newco.com"
        mock_response:
          updatePerson:
            uuid: "test-002"
            email: "sarah@newco.com"

    rubric: new-person
    tags: [multi-turn, pronoun-resolution]
```

## 7. File Layout

```
evals/
├── promptfooconfig.yaml              # Global config
├── providers/
│   └── claudriel-skill-provider.js   # Custom Anthropic API provider
├── rubrics/
│   ├── _base.yaml                    # Shared 5 criteria
│   ├── commitment.yaml               # Base + direction-detection
│   ├── judgment-rule.yaml            # Base + filler-stripping
│   ├── new-workspace.yaml            # Base only
│   ├── new-person.yaml               # Base only
│   ├── schedule-entry.yaml           # Base only
│   └── triage-entry.yaml             # Base + dismiss-vs-delete
├── schemas/
│   ├── commitment.json               # GraphQL tool schemas
│   ├── judgment-rule.json
│   ├── new-workspace.json
│   ├── new-person.json
│   ├── schedule-entry.json
│   └── triage-entry.json
├── mocks/
│   ├── commitment.json               # Default mock tool responses
│   ├── judgment-rule.json
│   ├── new-workspace.json
│   ├── new-person.json
│   ├── schedule-entry.json
│   └── triage-entry.json
└── scores/
    └── baseline.json                 # Last-known-good scores

.claude/skills/<skill>/evals/
├── basic.yaml                        # Deterministic (existing)
├── trajectory.yaml                   # Trajectory evals (new)
└── multi-turn.yaml                   # Multi-turn evals (new)
```

## 8. promptfoo Configuration

```yaml
# evals/promptfooconfig.yaml

defaultTest:
  options:
    provider:
      id: file://providers/claudriel-skill-provider.js
    scoring:
      model: claude-haiku-4-5-20251001

env:
  ANTHROPIC_API_KEY: "{{ANTHROPIC_API_KEY}}"

defaults:
  subject_model: claude-sonnet-4-6
  judge_model: claude-haiku-4-5-20251001
  max_turns: 10
  runs: 3

testMatch:
  - .claude/skills/*/evals/trajectory*.yaml
  - .claude/skills/*/evals/multi-turn*.yaml
```

## 9. Integration with Deterministic Validator

The deterministic validator (`php bin/eval-validate`) needs a minor update to handle the new eval types:

- Files with `eval_type: trajectory` or `eval_type: multi-turn` are validated for structural correctness (top-level fields, test names, tags).
- The validator gains a new rule: `TrajectorySchemaRule` that checks:
  - `turns[]` array is present and non-empty
  - Each turn has `input` (required) and `operation` (required, same enum as basic evals)
  - Each turn's `assertions[]` are validated against `AssertionCompatibilityRule` using the turn's `operation` field
  - `mock_response` is present for all turns except the last
  - `rubric` field references a valid rubric file
  - `max_turns` is a positive integer if present
- Coverage report includes trajectory and multi-turn test counts per skill.

## 10. Resolved Design Decisions

1. **Execution model:** promptfoo orchestration + custom Anthropic API provider with tool schemas (not Claude Code CLI, not system-prompt-only).
2. **Models:** Configurable per-skill. Default: Sonnet subject, Haiku judge.
3. **Rubric format:** YAML with version field, inheritable base criteria, weighted scoring 0-5.
4. **Mock responses:** Per-turn in eval YAML, with skill-level defaults in `evals/mocks/`.
5. **Provider return shape:** Includes `metadata: { model, skill, turns }` for debugging.
6. **Safety cap:** `max_turns` field (default 10) prevents runaway evals. Per-turn inner loop capped at 3 API calls.
7. **Test discovery:** `testMatch` globs in promptfooconfig.yaml for zero-arg runs (wildcard pattern for extensibility).
8. **Per-turn operation field:** Each turn in trajectory/multi-turn evals declares its `operation` for assertion compatibility validation (consistent with #444).
9. **Model aliases:** Short aliases (`sonnet`, `haiku`, `opus`) mapped to full model IDs by the provider.
10. **Rubric inheritance:** Provider loads `_base.yaml` first, merges skill-specific criteria. Same-name criteria override, others append.
11. **Path resolution:** Provider resolves skill files at `.claude/skills/<skill>/SKILL.md`, schemas at `evals/schemas/<skill>.json`, both relative to project root.

## 11. Acceptance Criteria (from #445)

- [ ] LLM-judge rubrics defined for all 6 CRUD skills
- [ ] Rubric scoring produces numeric scores (0-5 per criterion)
- [ ] Trajectory evals cover full CRUD lifecycle for each entity type
- [ ] Multi-turn evals test context preservation (pronoun resolution, follow-up operations)
- [ ] Score thresholds documented: composite >= 3.5 pass, >15% regression = warning
- [ ] Results integrated into coverage report from #444
