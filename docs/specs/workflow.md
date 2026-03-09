# GitHub Workflow Specification

## Repository

`jonesrussell/claudriel` — Claudriel, AI personal operations system

## Versioning Model

Pre-1.0 project. No formal versioning yet. Development is issue-driven with features merged directly to `main`.

## Milestone List

| # | Title | Status | Issues |
|---|-------|--------|--------|
| 2 | v0.2 — Daily Use | OPEN | #9, #12, #13, #14 |

## Issue History

| # | Status | Milestone | Description |
|---|--------|-----------|-------------|
| 14 | OPEN | v0.2 | feat: session tracking for Day Brief |
| 13 | OPEN | v0.2 | feat: commitment actions (done/ignore/track) |
| 12 | OPEN | v0.2 | feat: Day Brief v1 sections and grouping |
| 9 | OPEN | v0.2 | chore: wire CLI commands into ConsoleKernel |
| 8 | CLOSED | — | feat: Day Brief assembler |
| 7 | CLOSED | — | feat: Web Day Brief view |
| 6 | CLOSED | — | feat: CLI commands (brief + commitments) |
| 5 | CLOSED | — | feat: Drift detector |
| 4 | CLOSED | — | feat: Event handler |
| 3 | CLOSED | — | feat: Commitment handler |
| 2 | CLOSED | — | feat: Commitment extraction pipeline step |
| 1 | CLOSED | — | feat: Gmail message normalizer |

## The 5 Workflow Rules

1. **All work begins with an issue** — ask for issue number before writing code; create one if missing
2. **Every issue belongs to a milestone** — unassigned issues are incomplete triage (currently no milestones)
3. **Milestones define the roadmap** — check active milestone before proposing work; don't invent new ones without discussion
4. **PRs must reference issues** — title format `feat(#N): description`, body with `Closes #N`
5. **Claude reads the drift report** — flag `bin/check-milestones` warnings before beginning work

## Branch Strategy

Feature branches off `main`. PR to `main`. Direct push only for trivial fixes.

## Keeping This Spec Updated

When milestones are created or closed, update the **Milestone List** table above.
When issue #9 is resolved, update the Issue History table.
