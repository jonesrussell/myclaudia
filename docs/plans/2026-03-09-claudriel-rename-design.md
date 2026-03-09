# Design: Rename MyClaudia → Claudriel

## Context

MyClaudia is being renamed to Claudriel — the character identity for the AI personal operations system. The rename covers PHP namespaces, CLI commands, env vars, docs, templates, MCP servers, deploy scripts, install paths, and the GitHub repo itself.

## Approach

Layered rename across 4 PRs, bottom-up. Each PR is independently testable.

## PR 1: PHP Namespace + Composer

The foundation layer. All other PRs depend on this.

| What | From | To |
|------|------|----|
| Namespace | `MyClaudia\` | `Claudriel\` |
| Composer package | `jonesrussell/myclaudia` | `jonesrussell/claudriel` |
| PSR-4 autoload | `"MyClaudia\\": "src/"` | `"Claudriel\\": "src/"` |
| Service provider | `McClaudiaServiceProvider` | `ClaudrielServiceProvider` |
| `storage/framework/packages.php` | `McClaudia` refs | `Claudriel` refs |

**Files**: 34 src/ + 26 tests/ + composer.json + packages.php = ~62 files.

**Validation**: `composer dump-autoload`, run full test suite.

## PR 2: CLI Commands + Env Vars

| What | From | To |
|------|------|----|
| CLI commands (4) | `myclaudia:*` | `claudriel:*` |
| Env vars | `MYCLAUDIA_API_KEY`, `MYCLAUDIA_API_URL`, `MYCLAUDIA_ROOT` | `CLAUDRIEL_API_KEY`, `CLAUDRIEL_API_URL`, `CLAUDRIEL_ROOT` |
| `.env` + `.env.example` | `MYCLAUDIA_*` | `CLAUDRIEL_*` |
| Env reads in PHP | `getenv('MYCLAUDIA_*')` | `getenv('CLAUDRIEL_*')` |
| `bin/mc-ingest` | `MYCLAUDIA_*` | `CLAUDRIEL_*` |

**Files**: 4 commands + ~10 controllers/configs + 2 env files + 1 shell script + tests.

**Validation**: Run test suite, verify CLI commands list correctly.

## PR 3: Templates, Docs, MCP Servers

| What | From | To |
|------|------|----|
| Twig templates (4) | "MyClaudia" titles | "Claudriel" |
| `CLAUDE.md` | "MyClaudia" refs | "Claudriel" |
| `CLAUDE.user.md` | "MyClaudia" personality | "Claudriel" |
| `docs/specs/` (3 files) | "MyClaudia" refs | "Claudriel" |
| `docs/plans/` | Leave as-is (historical) | — |
| MCP server names (2) | `myclaudia-*` | `claudriel-*` |
| MCP package.json (2) | `myclaudia-*` | `claudriel-*` |
| `.claude/settings.json` | `myclaudia-*` keys | `claudriel-*` |
| Character image | — | Add `public/images/claudriel.png` |
| Auto-memory | `MEMORY.md` | Update references |

**Validation**: Visual check of templates, MCP servers start correctly.

## PR 4: Repo Rename + Install Path + Deploy Scripts

| What | From | To |
|------|------|----|
| `bin/deploy` | `~/myclaudia` | `~/claudriel` |
| `bin/setup-install` | target `~/myclaudia` | `~/claudriel` |
| CI workflow | repo name refs | updated |

**Post-merge manual steps:**
1. Merge PR 4
2. `gh repo rename claudriel`
3. `mv ~/dev/myclaudia ~/dev/claudriel`
4. `mv ~/myclaudia ~/claudriel`
5. Update git remotes in both copies
6. Update `MEMORY.md` project paths

## Milestone

Create GitHub milestone "v0.4 — Claudriel Rename" with 4 issues (one per PR).

## Risk

- PR 1 is the riskiest — namespace changes break autoloading if incomplete. Full test suite validates.
- Env var rename (PR 2) requires updating `~/claudriel/.env` in the installed copy.
- GitHub repo rename (PR 4) auto-redirects old URLs but local remotes need manual update.
