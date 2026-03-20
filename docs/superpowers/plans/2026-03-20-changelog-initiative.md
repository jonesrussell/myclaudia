# Changelog Initiative Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add Keep a Changelog format changelogs and a portable release script to all 13 workspace projects.

**Architecture:** A single canonical `scripts/release.sh` is written once and copied identically to each project. Each project gets a backfilled `CHANGELOG.md` generated from git history. Projects without git repos (goforms, goformx-laravel, rainbow) get changelog + script scaffolded for when they become independent repos.

**Tech Stack:** Bash (git, gh CLI, sed)

**Spec:** `docs/superpowers/specs/2026-03-20-changelog-initiative-design.md`

---

## Task 1: Write the canonical release script

**Files:**
- Create: `/home/jones/dev/claudriel/scripts/release.sh`

This is the master copy. All other projects get an identical copy.

- [ ] **Step 1: Write `scripts/release.sh`**

Based on Waaseyaa's existing script, enhanced with CHANGELOG.md manipulation and pre-release version support.

```bash
#!/usr/bin/env bash
# Release script: validates, updates CHANGELOG.md, tags, pushes, and optionally creates GitHub release.
# Usage: scripts/release.sh <version>  (e.g., scripts/release.sh v1.0.0 or v0.1.0-alpha.5)
set -euo pipefail

VERSION="${1:?Usage: scripts/release.sh <version>}"
SEMVER="${VERSION#v}"

# Validate semver (vX.Y.Z or vX.Y.Z-prerelease.N)
[[ "$VERSION" =~ ^v[0-9]+\.[0-9]+\.[0-9]+(-.+)?$ ]] || { echo "ERROR: version must match vX.Y.Z or vX.Y.Z-pre.N"; exit 1; }

# Must be on main
BRANCH=$(git branch --show-current)
[ "$BRANCH" = "main" ] || { echo "ERROR: must be on main (currently $BRANCH)"; exit 1; }

# Must be clean
[ -z "$(git status --porcelain)" ] || { echo "ERROR: working tree is not clean"; exit 1; }

# Must be synced with origin
git fetch origin main --quiet
LOCAL=$(git rev-parse HEAD)
REMOTE=$(git rev-parse origin/main)
[ "$LOCAL" = "$REMOTE" ] || { echo "ERROR: local main differs from origin/main"; exit 1; }

# Tag must not exist
git rev-parse "$VERSION" > /dev/null 2>&1 && { echo "ERROR: tag $VERSION already exists"; exit 1; }

# CHANGELOG.md must exist and have Unreleased content
[ -f CHANGELOG.md ] || { echo "ERROR: CHANGELOG.md not found"; exit 1; }
grep -q '## \[Unreleased\]' CHANGELOG.md || { echo "ERROR: no [Unreleased] section in CHANGELOG.md"; exit 1; }

# Check [Unreleased] has content (not just empty headers)
UNRELEASED_CONTENT=$(sed -n '/^## \[Unreleased\]/,/^## \[/{/^## \[/d;/^$/d;/^### /d;p;}' CHANGELOG.md)
[ -n "$UNRELEASED_CONTENT" ] || { echo "ERROR: [Unreleased] section has no entries"; exit 1; }

# Extract unreleased section for tag message
TAG_MSG=$(sed -n '/^## \[Unreleased\]/,/^## \[/{/^## \[Unreleased\]/d;/^## \[/d;p;}' CHANGELOG.md)

# Portable sed in-place (GNU vs BSD)
sedi() {
    if sed --version >/dev/null 2>&1; then
        sed -i "$@"
    else
        sed -i '' "$@"
    fi
}

# Rename [Unreleased] to [X.Y.Z] and insert fresh [Unreleased]
DATE=$(date +%Y-%m-%d)
sedi "s/^## \[Unreleased\]/## [${SEMVER}] - ${DATE}/" CHANGELOG.md
sedi "/^## \[${SEMVER}\] - ${DATE}/i\\
## [Unreleased]\\
" CHANGELOG.md

echo "=== Release $VERSION ==="
echo ""
echo "Changes:"
echo "$TAG_MSG"
echo ""

# Commit changelog, tag, push
git add CHANGELOG.md
git commit -m "chore: release ${VERSION}"
git tag -a "$VERSION" -m "Release ${VERSION}

${TAG_MSG}"
git push origin main "$VERSION"
echo "Tag $VERSION pushed."

# GitHub release (optional)
if command -v gh > /dev/null 2>&1; then
    read -rp "Create GitHub release? [y/N] " confirm
    if [[ "$confirm" =~ ^[Yy]$ ]]; then
        gh release create "$VERSION" --title "Release ${VERSION}" --notes "$TAG_MSG" --verify-tag
        echo "GitHub release created."
    fi
else
    echo "NOTICE: gh CLI not found — create GitHub release manually."
fi
```

