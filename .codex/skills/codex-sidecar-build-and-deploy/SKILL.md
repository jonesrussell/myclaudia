---
name: codex-sidecar-build-and-deploy
description: Use when Claudriel changes affect the Python sidecar container and Codex needs the local build, CI, and production deployment workflow.
---

# Codex Sidecar Build And Deploy

Use this skill when the task touches the sidecar service or any production deploy path that depends on it.

## Rebuild The Sidecar Container

- Dockerfile: `/home/jones/dev/claudriel/docker/sidecar/Dockerfile`
- Source root: `/home/jones/dev/claudriel/docker/sidecar/`
- Compose file: `/home/jones/dev/claudriel/docker-compose.sidecar.yml`

Production deploys rebuild the sidecar with:

- `docker compose -f docker-compose.sidecar.yml --env-file .env up -d --build`

## Where The Dockerfile Lives

- `/home/jones/dev/claudriel/docker/sidecar/Dockerfile`

## How GitHub Actions Builds And Deploys It

- CI sidecar job lives in `/home/jones/dev/claudriel/.github/workflows/ci.yml`
- Production deploy lives in `/home/jones/dev/claudriel/.github/workflows/deploy.yml`
- Push to `main` triggers the deploy workflow.
- The workflow deploys the PHP app, then SSHes to production.
- On production it stages:
  - `current/docker-compose.sidecar.yml`
  - `current/docker/sidecar/` into `sidecar/docker-context/`
- It extracts required env vars from `shared/.env`
- It rebuilds and starts the sidecar with Docker Compose

## Test The Container Locally In WSL

Use the repo sources directly for code-level checks:

- `cd /home/jones/dev/claudriel/docker/sidecar`
- `pip install -r requirements.txt`
- `ruff check app/`
- `pytest`

For a container-level local check, mirror production staging:

1. Create a temporary directory.
2. Copy `docker-compose.sidecar.yml` into it.
3. Copy `docker/sidecar/` to `docker-context/`.
4. Provide required env vars.
5. Run `docker compose -f docker-compose.sidecar.yml up --build`

## Verify Production After Deploy

Check the smallest relevant surface:

- GitHub Actions deploy status
- sidecar health if exposed
- Claudriel behavior that depends on the sidecar
- production logs if deploy output reports failure

If production verification requires SSH, do it only when the user explicitly allows production access.

## Deployment Rules

- Sidecar container must be rebuilt on every deploy.
- Do not assume an app-only deploy is enough when sidecar-related code changed.
- Prefer GitHub Actions over manual production builds.
