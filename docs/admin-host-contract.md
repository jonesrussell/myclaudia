# Admin Host Contract

Claudriel currently vendors the Waaseyaa admin SPA under `frontend/admin` and serves the committed build from `public/admin`. The code in this document defines the host-side behavior that Claudriel should keep when the upstream admin becomes a packaged dependency.

TODO: replace these internal contracts with the upstream Waaseyaa admin host contract once that package is available.

## Current host responsibilities

- Access policy: `Claudriel\Support\AdminAccess` decides whether an authenticated account can use the admin UI.
- Tenant resolution and enforcement: `Claudriel\Admin\Host\ClaudrielAdminHost` resolves tenant context for admin bootstrap and enforces tenant presence for `/admin`.
- Admin bootstrap/session provider: `/admin/session` returns the authenticated account, resolved tenant context, and admin entity catalog.
- Login handoff and safe redirects: `/login?redirect=...` is used for admin entry, and only absolute in-app paths beginning with `/` are accepted as redirect targets.
- Entity catalog policy: `Claudriel\Support\AdminCatalog` decides which entity types appear in the admin UI.
- Transport adapter: `frontend/admin/app/host/claudrielAdapter.ts` maps generic admin CRUD calls onto Claudriel API endpoints.

## `/admin/session` payload

Current JSON shape:

```json
{
  "account": {
    "uuid": "account-uuid",
    "email": "owner@example.com",
    "tenant_id": "tenant-uuid",
    "roles": ["tenant_owner"]
  },
  "tenant": {
    "uuid": "tenant-uuid",
    "name": "Tenant name",
    "default_workspace_uuid": "workspace-uuid"
  },
  "entity_types": [
    {
      "id": "workspace",
      "label": "Workspace",
      "keys": { "id": "wid", "uuid": "uuid", "label": "name" },
      "group": "structure",
      "disabled": false
    }
  ]
}
```

Behavior notes:

- Anonymous requests receive `401 {"error":"Not authenticated."}`.
- Authenticated non-admin requests receive `403 {"error":"Admin access is required."}`.
- The UI route `/admin` additionally rejects authenticated requests with no tenant context using HTTP `409`.
- `tenant` is `null` when the tenant cannot be resolved from the current account.

## `/admin/logout` behavior

- Endpoint: `POST /admin/logout`
- Session behavior: unsets `$_SESSION['claudriel_account_uuid']` and regenerates the PHP session id.
- Success payload:

```json
{
  "logged_out": true
}
```

## Entity catalog format

Current entity catalog entries are arrays with this shape:

```json
{
  "id": "workspace",
  "label": "Workspace",
  "keys": { "id": "wid", "uuid": "uuid", "label": "name" },
  "group": "structure",
  "disabled": false
}
```

Current catalog policy:

- `workspace` -> `structure`
- `person` -> `people`
- `commitment` -> `workflows`
- `schedule_entry` -> `workflows`
- `triage_entry` -> `workflows`

## Entity transport mapping

The host adapter in `frontend/admin/app/host/claudrielAdapter.ts` currently maps generic admin operations onto these Claudriel APIs:

| Admin entity type | List/Create base path | Get/Update/Delete path |
| --- | --- | --- |
| `workspace` | `/api/workspaces` | `/api/workspaces/{id}` |
| `person` | `/api/people` | `/api/people/{id}` |
| `commitment` | `/api/commitments` | `/api/commitments/{id}` |
| `schedule_entry` | `/api/schedule` | `/api/schedule/{id}` |
| `triage_entry` | `/api/triage` | `/api/triage/{id}` |

Schema loading is currently resolved through `/api/schema/{entityType}`.

## Login handoff and safe redirect behavior

- Anonymous `/admin` requests redirect to `/login?redirect=<requested admin path>`.
- The login form accepts a `redirect` query parameter or posted field only when it is:
  - a string
  - non-empty
  - begins with `/`
  - does not begin with `//`
- After successful login:
  - if a safe redirect is present, Claudriel redirects there unchanged
  - otherwise Claudriel redirects to `/app?login=1&tenant_id=<tenant>&workspace_uuid=<default workspace>` when those values are available
- If an already-authenticated account visits `/login`:
  - a safe redirect target wins
  - otherwise Claudriel redirects to `/app` with tenant and workspace query parameters when available