- [ ] **Step 2: Make executable**

Run: `chmod +x scripts/release.sh`

- [ ] **Step 3: Test the script validates correctly**

Run these and confirm each fails with the expected error:
```bash
./scripts/release.sh           # "Usage: ..."
./scripts/release.sh foo       # "ERROR: version must match..."
./scripts/release.sh v1.0.0    # "ERROR: must be on main" (if on feature branch)
```

- [ ] **Step 4: Commit**

```bash
git add scripts/release.sh
git commit -m "chore: add portable release script"
```

---

## Task 2: Standardize Claudriel changelog

**Files:**
- Modify: `/home/jones/dev/claudriel/CHANGELOG.md`

Claudriel already has a CHANGELOG.md with an `[Unreleased]` section. Verify format matches the spec, add Semantic Versioning reference to header.

- [ ] **Step 1: Verify existing CHANGELOG.md format**

Read `/home/jones/dev/claudriel/CHANGELOG.md`. Check:
- Header references Keep a Changelog AND Semantic Versioning
- `[Unreleased]` section exists with entries

- [ ] **Step 2: Fix header if needed**

Ensure the header matches:
```markdown
# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
```

- [ ] **Step 3: Commit**

```bash
git add CHANGELOG.md
git commit -m "chore: standardize changelog format"
```

---

## Task 3: Standardize Waaseyaa

**Files:**
- Modify: `/home/jones/dev/waaseyaa/scripts/release.sh`
- Modify: `/home/jones/dev/waaseyaa/CHANGELOG.md`
- Remove: `/home/jones/dev/waaseyaa/RELEASE_NOTES.md`

- [ ] **Step 1: Replace release.sh with canonical version**

Copy `/home/jones/dev/claudriel/scripts/release.sh` to `/home/jones/dev/waaseyaa/scripts/release.sh`.

- [ ] **Step 2: Merge RELEASE_NOTES.md content into CHANGELOG.md**

Read both files. If RELEASE_NOTES.md has content not already in CHANGELOG.md, merge it into the appropriate version section. Then delete RELEASE_NOTES.md.

- [ ] **Step 3: Verify CHANGELOG.md header format**

Same header check as Task 2.

- [ ] **Step 4: Commit**

```bash
cd /home/jones/dev/waaseyaa
git add scripts/release.sh CHANGELOG.md
git rm RELEASE_NOTES.md
git commit -m "chore: standardize changelog and release script"
```

---

## Task 4: Standardize north-cloud

**Files:**
- Modify: `/home/jones/dev/north-cloud/CHANGELOG.md`
- Create: `/home/jones/dev/north-cloud/scripts/release.sh`

North-cloud has an existing CHANGELOG.md (7KB) and a scripts/ directory.

- [ ] **Step 1: Copy canonical release.sh**

Copy from claudriel. `chmod +x`.

- [ ] **Step 2: Verify CHANGELOG.md header format**

Same header check. Fix if needed.

- [ ] **Step 3: Commit**

```bash
cd /home/jones/dev/north-cloud
git add scripts/release.sh CHANGELOG.md
git commit -m "chore: add release script, standardize changelog"
```

---

## Task 5: Backfill streetcode-laravel

**Files:**
- Create: `/home/jones/dev/streetcode-laravel/CHANGELOG.md`
- Create: `/home/jones/dev/streetcode-laravel/scripts/release.sh`

- [ ] **Step 1: Generate changelog from git history**

```bash
cd /home/jones/dev/streetcode-laravel
git log --oneline --no-merges | head -50
```

Group commits into Added/Changed/Fixed categories. Write a `[0.1.0] - YYYY-MM-DD` section (date = most recent commit) plus an empty `[Unreleased]` section.

- [ ] **Step 2: Write CHANGELOG.md**

Use the standard header followed by backfilled content.

- [ ] **Step 3: Copy canonical release.sh**

Copy from claudriel. `chmod +x`.

- [ ] **Step 4: Commit**

```bash
git add CHANGELOG.md scripts/release.sh
git commit -m "chore: add changelog and release script"
```

---

## Task 6: Backfill northcloud-laravel

Same pattern as Task 5.

**Files:**
- Create: `/home/jones/dev/northcloud-laravel/CHANGELOG.md`
- Create: `/home/jones/dev/northcloud-laravel/scripts/release.sh`

- [ ] **Step 1: Generate changelog from git history**
- [ ] **Step 2: Write CHANGELOG.md**
- [ ] **Step 3: Copy canonical release.sh, chmod +x**
- [ ] **Step 4: Commit**: `chore: add changelog and release script`

---

## Task 7: Backfill goforms

