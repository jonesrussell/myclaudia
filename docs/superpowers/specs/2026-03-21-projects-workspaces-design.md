# Projects & Workspaces Design

**Date:** 2026-03-21
**Status:** Approved
**Scope:** Claudriel-level entity model for Projects, Workspaces, and Repos with explicit junction entities and GitHub as source of truth.
**Supersedes:** `2026-03-20-workspace-project-repo-design.md`
**GitHub Epic:** jonesrussell/claudriel#428
**Milestone:** v1.8 — Projects & Workspaces

## Architectural Boundary

Waaseyaa is the framework. Claudriel is the application.

- Waaseyaa owns entity system, storage, routing, access, field types, middleware, kernel lifecycle.
- Claudriel owns Projects, Workspaces, Repos, and all GitHub-aware domain logic.
- No Waaseyaa changes are required or permitted for this milestone.

## Problem

Current Workspace and Project entities have structural problems:

- Workspace couples to a single repo (`repo_path`, `repo_url`, `branch`) and a single project (`project_id` FK).
- Project uses untyped JSON blobs (`metadata`, `settings`, `context`) instead of explicit fields.
- No Repo entity exists — repo data is inlined on Workspace.
- Relationships are 1:1 via FKs. Cannot associate multiple repos or projects.

## Entity Model

### Project

A unit of work and planning, backed by GitHub. All fields below already exist on the current `project` table except as noted — the migration only drops columns.

**Entity keys:** `['id' => 'prid', 'uuid' => 'uuid', 'label' => 'name']`

| Field | Type | Notes |
|-------|------|-------|
| `prid` | integer | PK, auto-increment, readOnly |
| `uuid` | string | Stable external ID, readOnly |
| `name` | string | Required, max 255 |
| `description` | string | Purpose/scope |
| `status` | string | `active`, `paused`, `completed`, `archived` — validated at API layer (422 on invalid value) |
| `account_id` | string | Owner |
| `tenant_id` | string | Tenant scope (internal — not exposed via GraphQL) |
| `created_at` | timestamp | readOnly |
| `updated_at` | timestamp | readOnly |

### Workspace

A working context for humans or agents.

**Entity keys:** `['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']`

| Field | Type | Notes |
|-------|------|-------|
| `wid` | integer | PK, auto-increment, readOnly |
| `uuid` | string | Stable external ID, readOnly |
| `name` | string | Required |
| `description` | string | Purpose |
| `status` | string | `active`, `archived` — validated at API layer |
| `mode` | string | `persistent`, `ephemeral` — validated at API layer |
| `saved_context` | text_long | Serialized filters, active selections, UI state |
| `account_id` | string | Owner |
| `tenant_id` | string | Tenant scope (internal — not exposed via GraphQL) |
| `created_at` | timestamp | readOnly |
| `updated_at` | timestamp | readOnly |

### Repo

A tracked GitHub repository (new entity).

**Entity keys:** `['id' => 'rid', 'uuid' => 'uuid', 'label' => 'name']`

| Field | Type | Notes |
|-------|------|-------|
| `rid` | integer | PK, auto-increment, readOnly |
| `uuid` | string | Stable external ID, readOnly |
| `owner` | string | GitHub owner (e.g. `jonesrussell`) |
| `name` | string | Required, repo name |
| `full_name` | string | `owner/name` |
| `url` | string | GitHub URL |
| `default_branch` | string | `main`, `master`, etc. |
| `local_path` | string | Nullable, local clone path |
| `account_id` | string | Claudriel user who added it |
| `tenant_id` | string | Tenant scope (internal — not exposed via GraphQL) |
| `created_at` | timestamp | readOnly |
| `updated_at` | timestamp | readOnly |

### Junction Entities

Explicit many-to-many relationships. No JSON arrays.

Junction entities do not carry `tenant_id` — tenant scoping is enforced via the parent entity lookup. A junction query always starts from a parent (e.g., "repos for project X"), and the parent's access policy handles tenant isolation.

**ProjectRepo**

| Field | Type |
|-------|------|
| `id` | integer (PK) |
| `uuid` | string |
| `project_uuid` | string |
| `repo_uuid` | string |
| `created_at` | timestamp |

**WorkspaceProject**

| Field | Type |
|-------|------|
| `id` | integer (PK) |
| `uuid` | string |
| `workspace_uuid` | string |
| `project_uuid` | string |
| `created_at` | timestamp |

**WorkspaceRepo**

| Field | Type |
|-------|------|
| `id` | integer (PK) |
| `uuid` | string |
| `workspace_uuid` | string |
| `repo_uuid` | string |
| `is_active` | boolean |
| `created_at` | timestamp |

`is_active` on WorkspaceRepo indicates which repo is currently selected/focused in the workspace UI. A workspace may link many repos but only one (or a few) are "active" at a time — e.g., the repo currently being browsed or worked on. Defaults to `true` on link creation.

## Cascade Delete Behavior

Application-level cascade via a single `JunctionCascadeSubscriber` listening to `entity.post_delete`.

