# Claudriel v1.0 Workflow

## Purpose

This document is the canonical workflow guide for the v1.0 multi-tenant foundation. It consolidates the operational guidance from the v1.0 plan, the tenant/workspace boundary model, the smoke matrix, and the current deploy implementation so Codex and human contributors follow the same path.

## Source Of Truth

- Milestone sequencing: [v1.0-plan.md](/home/fsd42/dev/claudriel/v1.0-plan.md)
- Tenant and workspace model: [tenant-workspace-boundaries.md](/home/fsd42/dev/claudriel/docs/tenant-workspace-boundaries.md)
- Smoke surfaces: [v1.0-smoke-matrix.md](/home/fsd42/dev/claudriel/tests/smoke/v1.0-smoke-matrix.md)
- Temporal agent runtime and operations: [temporal-agents.md](/home/fsd42/dev/claudriel/docs/specs/temporal-agents.md)
- Public account lifecycle and operations: [public-accounts.md](/home/fsd42/dev/claudriel/docs/specs/public-accounts.md)
- Public homepage and app-entry contract: [public-entry-funnel.md](/home/fsd42/dev/claudriel/docs/specs/public-entry-funnel.md)
- Production deploy orchestration: [deploy.php](/home/fsd42/dev/claudriel/deploy.php)
- CI and production entrypoint: [deploy.yml](/home/fsd42/dev/claudriel/.github/workflows/deploy.yml)

If this document conflicts with an older planning or workflow draft, prefer the files above.

## Local Development Workflow

### Working Conventions

- Start from the repo root: `/home/fsd42/dev/claudriel`.
- Inspect the current worktree before editing because Claudriel work often spans multiple open phases.
- Keep changes scoped to one issue or one operational task.
- Prefer issue-driven work with explicit progress comments when the task maps to a GitHub issue.
- Treat the repo as a multi-tenant application, even in local single-tenant development.

### Branch And PR Flow

- Standard path: branch from `main`, commit focused changes, open a PR, merge, let GitHub Actions deploy.
- Do not push directly to `main` unless explicitly requested.
- Do not close issues or modify milestones unless the task explicitly calls for it.
- When updating an issue, record concrete implementation progress rather than speculative plans after the work is already in motion.

### Local Checks

For PHP application changes:

- `composer lint`
- `composer analyse`
- `composer test`

For sidecar changes under `docker/sidecar/`:

- `cd docker/sidecar && ruff check app/`
- `cd docker/sidecar && pytest`

Run the smallest relevant subset first, then expand if the change crosses boundaries.

## Local Smoke Checks

The v1.0 smoke surfaces are defined in [v1.0-smoke-matrix.md](/home/fsd42/dev/claudriel/tests/smoke/v1.0-smoke-matrix.md). Local smoke checks should target the same surfaces whenever possible.

For the public signup and account system, also use [v1.2-public-account-smoke-matrix.md](/home/fsd42/dev/claudriel/tests/smoke/v1.2-public-account-smoke-matrix.md) and [public-accounts.md](/home/fsd42/dev/claudriel/docs/specs/public-accounts.md) as the source of truth for onboarding, reset, and deploy-validation expectations.

For the marketing-homepage and app-entry split, also use [v1.3-public-entry-funnel-smoke-matrix.md](/home/fsd42/dev/claudriel/tests/smoke/v1.3-public-entry-funnel-smoke-matrix.md) and [public-entry-funnel.md](/home/fsd42/dev/claudriel/docs/specs/public-entry-funnel.md).

### Serve The App Locally

- Repo-native path: `bin/serve <port>`
- Lightweight PHP path when Docker is unnecessary: `php -d variables_order=EGPCS -S 127.0.0.1:<port> -t public public/index.php`

### Minimum Local Smoke Set

- Dashboard render: `GET /`
- Brief JSON: `GET /brief` with `Accept: application/json`
- Brief stream: `GET /stream/brief`
- Chat send: `POST /api/chat/send`
- Chat stream: `GET /stream/chat/{message_id}`
- Invalid route fail-closed behavior: request an unknown route near the brief or dashboard surfaces and expect `404`

For proactive temporal-agent changes, also use the smoke-style validation sequence documented in [temporal-agents.md](/home/fsd42/dev/claudriel/docs/specs/temporal-agents.md).

### Expected Local Caveats

- Chat may fail with `503` when `ANTHROPIC_API_KEY` or sidecar-backed chat configuration is intentionally absent. That is an acceptable fail-closed smoke result in local development.
- Chat POST requires valid CSRF state from the dashboard flow. Browser-driven smoke checks are more representative than raw `curl` for the happy path.
- Local single-tenant data still needs to respect tenant/workspace resolution rules. Do not interpret a successful local default-tenant run as permission to widen scope in code.

