---
name: codex-environment-core
description: Use at the start of any Claudriel task to establish the canonical WSL2 environment, deployment model, production boundaries, and safety rules.
---

# Codex Environment Core

Use this skill as the default operating environment for all Claudriel work.

## Environment Assumptions

- Codex runs inside WSL2 on the developer machine.
- Project root: `/home/jones/dev/claudriel/`
- Production server: `deployer@claudriel.northcloud.one`
- Production Caddyfile: `/home/deployer/claudriel/Caddyfile`
- Sidecar container must be rebuilt and deployed for any production changes.
- GitHub Actions deploys on push to `main`.
- Codex may use SSH for production hotfixes, but canonical deployment is via GitHub Actions.

## Allowed Actions

- Read, write, and modify files in the repo.
- Create branches, commit, and push.
- Run commands inside WSL.
- SSH into production only when explicitly instructed.
- Never modify server config outside the defined Claudriel paths.

## Directory Map

- Repo: `/home/jones/dev/claudriel/`
- Production app: `/home/deployer/claudriel/`
- Production Caddyfile: `/home/deployer/claudriel/Caddyfile`
- Sidecar build context: `/home/jones/dev/claudriel/docker/sidecar/`
- Sidecar compose file: `/home/jones/dev/claudriel/docker-compose.sidecar.yml`
- GitHub Actions CI workflow: `/home/jones/dev/claudriel/.github/workflows/ci.yml`
- GitHub Actions deploy workflow: `/home/jones/dev/claudriel/.github/workflows/deploy.yml`

## Deployment Rules

- All normal work must go through PR -> merge -> GitHub Actions -> production.
- Sidecar container must be rebuilt on every deploy.
- SSH is allowed only for:
  - reading production state
  - applying temporary hotfixes
  - validating the Caddyfile or reading logs
- If a hotfix is applied over SSH, the equivalent repo change must follow before the next normal deploy.

## Safety Constraints

- Never delete files outside the repo.
- Never modify unrelated server config.
- Never assume Laravel conventions.
- Always confirm before running destructive commands.

## Operating Checklist

1. Start from `/home/jones/dev/claudriel/`.
2. Inspect relevant files before editing.
3. Keep the change scoped to the user request.
4. Run the smallest relevant checks.
5. Commit only the intended diff.
6. Prefer repo-driven deployment unless the user explicitly requests a production hotfix.
