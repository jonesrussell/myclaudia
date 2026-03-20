# Changelog Initiative Design

Cross-project changelog and release script rollout for all 12 workspace projects.

## Goal

Establish consistent, human-written changelogs and a portable release script across every project in the workspace. Changelogs track what changed and why; the release script automates the tagging ceremony.

## CHANGELOG.md Format

All projects use [Keep a Changelog v1.1.0](https://keepachangelog.com/en/1.1.0/) with [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

```markdown
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
### Changed
### Fixed
### Removed

## [X.Y.Z] - YYYY-MM-DD

### Added
- Feature description (#issue)
```

### Entry rules

- One entry per issue or PR, not per commit. Reference the issue number.
- Group by category: Added, Changed, Fixed, Removed. Drop empty categories.
- `[Unreleased]` is always present at the top and accumulates work between releases.
- Entries are human-written in clear, user-facing language, not raw commit messages.

## Release Script

A portable `scripts/release.sh`, identical across all 12 projects. No per-project customization. Based on Waaseyaa's existing script, simplified for cross-ecosystem use (Go, PHP, TypeScript).

### Dependencies

`git`, `gh` (GitHub CLI), `sed`. No runtime or language-specific tooling. The script targets GNU sed; a portability shim handles BSD/macOS `sed -i` differences.

### What it does

1. Validates version argument matches semver (`vX.Y.Z` or `vX.Y.Z-alpha.N`).
2. Ensures clean working tree on main branch, synced with origin.
3. Checks that `[Unreleased]` section in CHANGELOG.md has content.
4. Renames `[Unreleased]` to `[X.Y.Z] - YYYY-MM-DD` and inserts a fresh empty `[Unreleased]` section.
5. Commits the changelog update.
6. Creates an annotated git tag with the changelog section as the tag message.
7. Pushes commit and tag to origin.
8. Optionally creates a GitHub release via `gh release create` (prompts for confirmation).

### What it does NOT do

- Update `composer.json` or `package.json` version fields (project-specific, handle separately).
- Build, test, or deploy anything (CI's responsibility).
- Require any runtime dependencies beyond git, gh, and sed.

## Backfill Strategy

### Projects with existing tags (Waaseyaa)

Backfill is straightforward: one changelog section per existing tag, generated from `git log` between tags and consolidated per issue.

### Projects without tags (most of them)

Create a single `[0.1.0] - YYYY-MM-DD` entry capturing the current state. The date is the most recent significant commit. No fake historical versions. Proper versioning begins going forward.

### Process per project

1. Find natural version boundaries (tags, milestone commits, or date-based chunks).
2. Group commits by type: `feat(` to Added, `fix(` to Fixed, `refactor(/chore(` to Changed. Skip `docs(` unless significant.
3. Consolidate per issue: multiple commits referencing the same issue become one entry.
4. Human-edit the result into clear, user-facing descriptions.

### Exception

Claudriel already has an `[Unreleased]` section with content. Keep it as-is and ensure it follows the format.

## Rollout Order

Three tiers based on existing state and development activity.

### Tier 1: Already have changelogs (verify format, add release script)

| Project | Type | Notes |
|---------|------|-------|
| Waaseyaa | PHP monorepo | Has CHANGELOG.md, RELEASE_NOTES.md, release.sh. Standardize script. Remove RELEASE_NOTES.md (content merged into CHANGELOG.md). |
| Claudriel | PHP/Waaseyaa | Has CHANGELOG.md. Add release script, verify format. |

### Tier 2: Active development (backfill, add release script)

| Project | Type | Notes |
|---------|------|-------|
| north-cloud | Go microservices | |
| goforms | Go API | |
| goformx-laravel | Laravel SPA | |
| streetcode-laravel | Laravel SPA | |
| northcloud-laravel | PHP+Vue package | Shared package used by 5 consumer apps |

### Tier 3: Lower activity (backfill, add release script)

| Project | Type | Notes |
|---------|------|-------|
| coforge | Laravel SPA (DDEV) | |
| movies-of-war.com | Laravel SPA (DDEV) | |
| diidjaaheer | Laravel SPA | |
| orewire-laravel | Laravel SPA (DDEV) | |
| mp-emailer | Go CLI | |
| rainbow/12angrymen | TypeScript/Vite | |

### Deliverables per project

- `CHANGELOG.md` (backfilled from git history)
- `scripts/release.sh` (identical copy)
- One commit: `chore: add changelog and release script`

## Out of scope

- CI/CD integration (auto-release on tag push) is a future enhancement, not part of this initiative.
- Automated changelog generation from commits (we chose human-written entries).
- Version bumping in package manifests (project-specific concern).
