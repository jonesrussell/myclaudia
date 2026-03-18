# Planning Intelligence Dataset: Cross-Repository Roadmap
**Date:** 2026-03-18
**Repos:** waaseyaa/framework, minoo/minoo, jonesrussell/claudriel
**Based on:** Code-level scan of all three repos + GitHub issue/milestone data

---

## A) Planning-Grade Architectural Signals

### Waaseyaa (Framework) — 12 Signals

| # | Signal | Evidence | Milestone Implication |
|---|--------|----------|----------------------|
| W1 | **DBAL migration incomplete** | `database-legacy` (14 files) marked "interim until Doctrine migration"; v1.4 has 17 issues, 0 started | Dedicated milestone (v1.4, exists) |
| W2 | **4 orphaned interfaces** | `EventStoreInterface`, `RevisionableInterface`, `RevisionableStorageInterface`, `SearchProviderInterface` — defined, zero implementations | 3 future milestones: search provider, revision system, event sourcing |
| W3 | **Admin surface incomplete** | `admin-surface` (8 files): AbstractAdminSurfaceHost, CatalogBuilder exist but Controller/ is empty | Blocks Minoo v1.0 Admin |
| W4 | **Workflows package is feature-complete** | 13 files: editorial state machine, content moderation, transition access control, visibility resolution | NOT a gap (previous report was wrong). Ready for app adoption |
| W5 | **Queue is feature-complete** | 17 files: in-memory, sync, message-bus queues; job handlers; batched/chained; rate limiting | NOT a gap. Needs production backend (Redis/database) |
| W6 | **Cache has 3 backends** | 15 files: Null, Memory, Database backends; tag-aware; entity/config/translation invalidation listeners | NOT a gap. Database backend exists. Redis would be nice-to-have |
| W7 | **Search is interface-only** | `SearchProviderInterface` + request/result/facet DTOs but zero providers | Needs at least one concrete provider (SQLite FTS5 or similar) |
| W8 | **Telescope is comprehensive** | 35 files: 4 recorders, CodifiedContext observer, SQLite/JSONL/Prometheus storage, drift scoring | Mature diagnostics. No planning action needed |
| W9 | **No TODO/FIXME in codebase** | Zero inline TODOs in waaseyaa code. All planning via GitHub issues and 35 spec files | Planning discipline is strong |
| W10 | **Revision system designed but unbuilt** | `RevisionableInterface` + `RevisionableStorageInterface` in entity package | Future milestone: content versioning |
| W11 | **Event sourcing designed but unbuilt** | `EventStoreInterface` defined | Future milestone: audit replay |
| W12 | **GitHub package exists** | 5 files: GitHubClient wrapper with Issue, PullRequest, Milestone entity types | Available for Claudriel's GitHub integration features |

**Corrections to previous report:**
- Workflows is NOT scaffolded — it's feature-complete (13 files, editorial state machine)
- Cache is NOT Memory-only — it has a Database backend
- Queue is NOT interface-only — it has full implementation, just needs production backends

---

### Minoo (Application) — 10 Signals

| # | Signal | Evidence | Milestone Implication |
|---|--------|----------|----------------------|
| M1 | **13 of 19 entity types lack controllers** | Only Community, Event, Group, Teaching, People, Elder Support have controllers | Need controller coverage milestone |
| M2 | **No GraphQL spec or tests** | No docs/specs/graphql.md, no schema contract tests, no per-entity fieldDefinitions validation | GraphQL milestone needs spec-first approach |
| M3 | **Domain services only in Geo** | `src/Domain/Geo/` has LocationService, CommunityFinder, VolunteerRanker; other domains have zero services | Elder support, event lifecycle, language mgmt need domain extraction |
| M4 | **Commented-out code in providers** | Multiple providers contain dead routes/services (ChatServiceProvider, AccountServiceProvider) | Cleanup issue needed |
| M5 | **E2E test coverage sparse** | Playwright directory exists but minimal tests; no GraphQL integration tests | Testing milestone or per-feature test requirements |
| M6 | **Seed data is scaffold-level** | 6 seeders (376 lines total); enough for development, not for demo/staging | Seed data enhancement issue |
| M7 | **No public user accounts** | Admin accounts only; no community member profiles, no saved favorites, no learning progress | Major future milestone |
| M8 | **Search depends on external NorthCloud** | No local search fallback; if external service is down, search breaks | Needs framework search provider (waaseyaa W7) |
| M9 | **20 well-scoped service providers** | Better than Claudriel's monolith; each provider is domain-scoped | Pattern is healthy but mixes concerns |
| M10 | **5 spec files cover core architecture** | entity-model, ingestion-pipeline, search, frontend-ssr, workflow specs exist | No GraphQL, API versioning, or deployment specs |