## Deploy Validation Workflow

### Canonical Production Path

Production deploys are driven by GitHub Actions and finalized by Deployer:

1. GitHub Actions verify job runs PHP and sidecar checks.
2. GitHub Actions deploy job assembles the artifact and runs `dep deploy production --no-interaction -vv`.
3. [deploy.php](/home/fsd42/dev/claudriel/deploy.php) performs production-side orchestration.
4. `deploy:validate` fails closed if the promoted release is unhealthy.

### What `deploy:validate` Checks

- sidecar health at `http://127.0.0.1:8100/health`
- public brief reachability at `https://claudriel.northcloud.one/brief`
- public brief JSON payload shape
- public signup and login probes with live CSRF extraction
- public homepage CTA markers at `/`
- anonymous app-shell redirect behavior at `/app`
- public chat send and chat stream behavior using the negative workspace-delete probe

The deploy validation logs are expected to surface in the GitHub Actions UI because the deploy job runs Deployer with verbose output.

### Local Approximation

There is no full local equivalent of `dep deploy production` because the production task graph assumes the real server layout and the public Caddy endpoint. For local approximation:

- run the local smoke set against a served app
- run the sidecar lint/test suite if sidecar behavior changed
- treat production-only validation as owned by GitHub Actions plus `deploy.php`, not by ad hoc local shell scripts

## Tenant And Workspace Development Rules

The boundary model from [tenant-workspace-boundaries.md](/home/fsd42/dev/claudriel/docs/tenant-workspace-boundaries.md) applies to everyday development work.

### Required Rules

- Resolve `tenant_id` before resolving `workspace_id`.
- Scope every workspace lookup by tenant.
- Scope every repository query and mutation by tenant, and by workspace where the surface is workspace-aware.
- Fail closed on mismatched, missing, or cross-tenant workspace access.
- Pass `tenant_id` and `workspace_id` through chat and sidecar boundaries instead of re-deriving them from global state.

### Practical Guidance

- Do not write code that assumes workspace names are globally unique.
- Do not accept client-provided workspace identifiers without tenant-scoped verification.
- Keep null workspace as a valid tenant-scoped state where the model allows it.
- Treat smoke surfaces as routing contracts. If a change breaks brief, dashboard, chat, or stream behavior, it is a workflow problem, not only a product bug.

## Codex Operating Rules

Codex should use this repo with explicit sequencing and limited drift.

### Task Execution

- Read the active issue, the v1.0 plan, and any referenced source-of-truth docs before editing.
- Use the issue dependency order from [v1.0-plan.md](/home/fsd42/dev/claudriel/v1.0-plan.md) when deciding what to start next.
- Prefer implementation over extended speculation once the task is clear.
- Update the relevant GitHub issue with concrete progress after meaningful changes land.

### Issue And Milestone Handling

- Do not modify milestones, labels, or issue state unless the task explicitly requires it.
- Do not close issues as part of implementation progress unless the user explicitly requests closure.
- When work is exploratory or blocked, document the blocker in the issue comment instead of rewriting the issue body unless asked.

### Drift Prevention

- Do not treat archived planning docs as active requirements.
- Prefer the root v1.0 plan, the boundary model, the smoke matrix, and the current deploy code over earlier design drafts.
- When a new change implies a workflow rule change, update this workflow doc and the relevant Codex skill in the same pass.

## Production Workflow

### Verify Stage

The GitHub Actions verify job is the pre-deploy gate. It currently runs:

- Composer install
- PHPStan
- PHPUnit
- sidecar dependency install
- sidecar Ruff lint
- sidecar pytest

If any of these fail, production deploy must not start.

### Deployer Orchestration

[deploy.php](/home/fsd42/dev/claudriel/deploy.php) is the canonical production orchestration layer. It is responsible for:

- artifact upload
- shared runtime directory setup
- Caddyfile copy
- sidecar promotion
- Caddy and PHP-FPM reload
- fail-closed post-deploy validation

GitHub Actions invokes this path; it should not duplicate the validation logic over SSH.

### Smoke Surfaces And Fail-Closed Behavior

The production validation path should always preserve the v1.0 smoke surfaces:

- dashboard and brief availability
- brief JSON payload shape
- chat send and stream path
- workspace mutation negative case
- invalid routing or boundary mismatches returning `403` or `404`, never mixed-scope data

Routing enforcement from Phase 4 and deploy validation from Phase 1/5 reinforce each other. The deploy pipeline is not healthy unless the public surfaces behave correctly under the tenant/workspace boundary model.
