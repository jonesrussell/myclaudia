# Public Accounts

## Purpose

This document is the canonical reference for Claudriel's public signup and account lifecycle. It describes the user-facing flow, the bootstrap behavior behind verification, and the operational mail and deploy expectations that keep the surface healthy in production.

## Runtime Surface

The public account subsystem currently exposes these primary routes:

- `GET /signup`
- `POST /signup`
- `GET /signup/check-email`
- `GET /verify-email/{token}`
- `GET /signup/verification-result`
- `GET /onboarding/bootstrap`
- `GET /login`
- `POST /login`
- `POST /logout`
- `GET /account/session`
- `GET /forgot-password`
- `POST /forgot-password`
- `GET /forgot-password/check-email`
- `GET /reset-password/{token}`
- `POST /reset-password/{token}`
- `GET /reset-password/complete`

## Account Lifecycle

### Signup

`POST /signup` accepts `name`, `email`, and `password`.

Current behavior:

- Email is normalized to lowercase.
- Passwords are stored as password hashes, never raw values.
- Duplicate email signup is rejected.
- New accounts are created with status `pending_verification`.
- Verification mail is sent immediately after the account and verification token are persisted.

The signup form fails closed with `422` for:

- missing `name`, `email`, or `password`
- invalid email format
- duplicate email address

### Email Verification

Signup creates an `account_verification_token` record with:

- a SHA-256 token hash
- a 24 hour expiry
- a single-use `used_at` marker
- a default redirect target of `/onboarding/bootstrap`

`GET /verify-email/{token}` activates the account when the token is fresh and unused.

Verification side effects:

- account status changes to `active`
- `email_verified_at` is stamped
- verification token is marked used
- tenant bootstrap runs
- default workspace bootstrap runs
- sidecar bootstrap runs
- the browser is redirected to `/onboarding/bootstrap` with `account`, `tenant`, `workspace`, and `verified=1`

Invalid or expired verification tokens redirect to `/signup/verification-result?status=invalid`.

### Tenant Bootstrap

Verification is the only place that creates the initial tenant. The bootstrap is idempotent.

Rules:

- tenant ownership is keyed by `owner_account_uuid`
- repeated verification or replayed bootstrap returns the existing tenant instead of creating a second one
- the verified account receives `tenant_id`
- the verified account is guaranteed to hold the `tenant_owner` role

Persisted tenant metadata includes:

- `bootstrap_source=public_signup`
- `bootstrap_state=tenant_ready`
- `owner_email`
- `default_workspace_uuid` once workspace bootstrap completes

Tenant naming uses the account name when present and otherwise falls back to the email local part.

### Workspace Bootstrap

Verification also creates the default workspace for the tenant. This step is also idempotent.

Rules:

- one tenant gets one default bootstrap workspace
- the workspace is named `Main Workspace`
- the workspace is tenant-scoped from creation
- tenant metadata stores the default workspace UUID for later login redirect and session-state resolution

Workspace metadata currently records:

- `bootstrap_kind=default`
- `bootstrap_source=public_signup`
- `surfaces=["dashboard","brief","chat"]`
- `sidecar_bootstrap` after the sidecar call finishes

### Sidecar Bootstrap

After tenant and workspace creation, Claudriel calls the sidecar bootstrap endpoint:

- `POST {SIDECAR_URL}/bootstrap/workspace`

Request body:

```json
{
  "tenant_id": "<tenant uuid>",
  "workspace_id": "<workspace uuid>"
}
```

Authentication:

- `Authorization: Bearer {CLAUDRIEL_SIDECAR_KEY}`

Runtime behavior:

- if `SIDECAR_URL` or `CLAUDRIEL_SIDECAR_KEY` is missing, bootstrap is skipped and workspace metadata records `state=skipped`
- if the sidecar returns success, workspace metadata records the returned state with tenant and workspace IDs
- if the sidecar call fails, verification fails closed by raising an exception rather than silently claiming onboarding completed

## Authentication Lifecycle

### Login

`POST /login` accepts `email` and `password`.

Current behavior:

- only `active` accounts can log in
- password verification uses the stored password hash
- successful login stores `claudriel_account_uuid` in the PHP session
- session ID is regenerated on login
- CSRF state is regenerated on login
- the redirect includes `login=1`, `tenant_id`, and the tenant's default `workspace_uuid` when available

Failed login returns `401` with `Invalid credentials.`.

### Session State

`GET /account/session` returns the authenticated account summary for session-aware surfaces.

Current payload includes:

- account UUID
- email
- tenant UUID
- roles
- tenant default workspace UUID

Unauthenticated access returns `401`.

### Logout

`POST /logout` removes `claudriel_account_uuid`, regenerates the session ID, regenerates the CSRF token, and redirects to `/login?logged_out=1`.

## Password Reset Lifecycle

`POST /forgot-password` issues reset mail for verified accounts only.

Rules:

- unknown email addresses do not raise user-visible errors
- reset tokens are hashed with SHA-256
- reset tokens expire after 2 hours
- reset tokens are single-use through `used_at`

`GET /reset-password/{token}` only renders the reset form when the token is still valid.

`POST /reset-password/{token}`:

- updates the stored password hash
- marks the reset token used
- redirects to `/reset-password/complete?status=complete`

Invalid or expired reset tokens redirect to `/reset-password/complete?status=invalid`.

## Mail Delivery

Both signup verification and password reset use the same transport model:

- primary transport: SendGrid
- fallback transport: append-only logged delivery

Environment variables:

- `SENDGRID_API_KEY`
- `CLAUDRIEL_MAIL_FROM_EMAIL`
- `CLAUDRIEL_MAIL_FROM_NAME`
- `CLAUDRIEL_APP_URL`

SendGrid behavior:

- when `SENDGRID_API_KEY` is configured, Claudriel sends mail through `https://api.sendgrid.com/v3/mail/send`
- when the API key is empty, the system falls back to the logged transport instead of failing
- when SendGrid returns an HTTP error, the request raises a runtime exception

Fallback behavior:

- deliveries are appended to `storage/mail-delivery.log`
- this path is useful for local development, smoke tests, and operational inspection when real delivery is intentionally disabled

Operational implication:

- signup or reset flows that depend on actual user delivery require working SendGrid configuration in production
- local or test environments can intentionally rely on the logged transport without changing controller behavior

## Deploy And Smoke Validation

The canonical smoke surface for this subsystem lives in [v1.2-public-account-smoke-matrix.md](/home/fsd42/dev/claudriel/tests/smoke/v1.2-public-account-smoke-matrix.md).

Current deploy validation in [deploy.php](/home/fsd42/dev/claudriel/deploy.php) covers:

- `GET /signup`
- invalid signup submission with live CSRF extraction and expected `422`
- `GET /login`
- invalid login submission with live CSRF extraction and expected `401`
- the existing public brief and chat probes

This deploy probe is intentionally non-destructive:

- it does not create a real account
- it does not require outbound mail delivery
- it still proves the public onboarding surface is wired and rejecting bad input correctly

## Troubleshooting

### Signup succeeds locally but no email arrives

Expected causes:

- `SENDGRID_API_KEY` is unset and the logged fallback is being used
- `storage/mail-delivery.log` should contain the verification URL payload

### Verification link fails immediately

Check:

- token age exceeds 24 hours
- token was already consumed
- `CLAUDRIEL_APP_URL` generated an unexpected verification URL

### Login succeeds but the app lands without tenant context

Check:

- the verified account has `tenant_id`
- the tenant metadata contains `default_workspace_uuid`
- verification completed fully, including tenant and workspace bootstrap

### Onboarding page shows tenant or workspace missing

Check:

- verification redirect query includes `account`, `tenant`, and `workspace`
- tenant bootstrap metadata is present
- default workspace exists for that tenant

### Sidecar bootstrap appears skipped

Check:

- `SIDECAR_URL` is configured
- `CLAUDRIEL_SIDECAR_KEY` is configured
- workspace metadata contains `sidecar_bootstrap`

`state=skipped` is expected when those sidecar variables are intentionally absent.

### Password reset requests appear to do nothing

Expected causes:

- the target email does not belong to a verified account
- the mail delivery fell back to `storage/mail-delivery.log`

This silent behavior is intentional to avoid exposing account existence through the public reset form.