---

### Claudriel (Application) — 14 Signals

| # | Signal | Evidence | Milestone Implication |
|---|--------|----------|----------------------|
| C1 | **3 empty reserved namespaces** | `src/Access/` (only AuthenticatedAccount.php), `src/Search/` (empty), `src/Seed/` (empty) | Access: v1.5.2 #231. Search/Seed: future |
| C2 | **Agent has 5 tools, static registration** | `TOOLS = [...]` list in main.py; `EXECUTORS = {...}` dict; adding tools is manual 4-step process | Agent expansion milestone; consider dynamic loader at ~10+ tools |
| C3 | **6-7 entities lack full CRUD** | Artifact, Integration, Operation, TriageEntry have no controllers, no commands, no admin pages | Design decision or incomplete — clarify which are internal-only |
| C4 | **Pipeline has 2 steps vs 12 ingest handlers** | CommitmentExtractionStep + WorkspaceClassificationStep only; ingestion is rich, pipeline is sparse | Pipeline expansion milestone (priority scoring, dedup, relationship extraction) |
| C5 | **Frontend admin is generic** | Dynamic `[entityType]` page + IngestSummaryWidget; no domain-specific admin pages for commitments, people, workspaces | Admin UX milestone |
| C6 | **Test gaps in data layer** | No integration tests for commitment workflows, ingestion pipelines, workspace lifecycle | Testing milestone or per-feature test requirements |
| C7 | **Temporal agents are mature** | 4 agents (OverrunAlert, ShiftRisk, WrapUpPrompt, UpcomingBlockPrep) with extensible registry pattern | Ready for more agents without architectural changes |
| C8 | **Public accounts complete** | v1.2/v1.3 delivered signup → verification → bootstrap flow | Internal account admin (roles, permissions) not started |
| C9 | **No `.env.example` or config validation** | 15+ env vars required; no example file, no schema validation | Infrastructure issue |
| C10 | **Git integration split across 2 locations** | GitRepositoryManager in `src/Layer2/`, GitOperator in `src/Service/` | Refactor into `src/Domain/Git/` |
| C11 | **DayBrief is snapshot-only** | DayBriefAssembler produces static JSON; no real-time updates, no SSE for brief changes | Enhancement in v1.9 or separate milestone |
| C12 | **TemporalNotification entity is a dead end** | Entity exists, TemporalNotificationApiController has dismiss/snooze, but no delivery mechanism | Notification delivery milestone |
| C13 | **No scheduled job system** | No cron config, no systemd timers, no background worker | Autonomous operations milestone |
| C14 | **waaseyaa/github package available** | Framework has GitHubClient with Issue, PullRequest, Milestone types | Could power Claudriel's IssueOrchestrator instead of raw `gh` CLI |

---

## B) Future Milestones (Per Repo)

### Waaseyaa — 8 Milestones

| # | Title | Type | Description | Touches | Cross-Repo | Sequence |
|---|-------|------|-------------|---------|------------|----------|
| v1.3 | GraphQL & Cleanup | Existing | Finish remaining 7 issues | graphql/, api/ | Enables Minoo GraphQL | **Now** |
| v1.4 | DBAL Migration | Existing | Replace database-legacy with DBAL (17 issues) | entity-storage/, foundation/ | Blocks both apps | After v1.3 |
| v1.5 | Admin Surface Completion | New | Make admin-surface functional: controllers, host contract, catalog API | admin-surface/ | Blocks Minoo v1.0 | After v1.4 |
| v1.6 | Search Provider | New | Concrete SearchProviderInterface implementation (SQLite FTS5) | search/ | Enables Minoo local search | After v1.5 |
| v1.7 | Revision System | New | Implement RevisionableInterface + RevisionableStorageInterface | entity/, entity-storage/ | Enables content versioning in both apps | After v1.4 |
| v1.8 | Projects & Workspaces | Existing | Placeholder for framework-level support | — | Claudriel v1.8 | TBD |
| v1.9 | Production Queue Backend | New | Redis or database queue driver for production async | queue/ | Enables async in both apps | After v1.4 |
| v2.0 | Schema Evolution | New | Auto-ALTER on fieldDefinition changes; migration generation from entity diffs | entity-storage/ | Fixes Minoo #273 schema drift | After v1.7 |

