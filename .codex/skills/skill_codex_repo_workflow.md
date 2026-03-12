---
name: codex-repo-workflow
description: Use for normal Claudriel repo work: branch naming, committing, syncing with main, local checks, pushing, and PR flow.
---

# Codex Repo Workflow

Use this skill for all non-hotfix Claudriel development work.

## Branch Naming Conventions

- Default prefix: `codex/`
- Format: `codex/<short-topic>`
- Examples:
  - `codex/remove-dashboard-auto-submit`
  - `codex/environment-skill-core`

## Commit Message Format

- Use a short imperative subject line.
- Keep the first line focused on the user-visible change.
- Examples:
  - `Stop dashboard auto-submitting morning brief`
  - `Add Codex environment skills`

## Sync With Main

1. Start from `main` when possible.
2. Pull the latest `main` before branching if it reduces merge risk.
3. Prefer a clean worktree when the current one is dirty.
4. Do not rewrite unrelated local work.

## Run Local Checks Before Pushing

For PHP app changes:

- `composer lint`
- `composer analyse`
- `composer test`

For sidecar changes under `docker/sidecar/`:

- `cd docker/sidecar`
- `ruff check app/`
- `pytest`

If checks are skipped, say exactly why.

## Push And Open PRs

1. Stage only intended files.
2. Commit with a focused subject.
3. Push with upstream tracking: `git push -u origin <branch>`
4. If GitHub CLI is available and the user wants a PR: `gh pr create --fill`

## Constraints

- Do not include unrelated local modifications in the commit.
- Do not amend commits unless the user explicitly asks.
- Do not push directly to `main` unless the user explicitly requests it.