| Deleted Entity | Junction Rows Removed | Untouched |
|---|---|---|
| Project | ProjectRepo, WorkspaceProject (matching `project_uuid`) | Repos, Workspaces |
| Workspace | WorkspaceProject, WorkspaceRepo (matching `workspace_uuid`) | Projects, Repos |
| Repo | ProjectRepo, WorkspaceRepo (matching `repo_uuid`) | Projects, Workspaces |

**Implementation details:**

- Uses `DatabaseInterface` for bulk `DELETE` queries (not EntityRepository — no entity lifecycle needed for junction cleanup).
- Best-effort: wrap in try-catch, log via `error_log()` on failure. A failed junction cleanup must not crash the parent delete.
- Orphaned junction rows are harmless — they reference a UUID that no longer exists.

## Access Policies

### ProjectAccessPolicy

- Owner (`account_id` matches current account): full CRUD
- Same tenant, not owner: read-only
- Anonymous: no access

### WorkspaceAccessPolicy

- Owner: full CRUD
- Anyone else (including same tenant): no access — workspaces are personal contexts

### RepoAccessPolicy

- Owner (who added it): full CRUD
- Same tenant: read-only
- Anonymous: no access

### Junction Access

No separate policies. Junction operations inherit from the parent being modified:

- Link/unlink a repo to a project: requires update access to the project.
- Link/unlink a project or repo to a workspace: requires ownership of the workspace.

All policies registered via `#[PolicyAttribute(entityType: '...')]` and discovered by `PackageManifestCompiler`. No `FieldAccessPolicyInterface` needed.

## CRUD API Routes

### Entity CRUD

| Method | Path | Access | Purpose |
|--------|------|--------|---------|
| GET | `/api/projects` | `_gate: project` | List projects |
| POST | `/api/projects` | `_gate: project` | Create project |
| GET | `/api/projects/{uuid}` | `_gate: project` | Get project |
| PATCH | `/api/projects/{uuid}` | `_gate: project` | Update project |
| DELETE | `/api/projects/{uuid}` | `_gate: project` | Delete project |
| GET | `/api/workspaces` | `_gate: workspace` | List workspaces |
| POST | `/api/workspaces` | `_gate: workspace` | Create workspace |
| GET | `/api/workspaces/{uuid}` | `_gate: workspace` | Get workspace |
| PATCH | `/api/workspaces/{uuid}` | `_gate: workspace` | Update workspace |
| DELETE | `/api/workspaces/{uuid}` | `_gate: workspace` | Delete workspace |
| GET | `/api/repos` | `_gate: repo` | List repos |
| POST | `/api/repos` | `_gate: repo` | Create repo |
| GET | `/api/repos/{uuid}` | `_gate: repo` | Get repo |
| PATCH | `/api/repos/{uuid}` | `_gate: repo` | Update repo |
| DELETE | `/api/repos/{uuid}` | `_gate: repo` | Delete repo |

### Junction Management

Nested under the parent being modified.

| Method | Path | Access Check | Purpose |
|--------|------|-------------|---------|
| GET | `/api/projects/{uuid}/repos` | Read on project | List repos linked to project |
| POST | `/api/projects/{uuid}/repos` | Update on project | Link repo to project |
| DELETE | `/api/projects/{uuid}/repos/{repo_uuid}` | Update on project | Unlink repo from project |
| GET | `/api/workspaces/{uuid}/projects` | Owner of workspace | List projects in workspace |
| POST | `/api/workspaces/{uuid}/projects` | Owner of workspace | Link project to workspace |
| DELETE | `/api/workspaces/{uuid}/projects/{project_uuid}` | Owner of workspace | Unlink project |
| GET | `/api/workspaces/{uuid}/repos` | Owner of workspace | List repos in workspace |
| POST | `/api/workspaces/{uuid}/repos` | Owner of workspace | Link repo to workspace |
| DELETE | `/api/workspaces/{uuid}/repos/{repo_uuid}` | Owner of workspace | Unlink repo |

### Junction POST Body

Junction link requests send the target entity UUID:

```json
POST /api/projects/{uuid}/repos
{ "repo_uuid": "..." }

POST /api/workspaces/{uuid}/projects
{ "project_uuid": "..." }

POST /api/workspaces/{uuid}/repos
{ "repo_uuid": "..." }
```

### Error Responses

- 404: parent or target entity not found
- 409: duplicate junction link (same UUID pair already exists)
- 403: insufficient access
- 422: missing required fields on create

### Reverse Lookups

REST API provides forward lookups from the parent being modified. Reverse lookups (e.g., "which projects contain this repo?") are not exposed in v1.8 via REST or GraphQL.

- No `GET /api/repos/{uuid}/projects`
- No `GET /api/projects/{uuid}/workspaces`

These can be added when Waaseyaa's GraphQL package gains a `graphqlFieldOverrides()` extension point, or as additional REST endpoints if needed earlier.

### Controllers

One controller per entity type (`ProjectController`, `WorkspaceController`, `RepoController`). Junction operations live on the parent's controller.

## GraphQL Schema

Read-only for v1.8. REST API handles all writes.