**Note:** Workflows (W4) and cache (W6) are already feature-complete. Queue needs only a production backend driver, not a rewrite.

---

### Minoo — 8 Milestones

| # | Title | Type | Description | Touches | Cross-Repo | Sequence |
|---|-------|------|-------------|---------|------------|----------|
| v0.9 | Stabilization | New | Assign 8 orphan issues; fix responsive, search, deploy bugs | Controllers, CSS | — | **Now** |
| v1.0 | Admin Surface | Existing | Wire waaseyaa admin-surface into Minoo (4 issues) | Admin/, Provider/ | Blocked by waaseyaa v1.5 | After waaseyaa v1.5 |
| v1.1 | Entity Controller Coverage | New | Build controllers for 6+ content entities lacking UI surfaces | Controller/, templates/ | — | After v1.0 |
| v1.2 | Local Search Fallback | New | SQLite FTS5 search as fallback for NorthCloud | Search/, Support/ | Blocked by waaseyaa v1.6 | After waaseyaa v1.6 |
| v1.3 | Domain Service Extraction | New | Extract business logic from controllers into domain services (elder support, event lifecycle) | Domain/ | — | After v1.1 |
| v1.4 | Community Member Accounts | New | Public registration, profiles, saved favorites, learning progress | Entity/, Access/, Controller/ | — | After v1.3 |
| v1.5 | Content Authoring | New | Elder/teacher content submission, editorial review via waaseyaa workflows | Domain/, Workflows/ | Uses waaseyaa workflows (already complete) | After v1.4 |
| v1.6 | PWA & Offline | New | Service worker, offline dictionary, installable app | frontend/, public/ | — | After v1.4 |

---

### Claudriel — 10 Milestones

| # | Title | Type | Description | Touches | Cross-Repo | Sequence |
|---|-------|------|-------------|---------|------------|----------|
| v1.5.1 | Google OAuth Verification | Existing | Production env, OAuth submission (6 issues) | Controller/, infra | — | **Now** |
| v1.5.2 | Framework Compliance | Existing | Entity constructors, fieldDefinitions, provider split, access policies (5 issues) | Entity/, Provider/, Access/ | — | **Now** |
| v1.5.3 | User Settings & Account | Existing | Google connection management (1 issue) | Controller/, frontend/ | — | After v1.5.1 |
| v1.5.4 | Infrastructure Hardening | New | PHP-FPM logging, database-legacy removal, sidebar, .env.example, config validation | infra, config | Partially blocked by waaseyaa v1.4 | After v1.5.2 |
| v1.6 | Agent Tool Expansion | New (repurpose) | Commitment CRUD, person lookup, brief queries, schedule management, dynamic tool loader | agent/, Controller/Internal* | — | After v1.5.3 |
| v1.7 | Notification Delivery | New (repurpose) | TemporalNotification email/push delivery, in-app notification center | Domain/Notification/, frontend/ | — | After v1.6 |
| v1.8 | Projects & Workspaces | Existing | Full project/workspace system (32 issues) | Entity/, Domain/Workspace/, frontend/ | — | After v1.5.4 |
| v1.9 | Pipeline Expansion | New | Additional extraction steps: confidence scoring, dedup, relationship extraction, priority ranking | Pipeline/ | — | After v1.8 |
| v1.10 | Autonomous Operations | New | Scheduled Gmail ingestion, auto-drift-check, background commitment extraction | Domain/, Queue/ | Blocked by waaseyaa v1.9 (queue backend) | After v1.9 |
| v2.0 | Voice I/O | New (merge v1.6+v1.7) | Speech-to-text input + text-to-speech output (combined) | agent/, frontend/ | — | After v1.10 |

---

## C) Feature Tracks Across Repos