**Files:**
- Create: `/home/jones/dev/goforms/CHANGELOG.md`
- Create: `/home/jones/dev/goforms/scripts/release.sh`

Note: Verify this is a git repo first. If not, scaffold the files for future use.

- [ ] **Step 1: Check git status, generate changelog from history**
- [ ] **Step 2: Write CHANGELOG.md**
- [ ] **Step 3: Copy canonical release.sh, chmod +x**
- [ ] **Step 4: Commit**: `chore: add changelog and release script`

---

## Task 8: Backfill goformx-laravel

Same pattern. Verify git repo status first.

**Files:**
- Create: `/home/jones/dev/goformx-laravel/CHANGELOG.md`
- Create: `/home/jones/dev/goformx-laravel/scripts/release.sh`

- [ ] **Step 1: Check git status, generate changelog from history**
- [ ] **Step 2: Write CHANGELOG.md**
- [ ] **Step 3: Copy canonical release.sh, chmod +x**
- [ ] **Step 4: Commit**: `chore: add changelog and release script`

---

## Task 9: Backfill coforge

**Files:**
- Create: `/home/jones/dev/coforge/CHANGELOG.md`
- Create: `/home/jones/dev/coforge/scripts/release.sh`

- [ ] **Step 1: Generate changelog from git history**
- [ ] **Step 2: Write CHANGELOG.md**
- [ ] **Step 3: Copy canonical release.sh, chmod +x**
- [ ] **Step 4: Commit**: `chore: add changelog and release script`

---

## Task 10: Backfill movies-of-war.com

**Files:**
- Create: `/home/jones/dev/movies-of-war.com/CHANGELOG.md`
- Create: `/home/jones/dev/movies-of-war.com/scripts/release.sh`

- [ ] **Step 1: Generate changelog from git history**
- [ ] **Step 2: Write CHANGELOG.md**
- [ ] **Step 3: Copy canonical release.sh, chmod +x**
- [ ] **Step 4: Commit**: `chore: add changelog and release script`

---

## Task 11: Backfill diidjaaheer

**Files:**
- Create: `/home/jones/dev/diidjaaheer/CHANGELOG.md`
- Create: `/home/jones/dev/diidjaaheer/scripts/release.sh`

- [ ] **Step 1: Generate changelog from git history**
- [ ] **Step 2: Write CHANGELOG.md**
- [ ] **Step 3: Copy canonical release.sh, chmod +x**
- [ ] **Step 4: Commit**: `chore: add changelog and release script`

---

## Task 12: Backfill orewire-laravel

**Files:**
- Create: `/home/jones/dev/orewire-laravel/CHANGELOG.md`
- Create: `/home/jones/dev/orewire-laravel/scripts/release.sh`

- [ ] **Step 1: Generate changelog from git history**
- [ ] **Step 2: Write CHANGELOG.md**
- [ ] **Step 3: Copy canonical release.sh, chmod +x**
- [ ] **Step 4: Commit**: `chore: add changelog and release script`

---

## Task 13: Backfill mp-emailer

**Files:**
- Create: `/home/jones/dev/mp-emailer/CHANGELOG.md`
- Create: `/home/jones/dev/mp-emailer/scripts/release.sh`

- [ ] **Step 1: Generate changelog from git history**
- [ ] **Step 2: Write CHANGELOG.md**
- [ ] **Step 3: Copy canonical release.sh, chmod +x**
- [ ] **Step 4: Commit**: `chore: add changelog and release script`

---

## Task 14: Backfill rainbow/12angrymen

**Files:**
- Create: `/home/jones/dev/rainbow/CHANGELOG.md`
- Create: `/home/jones/dev/rainbow/scripts/release.sh`

Note: Verify git repo status. If a subdirectory without its own repo, scaffold files for future use.

- [ ] **Step 1: Check git status, generate changelog from history**
- [ ] **Step 2: Write CHANGELOG.md**
- [ ] **Step 3: Copy canonical release.sh, chmod +x**
- [ ] **Step 4: Commit**: `chore: add changelog and release script`

---

## Task 15: Create reusable changelog skill

**Files:**
- Create: `/home/jones/dev/skills/changelog/SKILL.md`

After all projects are set up, create a reusable Claude Code skill at `~/dev/skills/changelog/` that can be used in any repo to maintain changelogs and run releases. See user request.

- [ ] **Step 1: Write the skill file**

The skill should cover:
- How to write changelog entries (format, categories, issue references)
- When to update the changelog (after completing work, before committing)
- How to run a release (scripts/release.sh usage)
- Backfill instructions for new projects

- [ ] **Step 2: Commit**

```bash
cd /home/jones/dev/skills
git add changelog/SKILL.md
git commit -m "feat: add changelog maintenance skill"
```