### Types

Auto-generated from entity type `fieldDefinitions`. All scalar fields are exposed automatically.

**Relationship fields** (`Project.repos`, `Workspace.projects`, `Workspace.repos`, `Repo.projects`) require a `graphqlFieldOverrides()` extension point that does not yet exist in Waaseyaa's GraphQL package. These fields are **deferred** — a Waaseyaa issue should be filed for the extension point. Until then, junction relationships are accessible via the REST junction endpoints.

```graphql
type Project {
  uuid: String!
  name: String!
  description: String
  status: String!
  accountId: String
  createdAt: String
  updatedAt: String
  # repos: [Repo!]!  — deferred: requires Waaseyaa graphqlFieldOverrides()
}

type Workspace {
  uuid: String!
  name: String!
  description: String
  status: String!
  mode: String!
  savedContext: String
  accountId: String
  createdAt: String
  updatedAt: String
  # projects: [Project!]!  — deferred: requires Waaseyaa graphqlFieldOverrides()
  # repos: [Repo!]!        — deferred: requires Waaseyaa graphqlFieldOverrides()
}

type Repo {
  uuid: String!
  owner: String!
  name: String!
  fullName: String!
  url: String!
  defaultBranch: String!
  localPath: String
  accountId: String
  createdAt: String
  updatedAt: String
  # projects: [Project!]!  — deferred: requires Waaseyaa graphqlFieldOverrides()
}
```

### List Types

```graphql
type ProjectList {
  items: [Project!]!
  totalCount: Int!
}

type WorkspaceList {
  items: [Workspace!]!
  totalCount: Int!
}

type RepoList {
  items: [Repo!]!
  totalCount: Int!
}
```

### Queries

```graphql
type Query {
  projects(limit: Int, offset: Int): ProjectList!
  project(uuid: String!): Project
  workspaces(limit: Int, offset: Int): WorkspaceList!
  workspace(uuid: String!): Workspace
  repos(limit: Int, offset: Int): RepoList!
  repo(uuid: String!): Repo
}
```

`totalCount` reflects full storage count (not access-filtered). `items` contains only entities the caller can access.

Relationship fields are resolved in GraphQL field resolvers via junction table queries. Access filtering is applied to relationship results.

`tenant_id` is intentionally excluded from all GraphQL types. It is an internal scoping field used by access policies — not meaningful to API consumers.

## Event Subscriber

**Class:** `Claudriel\Subscriber\JunctionCascadeSubscriber`

**Subscribes to:** `entity.post_delete`

**Dependencies:** `DatabaseInterface` only.

**Registration:** Via service provider `boot()` method.

**Behavior:** Matches on deleted entity type, issues bulk `DELETE` queries against relevant junction tables. Best-effort with `error_log()` on failure.

## Migration Plan

### Migration 1: CreateProjectWorkspaceRepoTables

Creates 4 new tables:
- `repo`
- `project_repo`
- `workspace_project`
- `workspace_repo`

### Migration 2: MigrateWorkspaceProjectData

1. For each existing Workspace with `repo_url` populated:
   - Create a Repo entity from `repo_url`, `repo_path`, `branch`
   - Create a WorkspaceRepo junction linking workspace to new repo
   - If `project_id` is set, create a WorkspaceProject junction
2. Add `saved_context` column to `workspace` table
3. Drop columns from `workspace`: `repo_path`, `repo_url`, `branch`, `codex_model`, `last_commit_hash`, `ci_status`, `project_id`, `metadata`
4. Drop columns from `project`: `metadata`, `settings`, `context`

Order: Migration 1 runs before Migration 2.

## GitHub Integration Boundary

Claudriel does NOT replicate GitHub issues or milestones into entity storage.

- **Claudriel stores:** organizational groupings (which repos belong to which project, which projects are in which workspace)
- **GitHub provides:** issues, milestones, PRs, project boards (accessed live via GitHub API through existing `InternalGithubController`)
- **Project** is the Claudriel-side grouping that references GitHub repos

## Testing Strategy

- Entity CRUD: create, read, update, delete for Project, Workspace, Repo
- Junction CRUD: link, unlink, list, duplicate prevention (409)
- Cascade delete: delete parent, verify junction rows removed, verify other parents untouched
- Access policies: owner CRUD, tenant read-only (Project/Repo), owner-only (Workspace), anonymous denied
- Migration: run on existing database, verify data migrated, verify old columns dropped
- All tests use `DBALDatabase::createSqlite(':memory:')` for in-memory testing with real `EntityRepository`

## Issue Tracker

| Issue | Title |
|-------|-------|
| #428 | Epic: v1.8 — Projects & Workspaces |
| #429 | Design: entity model, junctions, GitHub boundary |
| #430 | Implement: Repo entity + RepoServiceProvider |
| #431 | Implement: Junction entities |
| #432 | Refactor: remove obsolete Workspace/Project fields |
| #433 | Implement: CRUD API routes |
| #434 | Implement: Access policies |
| #435 | Implement: GraphQL schema updates |
| #436 | Test: integration coverage |