| Track | Type | Repos | Milestones | Existing Issues | Status | Blockers |
|-------|------|-------|------------|-----------------|--------|----------|
| **DBAL Migration** | Foundational | W, M, C | W:v1.4, C:#226, M:Alpha Upgrade | W:17, C:1, M:2 | Not started | None (root dependency) |
| **GraphQL Maturity** | Cross-repo | W, M, C | W:v1.3, M:GraphQL API, C:v1.5.2(#229) | W:7, M:~4, C:1 | Active (71% W) | W:v1.3 must finish first |
| **Admin Surface** | Cross-repo | W, M | W:v1.5, M:v1.0 | W:~5 new, M:4 | Blocked | W:admin-surface incomplete |
| **Framework Compliance** | App-level | C | C:v1.5.2 | C:5 | Sprint-ready | None |
| **OAuth & Production** | App-level | C | C:v1.5.1 | C:6 | Sprint-ready | External (Google review) |
| **Projects & Workspaces** | App-level | C (+W placeholder) | C:v1.8, W:v1.8 | C:32 | Planned | C:v1.5.2 should finish first |
| **Agent Expansion** | App-level | C | C:v1.6 | C:~8 new | Future | C:v1.5.3 |
| **Search** | Cross-repo | W, M | W:v1.6, M:v1.2 | None yet | Future | W:search provider needed |
| **Content Versioning** | Foundational | W | W:v1.7 | None yet | Future | W:v1.4 (DBAL first) |
| **Notification Delivery** | App-level | C | C:v1.7 | None yet | Future | C:v1.6 |
| **Language Revitalization** | App-level | M | M:Localization, M:v1.4 accounts | M:1 existing | Active (small) | None |
| **Community Engagement** | App-level | M | M:v1.4, M:v1.5, M:v1.6 | None yet | Future | M:v1.3 domain services |
| **Autonomous Ops** | Cross-repo | W, C | W:v1.9, C:v1.10 | None yet | Future | W:queue backend |
| **Schema Evolution** | Foundational | W, M | W:v2.0 | M:#273 | Future | W:v1.7 |
| **Voice I/O** | App-level | C | C:v2.0 | None | Aspirational | Everything else |

### Track Dependency Chain

```
DBAL Migration ──→ Content Versioning ──→ Schema Evolution
      │
      ├──→ Admin Surface ──→ Entity Controllers (Minoo)
      │                          │
      │                          └──→ Domain Services ──→ Community Accounts ──→ Content Authoring ──→ PWA
      │
      ├──→ Production Queue Backend ──→ Autonomous Ops (Claudriel)
      │
      └──→ Search Provider ──→ Local Search (Minoo)

Framework Compliance ──→ Projects & Workspaces ──→ Pipeline Expansion ──→ Autonomous Ops
         │
         └──→ Agent Expansion ──→ Notification Delivery ──→ Voice I/O
```

---

## D) Multi-Phase Roadmap

### Phase 1: Immediate (2-4 weeks) — "Finish & Stabilize"

| Milestone | Repo | Issues | Parallel? |
|-----------|------|--------|-----------|
| v1.3 finish (7 remaining) | waaseyaa | 7 | Yes |
| v1.5.1 Google OAuth (6) | claudriel | 6 | Yes |
| v1.5.2 Framework Compliance (5) | claudriel | 5 | Yes |
| Page Enrichment finish (3) | minoo | 3 | Yes |
| v0.9 Stabilization (8 orphans) | minoo | 8 | Yes |

**Total:** ~29 issues. All parallel, no cross-repo dependencies.
**Why now:** Clears all near-complete work. Creates clean baseline for Phase 2.

---

### Phase 2: Near-Term (1-2 months) — "Foundation Upgrade"

| Milestone | Repo | Issues | Parallel? |
|-----------|------|--------|-----------|
| v1.4 DBAL Migration (17) | waaseyaa | 17 | Standalone |
| v1.5.3 User Settings (1+) | claudriel | 1+ | Yes (parallel with W:v1.4) |
| v1.5.4 Infrastructure (3+) | claudriel | 3+ | Partially (C:#226 blocked by W:v1.4) |
| Alpha Upgrade (2) | minoo | 2 | Blocked by W:v1.4 |
| Localization (1) | minoo | 1 | Yes |

**Total:** ~24 issues. W:v1.4 is the critical path.
**Why now:** DBAL is the single largest tech debt. Both apps depend on it. Blocking it further compounds risk.

---

### Phase 3: Mid-Term (2-4 months) — "New Features"

| Milestone | Repo | Issues | Parallel? |
|-----------|------|--------|-----------|
| v1.5 Admin Surface | waaseyaa | ~5-8 new | Yes |
| v1.6 Search Provider | waaseyaa | ~6-8 new | Yes (parallel with v1.5) |
| v1.8 Projects & Workspaces (32) | claudriel | 32 | Yes |
| v1.6 Agent Tool Expansion | claudriel | ~8-10 new | Yes (parallel with v1.8) |
| v1.0 Admin Surface | minoo | 4 | Blocked by W:v1.5 |
| v1.1 Entity Controllers | minoo | ~6-8 new | After M:v1.0 |

**Total:** ~60-70 issues.
**Why now:** Framework infrastructure enables app features. Projects & Workspaces and Agent Expansion are the two biggest Claudriel features.

---

### Phase 4: Long-Term (4-8 months) — "Maturity & Community"

| Milestone | Repo | Issues | Parallel? |
|-----------|------|--------|-----------|
| v1.7 Revision System | waaseyaa | ~6-8 new | Yes |
| v1.9 Production Queue | waaseyaa | ~4-6 new | Yes |
| v1.7 Notification Delivery | claudriel | ~6-8 new | Yes |
| v1.9 Pipeline Expansion | claudriel | ~6-8 new | After C:v1.8 |
| v1.10 Autonomous Ops | claudriel | ~8-10 new | Blocked by W:v1.9 |
| v1.2 Local Search | minoo | ~4-6 new | After W:v1.6 |
| v1.3 Domain Services | minoo | ~6-8 new | Yes |
| v1.4 Community Accounts | minoo | ~8-12 new | After M:v1.3 |

**Total:** ~50-70 issues.

**Beyond Phase 4:**
- Claudriel v2.0 Voice I/O
- Minoo v1.5 Content Authoring (waaseyaa workflows ready)
- Minoo v1.6 PWA & Offline
- Waaseyaa v2.0 Schema Evolution

---

## E) Required Milestone Cleanup

### Waaseyaa

| Action | Milestone | Reason |
|--------|-----------|--------|
| **Keep** | v1.3 — GraphQL & Cleanup | Active, 71% done |
| **Keep** | v1.4 — DBAL Migration | Critical path |
| **Keep** | v1.8 — Projects & Workspaces | Placeholder, intentional |
| **Create** | v1.5 — Admin Surface Completion | Blocks Minoo |
| **Create** | v1.6 — Search Provider | Blocks Minoo local search |
| **Create** | v1.7 — Revision System | Orphaned interfaces need implementation |
| **Create** | v1.9 — Production Queue Backend | Enables async for both apps |
| **Create** | v2.0 — Schema Evolution | Fixes schema drift for both apps |

### Minoo

| Action | Milestone | Reason |
|--------|-----------|--------|
| **Close** | GraphQL API (#25) | 0 open issues, premature |
| **Close** | v0.6 Navigation (#7) | 1 remaining issue → reassign to v0.9 |
| **Rename** | "Page Enrichment & Polishing..." → "v0.9 — Launch Polish" | Standardize naming |
| **Rename** | "Waaseyaa Alpha Upgrade" → "v0.10 — Framework Upgrade" | Standardize naming |
| **Keep** | v1.0 — Admin Surface | Active, blocked by waaseyaa |
| **Keep** | Anishinaabemowin Localization | Active, small |
| **Create** | v0.9 — Stabilization (or merge into renamed Launch Polish) | Consolidate orphan issues |
| **Create** | v1.1 — Entity Controller Coverage | 13 entities without controllers |
| **Create** | v1.2 — Local Search Fallback | NorthCloud dependency risk |
| **Create** | v1.3 — Domain Service Extraction | Business logic in controllers |
| **Create** | v1.4 — Community Member Accounts | Public user engagement |

### Claudriel

| Action | Milestone | Reason |
|--------|-----------|--------|
| **Close** | v1.6 Voice Input (#17) | Empty, aspirational, repurpose number |
| **Close** | v1.7 Speech Output (#18) | Empty, aspirational, repurpose number |
| **Keep** | v1.5.1 — Google OAuth Verification | Active |
| **Keep** | v1.5.2 — Framework Compliance | Sprint-ready |
| **Keep** | v1.5.3 — User Settings & Account | Active |
| **Keep** | v1.8 — Projects & Workspaces | 32 issues, planned |
| **Create** | v1.5.4 — Infrastructure Hardening | Group orphan issues + infra work |
| **Create** | v1.6 — Agent Tool Expansion | Repurpose number; 5 tools → ~15 |
| **Create** | v1.7 — Notification Delivery | Repurpose number; dead-end entity |
| **Create** | v1.9 — Pipeline Expansion | 2 steps → ~6-8 steps |
| **Create** | v1.10 — Autonomous Operations | Scheduled jobs, background processing |
| **Create** | v2.0 — Voice I/O | Combined voice input + output |

---

## F) Required Issue Cleanup

### Waaseyaa

| Action | Issue(s) | Detail |
|--------|----------|--------|
| No orphans | — | All open issues have milestones |
| No moves needed | — | Issues correctly placed |

### Minoo

| Action | Issue(s) | Detail |
|--------|----------|--------|
| **Assign milestone** | #241 (deploy png) | → v0.9 Stabilization |
| **Assign milestone** | #245 (CommunityController final) | → v0.9 Stabilization |
| **Assign milestone** | #246 (community lookup) | → v0.9 Stabilization |
| **Assign milestone** | #270 (pre-push hook) | → v0.9 Stabilization |
| **Assign milestone** | #276 (dictionary research) | → Anishinaabemowin Localization |
| **Assign milestone** | #278 (mobile responsiveness) | → v0.9 Stabilization |
| **Assign milestone** | #279 (Toronto content verification) | → v0.9 Stabilization |
| **Assign milestone** | #280 (location bar search) | → v0.9 Stabilization |
| **Assign milestone** | #281 (business cards link) | → v0.9 Stabilization |
| **Reassign** | v0.6 #??? (remaining 1 issue) | → v0.9 Stabilization |
| **Close milestone** | GraphQL API (#25) | 0 open issues |
| **Clean dead code** | New issue needed | Remove commented routes/services in providers |

### Claudriel

| Action | Issue(s) | Detail |
|--------|----------|--------|
| **Assign milestone** | #225 (PHP-FPM logging) | → v1.5.4 Infrastructure |
| **Assign milestone** | #226 (database-legacy) | → v1.5.4 Infrastructure; mark blocked by waaseyaa v1.4 |
| **Assign milestone** | #227 (sidebar open) | → v1.5.1 or v1.5.4 |
| **New issue** | .env.example + config validation | → v1.5.4 Infrastructure |
| **New issue** | Refactor Git code into src/Domain/Git/ | → v1.8 or v1.5.4 |
| **New issue** | Dynamic agent tool loader | → v1.6 Agent Expansion |
| **Clarify scope** | Artifact, Integration, Operation, TriageEntry | Are these internal-only entities? If yes, document. If no, add CRUD issues. |

---

## G) Cross-Repo Dependency Graph

```
                            ┌─────────────────────────┐
                            │     WAASEYAA (Framework) │
                            └─────────┬───────────────┘
                                      │
                    ┌─────────────────┼─────────────────┐
                    │                 │                   │
              ┌─────▼──────┐   ┌─────▼──────┐    ┌──────▼─────┐
              │ v1.3 GraphQL│   │ v1.4 DBAL  │    │ v1.5 Admin │
              │ (7 open)    │   │ (17 open)  │    │ Surface    │
              └──────┬──────┘   └──────┬─────┘    └──────┬─────┘
                     │                 │                  │
         ┌───────────┤           ┌─────┼──────┐          │
         │           │           │     │      │          │
         ▼           ▼           ▼     ▼      ▼          ▼
    M:GraphQL   C:v1.5.2    C:#226  M:Alpha  W:v1.7   M:v1.0
    API         (#229)      Infra   Upgrade  Revision  Admin
                                              │
                    ┌─────────────────────────┤
                    │                         │
              ┌─────▼──────┐           ┌──────▼─────┐
              │ v1.6 Search│           │ v1.9 Queue │
              │ Provider   │           │ Backend    │
              └──────┬─────┘           └──────┬─────┘
                     │                        │
                     ▼                        ▼
               M:v1.2 Local            C:v1.10 Auto
               Search                  Ops


         ┌──────────────────────────────────────────┐
         │           CLAUDRIEL (Application)         │
         └──────────────────────────────────────────┘

    v1.5.1 OAuth ──→ v1.5.3 Settings ──→ v1.6 Agent Tools ──→ v1.7 Notifications
         │
    v1.5.2 Compliance ──→ v1.5.4 Infra ──→ v1.8 Projects ──→ v1.9 Pipeline ──→ v1.10 Auto ──→ v2.0 Voice
                              │
                              └── #226 blocked by W:v1.4


         ┌──────────────────────────────────────────┐
         │              MINOO (Application)          │
         └──────────────────────────────────────────┘

    v0.9 Stabilization ──→ v1.0 Admin (blocked by W:v1.5) ──→ v1.1 Controllers ──→ v1.3 Domain Services
                                                                                          │
    Localization (parallel) ──────────────────────────────────────────────────→ v1.4 Accounts ──→ v1.5 Authoring
                                                                                                      │
    v1.2 Search (blocked by W:v1.6) ─────────────────────────────────────────────────────→ v1.6 PWA/Offline


         ┌──────────────────────────────────────────┐
         │         PARALLELIZATION MAP               │
         └──────────────────────────────────────────┘

    Phase 1:  W:v1.3  ║  C:v1.5.1  ║  C:v1.5.2  ║  M:Enrichment  ║  M:v0.9
                      ║            ║            ║                ║
    Phase 2:  W:v1.4 ═══════════════════════════════════════════════════════════
              C:v1.5.3 ║ C:v1.5.4(partial) ║ M:Localization ║ M:Alpha(blocked)
                       ║                   ║                ║
    Phase 3:  W:v1.5 ║ W:v1.6 ║ C:v1.8 ║ C:v1.6 ║ M:v1.0(blocked) ║ M:v1.1
                     ║        ║        ║        ║                  ║
    Phase 4:  W:v1.7 ║ W:v1.9 ║ C:v1.7 ║ C:v1.9 ║ M:v1.2 ║ M:v1.3 ║ M:v1.4
```

---

## H) Clarifying Questions

1. **Adopt milestone structure?** The dataset proposes 8 waaseyaa + 8 minoo + 10 claudriel milestones. Should I create all proposed new milestones now, or only the immediate ones (Phase 1-2)?

2. **Repurpose Claudriel v1.6/v1.7?** Currently "Voice Input" and "Speech Output" (empty). Proposed: close these, repurpose v1.6 for Agent Tool Expansion and v1.7 for Notification Delivery. Voice becomes v2.0. Acceptable?

3. **Close stale Minoo milestones?** GraphQL API (0 open) and v0.6 Navigation (1 remaining issue). Close both?

4. **Rename Minoo milestones?** "Page Enrichment & Polishing for Waaseyaa Flagship Launch" → "v0.9 — Launch Polish", "Waaseyaa Alpha Upgrade" → "v0.10 — Framework Upgrade". Adopt versioned naming?

5. **Assign orphan issues?** 8 Minoo + 3 Claudriel orphans identified. Assign per the recommendations?

6. **Standardize labels?** Create consistent label taxonomy across all three repos? Proposed: `bug`, `enhancement`, `chore`, `refactor`, `docs`, `blocked`, `breaking-change` + domain labels.

7. **Waaseyaa v1.4 priority?** 17 issues, 0 started. Should this be the singular waaseyaa focus in Phase 2, or can it be split into sub-phases (e.g., v1.4.1 core DBAL, v1.4.2 migration tooling)?

8. **Claudriel entity scope clarification?** Artifact, Integration, Operation, TriageEntry have no CRUD surfaces. Are these internal-only entities (document as such), or do they need UI/API? This affects milestone scope.

9. **Previous report correction: waaseyaa capabilities.** The strategic report underestimated waaseyaa's queue (17 files, feature-complete), cache (3 backends), and workflows (13 files, editorial state machine). These are NOT gaps requiring new milestones. Queue needs only a production backend driver. Should I update the strategic report, or does this dataset supersede it?

10. **Cross-repo project board?** Should all three repos share a GitHub project board for cross-repo dependency tracking, or keep tracking via issue cross-references?
