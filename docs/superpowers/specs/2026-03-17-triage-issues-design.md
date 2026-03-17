# Triage Issues Skill Design

## Purpose

A two-tier skill for GitHub issue triage in Claudriel. Tier 1 runs automatically at session start (lightweight warnings). Tier 2 runs on manual invocation for full backlog analysis with actionable recommendations requiring per-action approval.

## Architecture

### Two-Tier Design

**Tier 1: Session Start Hook**
- Extends the existing `bin/check-milestones` behavior
- Runs automatically via SessionStart hook
- Fetches open issues and milestones via `gh` CLI
- Outputs warning lines only (matches current hook format)
- Target execution: under 5 seconds

**Tier 2: Manual `/triage-issues` Invocation**
- Full backlog analysis via `gh` CLI
- Groups issues by milestone, flags quality problems, detects potential duplicates
- Presents structured report (emoji-header format consistent with morning brief)
- Offers actions with per-action approval

### Data Flow

```
gh issue list --json number,title,body,updatedAt,milestone,state
gh api repos/{owner}/{repo}/milestones --jq
                    ↓
        ┌───────────┴───────────┐
   Tier 1 (hook)          Tier 2 (manual)
   Quick checks           Full analysis
   Warning lines          Structured report
                          Action offers
                               ↓ (approved)
                          gh issue edit
                          gh issue close
                          gh issue comment
```

## Tier 1: Session Start

### Checks Performed

1. **Missing milestones:** Open issues not assigned to any milestone
2. **Empty descriptions:** Issues with body empty or under 20 characters
3. **Stale issues:** Open issues with no update in 14+ days
4. **Stale milestones:** Open milestones with zero open issues

### Output Format

```
=== Issue Triage ===
⚠ 2 issues missing milestones: #42, #45
⚠ 1 issue has no description: #45
⚠ 3 stale issues (14+ days): #12, #18, #31
⚠ Stale milestones (no open issues): v1.6 Voice Input, v1.7 Speech Output
✓ 8 issues fully triaged
================
```

When all checks pass:
```
=== Issue Triage ===
✓ All 10 issues fully triaged
================
```

### Replaces `bin/check-milestones`

The skill's Tier 1 instructions supersede the current `bin/check-milestones` script. The existing script checks only milestones; Tier 1 adds description quality, staleness, and a fully-triaged count.

## Tier 2: Full Triage

### Report Sections

**📋 Milestone Health**
Each milestone with:
- Open issue count
- Percentage of stale issues (14+ days)
- Age of oldest open issue

**⚠️ Quality Gaps**
Issues failing the quality bar, grouped by failure type:
- No milestone assigned
- No description or description under 20 characters
- Stale (no activity 14+ days)

**🔍 Potential Duplicates**
Issue pairs with significant title keyword overlap. Uses word-level comparison after stripping common words (the, a, an, for, to, in, etc.). Flags pairs sharing 50%+ of significant title words.

**🎯 Action Queue**
Proposed actions, each presented individually for approval:
- "Assign #45 to milestone v1.5 OAuth?"
- "Close #12 as stale (no activity 45 days)?"
- "Comment on #31 requesting status update?"
- "Close milestone v1.6 Voice Input (no open issues)?"

### Action Approval Flow

Each action in the queue is presented one at a time. The skill:
1. Describes the action and its rationale
2. Waits for explicit approval ("yes", "skip", "stop")
3. Executes approved actions via `gh` CLI
4. Reports result before moving to next action

"Skip" moves to the next action. "Stop" ends the action queue.

## Quality Bar

| Check | Threshold | Tier 1 | Tier 2 |
|-------|-----------|--------|--------|
| Has milestone | Required | Warn | Offer to assign |
| Has description | >20 chars body | Warn | Offer to flag |
| Recent activity | <14 days since update | Warn | Offer to close/comment |
| Duplicate detection | 50%+ title word overlap | Skip | Flag pairs |
| Empty milestone | 0 open issues | Warn | Offer to close |

## Scope Boundaries

The skill does NOT:
- Auto-create issues
- Modify issue bodies (only adds comments)
- Manage labels (Claudriel does not currently use labels)
- Interact with PRs (separate concern)
- Take any action without per-action approval (Tier 2)
- Take any action at all in Tier 1 (warnings only)

## Skill Location

`~/.claude/skills/triage-issues/SKILL.md`

## Trigger Patterns

- Manual: "triage issues", "triage my issues", "check issues", "issue health", "backlog review"
- Automatic: SessionStart hook (Tier 1 only)

## Dependencies

- `gh` CLI authenticated with repo access
- Repository has GitHub issues enabled
- Milestone workflow established (per `docs/specs/workflow.md`)
