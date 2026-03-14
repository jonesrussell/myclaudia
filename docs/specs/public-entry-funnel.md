# Public Entry Funnel

## Purpose

This document defines the canonical route contract for Claudriel's public entry surface. It separates the anonymous marketing experience from the authenticated product shell and fixes the expected redirects between signup, login, onboarding, logout, and the app.

This spec is the source of truth for Milestone `#13` and should be read before changing any of these routes:

- `/`
- `/app`
- `/signup`
- `/login`
- `/logout`
- `/verify-email/{token}`
- `/onboarding/bootstrap`

## Top-Level Model

Claudriel now has two primary entry contexts:

- public entry: marketing, education, and account entry
- authenticated app: the dashboard, brief, chat, workspace, and temporal-product shell

The public entry surface must not render the authenticated app shell directly.

## Canonical Route Contract

| Route | Audience | Purpose | Expected behavior |
|---|---|---|---|
| `GET /` | Anonymous first | Public marketing homepage | Render the public homepage with product framing and clear CTAs to signup and login |
| `GET /` | Authenticated | Soft app re-entry | Redirect to `/app` unless a future explicit public-view mode is introduced |
| `GET /app` | Anonymous | Authenticated app entry | Redirect to `/login` |
| `GET /app` | Authenticated | Main product shell | Render the existing dashboard/app experience |
| `GET /signup` | Anonymous | Account creation | Render signup form |
| `GET /signup` | Authenticated | Account already exists | Redirect to `/app` |
| `GET /login` | Anonymous | Session entry | Render login form |
| `GET /login` | Authenticated | Session already present | Redirect to `/app` |
| `POST /login` | Anonymous or expired session | Session creation | On success redirect into `/app` with tenant and workspace context |
| `GET /verify-email/{token}` | Verifying user | Email verification | Verify account, bootstrap tenant/workspace, and continue toward `/app` |
| `GET /onboarding/bootstrap` | Newly verified user | Bootstrap result surface | Render onboarding/bootstrap completion state and provide app entry |
| `POST /logout` | Authenticated | Session exit | End session and return user to the public entry surface |

## Redirect Rules

### Anonymous users

Anonymous users should see:

- `/` as the public homepage
- `/signup` as the signup surface
- `/login` as the login surface

Anonymous users should not see:

- the authenticated dashboard at `/`
- the authenticated app shell at `/app`

Anonymous access to `/app` should redirect to `/login`.

### Authenticated users

Authenticated users should treat `/app` as the canonical product entry route.

Expected behavior:

- `/app` renders the dashboard/app shell
- `/` redirects to `/app`
- `/signup` redirects to `/app`
- `/login` redirects to `/app`

This keeps signed-in users out of the anonymous funnel unless they explicitly log out.

### Post-auth flows

Successful login should redirect to `/app` and preserve:

- `tenant_id`
- `workspace_uuid`
- any lightweight success marker already used by the product shell

Successful verification and onboarding should also terminate in `/app` once tenant and workspace bootstrap are ready.

Logout should return the browser to a public entry surface, not the authenticated app shell.

## Public Homepage CTA Contract

The homepage at `/` must provide clear next actions without requiring the user to infer the flow.

Required CTA structure:

- primary CTA: create an account
- secondary CTA: log in
- product framing that explains what Claudriel does before the user commits

The homepage should also make these concepts legible:

- Claudriel is a schedule and operations assistant
- signup is the first step for new users
- login is the path for returning users
- the product experience lives behind the authenticated app shell

## Onboarding Contract

Verification and onboarding remain multi-step internally, but the user-facing route contract should feel linear:

1. visitor lands on `/`
2. visitor chooses `/signup` or `/login`
3. new accounts verify and complete bootstrap
4. verified users land in `/app`
5. returning users log in and land in `/app`

Onboarding and verification pages can remain visible transitional surfaces, but they are not the final destination for a ready account.

## App Shell Contract

`/app` becomes the canonical product shell for:

- dashboard
- day brief
- chat
- workspace interactions
- proactive temporal guidance

Moving the shell to `/app` must not widen tenant or workspace access. All current tenant-aware and workspace-aware routing rules remain in force.

## Non-Goals

This milestone does not define:

- a public pricing system
- a marketing CMS
- multiple anonymous homepage variants
- any change to internal tenant/workspace authorization rules

## Implementation Order

The intended implementation order is:

1. define the route contract
2. add the public homepage at `/`
3. move the authenticated shell to `/app`
4. retarget login, verification, onboarding, and logout into `/app`
5. add regression coverage for public-vs-app boundaries
6. update smoke validation, deploy probes, and docs

Any change that skips ahead and re-decides these contracts should be treated as drift.

## Smoke And Deploy Validation

The public entry flow is not complete unless staging and production can prove these behaviors quickly:

- `GET /` returns the marketing homepage with signup and login CTAs
- anonymous `GET /app` redirects to `/login`
- valid login lands in `/app`
- authenticated re-entry to `/` returns the user to `/app`

The canonical smoke surface for this flow lives in [v1.3-public-entry-funnel-smoke-matrix.md](/home/fsd42/dev/claudriel/tests/smoke/v1.3-public-entry-funnel-smoke-matrix.md).

Deploy validation should remain non-destructive:

- verify homepage CTA markers at `/`
- verify anonymous `/app` redirects to `/login`
- keep the existing signup and login invalid-path probes

This makes staging verification straightforward with `curl` and keeps the public-entry contract observable without requiring a live account mutation in every probe.
