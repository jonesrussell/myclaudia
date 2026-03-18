# Strategic Planning Report: Cross-Repository Roadmap
**Date:** 2026-03-18
**Repos:** waaseyaa/framework, minoo/minoo, jonesrussell/claudriel

---

## A) Future-Facing Architectural Signals

### Waaseyaa (Framework)

**1. DBAL Migration Gap**
The framework has TWO database layers: `database-legacy` (PdoDatabase, custom query builders) and the newer DBAL direction. Every storage operation, every entity query, every migration runs through the legacy layer. v1.4 has 17 issues and 0 started. This is the single largest architectural debt across all three repos. Until this completes, both apps carry a deprecated dependency.

**2. Admin Surface is a Shell**
`packages/admin-surface/` exists but `src/Controller/` is empty. The admin SPA (Nuxt 3) works, but the "admin surface" abstraction (meant to let apps declare their admin catalog without building custom UIs) is incomplete. Minoo v1.0 Admin Surface (4 issues) is blocked on this becoming functional.

**3. Workflow Engine is Scaffolded, Not Built**
`packages/workflows/` exists in Layer 3 but has minimal implementation. Minoo needs editorial workflows (draft/review/publish). Claudriel needs commitment lifecycle workflows. Neither app can use a framework-level workflow engine because it doesn't exist yet.

**4. Search Package is Interface-Only**
`packages/search/` provides `SearchProviderInterface`, `SearchRequest`, `SearchResult` but no concrete provider. Minoo uses NorthCloudClient as a workaround. A framework-level search provider (SQLite FTS5, Elasticsearch adapter, or similar) would benefit both apps.

**5. Queue/Async is Scaffolded**
`packages/queue/` has interfaces and in-memory implementation but no production queue backend. Both apps do synchronous processing. As ingestion pipelines grow (Claudriel's Gmail, Minoo's NorthCloud), async processing becomes necessary.

**6. No Caching Strategy**
`packages/cache/` has `MemoryBackend` only. No file, Redis, or database cache backend. Both apps will need this for performance as entity counts grow.

**7. Missing Testing Infrastructure**
No database fixture system for integration tests beyond manual setup. Both apps use ad-hoc SQLite in-memory patterns. A standardized `DatabaseTestCase` with fixture loading would reduce test boilerplate.

**8. No Event Sourcing / Audit Trail**
`EntityWriteAuditListener` exists but audit storage is basic. No queryable audit log, no replay capability. Claudriel's commitment lifecycle and Minoo's editorial workflows would benefit from proper event sourcing.

**9. Mail Package is Basic**
`packages/mail/` exists but Minoo implements its own `MailService` in Support/. The framework mail package may not be feature-complete enough for app use.

**10. No File/Media Upload Pipeline**
`packages/media/` has entity types but no upload handling, image processing, or CDN integration. Minoo needs photos for ResourcePerson. Claudriel will need artifact storage.

---

### Minoo (Application)

**1. GIS System is Partially Built**
`src/Domain/Geo/` has CommunityFinder, LocationService, VolunteerRanker with Haversine calculations, but no actual geocoding service, no map UI, no spatial queries. The v0.7 "GIS-Aware" milestone (14 issues, closed) laid groundwork but the geo domain is incomplete.

**2. No Mobile Strategy**
The SSR site has responsive CSS but no PWA, no offline support, no native app path. For a community-focused platform used in rural areas (often with poor connectivity), offline access to dictionary entries would be valuable.

**3. Ingestion Pipeline is Batch-Only**
NorthCloud ingestion runs as batch import via CLI. No incremental sync, no webhook-based updates, no scheduled ingestion. As data sources grow (atlas-ling.ca dictionary, elder recordings), automated ingestion becomes necessary.

**4. No User Accounts for Community Members**
Minoo has admin accounts but no public user registration, no community member profiles, no saved favorites or learning progress. The platform is currently view-only for the public.

**5. No Content Authoring Workflow**
Despite having elders, teachers, and volunteers as entities, there's no way for these users to contribute content through the platform. All content comes through admin or ingestion.

**6. Search is External-Dependent**
Search relies entirely on NorthCloudClient. No local search fallback. If the external service goes down, search breaks.

**7. Admin Surface Not Wired**
4 open issues for wiring waaseyaa's admin-surface. Until this is done, admin CRUD is limited.

**8. Schema Drift Problem**
Adding fieldDefinitions to entity types doesn't auto-ALTER tables. Known issue (#273). Requires manual SQL or a schema check command. This will compound as entities evolve.

---

### Claudriel (Application)

**1. Access Control is Empty**
`src/Access/` contains only `.gitkeep` and `AuthenticatedAccount.php`. No access policies for any of the 19 entity types. This is a security gap — any authenticated user can access any entity. v1.5.2 #231 addresses this but scope is large (7+ policy classes).

**2. Monolithic Service Provider**
Single `ClaudrielServiceProvider` handles all 19 entity types, all routes, all service wiring. v1.5.2 #230 proposes splitting into 8 per-domain providers. This is structural debt that makes the codebase harder to navigate and modify.

**3. 13 Entity Types Missing fieldDefinitions**
Without fieldDefinitions, these entities have no GraphQL schema, no admin UI, no validation. v1.5.2 #229 tracks this. Until resolved, GraphQL is partial.

**4. Agent Tools are Minimal**
The Python agent has only 5 tools (gmail_list, gmail_read, gmail_send, calendar_list, calendar_create). For a "personal operations system," the agent needs: commitment management, workspace operations, brief queries, person lookups, schedule management. v1.8 adds git tools but the core ops tools are missing.

**5. No Notification System**
`TemporalNotification` entity exists but there's no delivery mechanism. No push notifications, no email notifications, no in-app notification center. The entity is a dead end.

**6. Chat is One-Way**
Chat works (SubprocessChatClient → agent/main.py) but there's no chat history search, no conversation branching, no multi-agent routing. The chat system is functional but primitive.

**7. Workspace-Event Assignment Not Implemented**
Workspace entity has `workspace_id` field, McEvent has `workspace_id` field, but the assignment logic (classifying events into workspaces) is a "future slice." Events float unassigned.

**8. No Scheduled Jobs**
No cron, no job scheduler, no background processing. Gmail ingestion, drift detection, commitment staleness checks — all must be triggered manually or via CLI. A scheduler is needed for autonomous operation.

**9. DayBrief is Static**
DayBriefAssembler produces a JSON snapshot. No real-time updates, no SSE streaming for brief changes, no push when new commitments arrive.

**10. No Data Export / Backup**
No way to export data, create backups, or migrate between environments. Single SQLite database with no redundancy.

---

## B) Natural Milestone Tracks

### Waaseyaa — Proposed Future Milestones

| # | Title | Description | Touches | Blocking | Sequence |
|---|-------|-------------|---------|----------|----------|
| v1.3 | GraphQL & Cleanup (existing) | Complete remaining 7 issues | graphql/, api/ | Minoo GraphQL API | Now |
| v1.4 | DBAL Migration (existing) | Replace database-legacy with DBAL | entity-storage/, foundation/, database-legacy/ | Both apps | After v1.3 |
| v1.5 | Admin Surface Completion | Make admin-surface functional: catalog, session, controllers, host contract | admin-surface/, admin/ | Minoo v1.0 Admin | After v1.4 |
| v1.6 | Production Infrastructure | Cache backends (file/Redis), queue backends (database/Redis), mail improvements | cache/, queue/, mail/ | Both apps performance | After v1.4 |
| v1.7 | Search Provider | Framework-level search: SQLite FTS5 provider, search indexing, autocomplete | search/ | Minoo local search | After v1.5 |
| v1.8 | Projects & Workspaces (existing) | Placeholder, may not need framework changes | — | — | TBD |
| v1.9 | Workflow Engine | State machine, transitions, guards, editorial workflows | workflows/ | Minoo content authoring | After v1.6 |
| v2.0 | Schema Evolution | Auto-ALTER on fieldDefinition changes, migration generation from entity diffs | entity-storage/, foundation/ | Both apps schema drift | After v1.4 |

---

### Minoo — Proposed Future Milestones

| # | Title | Description | Touches | Blocking | Sequence |
|---|-------|-------------|---------|----------|----------|
| v0.9 | Stabilization & Bugs | Assign 8 orphan issues, fix responsive, search, deploy issues | Controllers, CSS, config | — | Now |
| v1.0 | Admin Surface (existing) | Wire waaseyaa admin-surface into Minoo | Admin/, Provider/ | Blocked by waaseyaa v1.5 | After waaseyaa v1.5 |
| v1.1 | Local Search Fallback | SQLite FTS5 search as fallback when NorthCloud is unavailable | Support/, Search/ | Blocked by waaseyaa v1.7 | After waaseyaa v1.7 |
| v1.2 | Community Member Accounts | Public registration, saved favorites, learning progress, user profiles | Entity/User, Access/, Controller/ | — | After v1.0 |
| v1.3 | Content Authoring | Elder/teacher content submission, editorial review, moderation | Domain/, Workflows/ | Blocked by waaseyaa v1.9 | After waaseyaa v1.9 |
| v1.4 | Incremental Ingestion | Webhook-based NorthCloud sync, scheduled imports, delta processing | Ingestion/, Queue/ | Blocked by waaseyaa v1.6 | After waaseyaa v1.6 |
| v1.5 | PWA & Offline | Service worker, offline dictionary access, installable app | frontend/, public/ | — | After v1.2 |

---

### Claudriel — Proposed Future Milestones

| # | Title | Description | Touches | Blocking | Sequence |
|---|-------|-------------|---------|----------|----------|
| v1.5.1 | Google OAuth Verification (existing) | Production env, privacy policy, OAuth submission | Controller/, infra | — | Now |
| v1.5.2 | Framework Compliance (existing) | Entity constructors, fieldDefinitions, provider split, access policies | Entity/, Provider/, Access/ | — | Now |
| v1.5.3 | User Settings & Account (existing) | Google connection management, user preferences | Controller/, frontend/ | — | After v1.5.1 |
| v1.5.4 | Infrastructure Hardening | PHP-FPM logging (#225), database-legacy removal (#226), sidebar (#227), scheduled jobs | infra, config | Partially blocked by waaseyaa v1.4 | After v1.5.2 |
| v1.6 | Agent Tool Expansion | Commitment CRUD, person lookup, brief queries, schedule management, workspace ops via agent | agent/, Controller/Internal* | — | After v1.5.3 |
| v1.7 | Notification System | TemporalNotification delivery, email notifications, in-app notification center, push | Domain/Notification/, frontend/ | — | After v1.6 |
| v1.8 | Projects & Workspaces (existing) | Full project/workspace system, git integration, drift detection | Entity/, Domain/Workspace/, frontend/ | — | After v1.5.4 |
| v1.9 | Autonomous Operations | Scheduled Gmail ingestion, auto-drift-check, background commitment extraction, job scheduler | Domain/, Pipeline/, Queue/ | Blocked by waaseyaa v1.6 | After v1.8 |
| v2.0 | Voice I/O | Speech-to-text input, text-to-speech output (replaces empty v1.6/v1.7) | agent/, frontend/ | — | After v1.9 |

---

## C) Cross-Repo Milestone Alignment

### Dependency Graph

```
waaseyaa v1.3 (GraphQL)
  ├─→ minoo GraphQL API
  └─→ claudriel GraphQL stability

waaseyaa v1.4 (DBAL)
  ├─→ claudriel v1.5.4 (#226 database-legacy removal)
  ├─→ minoo Waaseyaa Alpha Upgrade
  └─→ waaseyaa v2.0 (Schema Evolution)

waaseyaa v1.5 (Admin Surface)
  └─→ minoo v1.0 (Admin Surface)

waaseyaa v1.6 (Production Infra)
  ├─→ claudriel v1.9 (Autonomous Ops — needs queue)
  └─→ minoo v1.4 (Incremental Ingestion — needs queue)

waaseyaa v1.7 (Search Provider)
  └─→ minoo v1.1 (Local Search Fallback)

waaseyaa v1.9 (Workflow Engine)
  └─→ minoo v1.3 (Content Authoring)

claudriel v1.5.2 (Framework Compliance)
  └─→ claudriel v1.8 (Projects & Workspaces — needs clean entity layer)

claudriel v1.8 (Projects & Workspaces)
  └─→ claudriel v1.9 (Autonomous Ops — workspace-aware scheduling)
```

### Parallel Tracks

These can run simultaneously without cross-repo blocking:

| Track A (waaseyaa) | Track B (claudriel) | Track C (minoo) |
|---------------------|---------------------|-----------------|
| v1.3 finish | v1.5.1 + v1.5.2 | Page Enrichment finish |
| v1.4 start | v1.5.3 | v0.9 Stabilization |
| v1.4 finish | v1.5.4 (partial) | Waaseyaa Alpha Upgrade |

### Must-Sequence Pairs

| First | Then | Reason |
|-------|------|--------|
| waaseyaa v1.3 | minoo GraphQL API | Framework GraphQL must be stable |
| waaseyaa v1.4 | claudriel #226 | Can't remove dep until framework migrates |
| waaseyaa v1.5 | minoo v1.0 Admin | Admin surface must be functional |
| claudriel v1.5.2 | claudriel v1.8 | Entity layer must be clean first |
| waaseyaa v1.6 | claudriel v1.9 | Need production queue backend |

### Merge/Rename Candidates

| Current | Proposed | Reason |
|---------|----------|--------|
| Claudriel v1.6 "Voice Input" + v1.7 "Speech Output" | Claudriel v2.0 "Voice I/O" | Combine into single milestone, clear current empties |
| Minoo "Page Enrichment & Polishing for Waaseyaa Flagship Launch" | Minoo v0.9 "Launch Polish" | Standardize naming |
| Minoo "Waaseyaa Alpha Upgrade" | Minoo v0.10 "Framework Upgrade" | Versioned naming |
| Minoo "GraphQL API" | Close or rename to "v1.0.1 — GraphQL API" | Depends on waaseyaa v1.3 |

---

## D) Feature Track Identification

| Track | Type | Repos | Status | Key Issues/Milestones |
|-------|------|-------|--------|----------------------|
| **DBAL Migration** | Foundational | waaseyaa, claudriel, minoo | Blocked (not started) | waaseyaa v1.4 (17 issues), claudriel #226 |
| **Admin Surface** | Cross-repo | waaseyaa, minoo | Blocked (waaseyaa incomplete) | waaseyaa v1.5, minoo v1.0 (4 issues) |
| **GraphQL Maturity** | Cross-repo | waaseyaa, minoo, claudriel | Active | waaseyaa v1.3 (7 open), minoo GraphQL API |
| **Framework Compliance** | Application | claudriel | Sprint-ready | claudriel v1.5.2 (5 issues, mechanical) |
| **OAuth & Production** | Application | claudriel | Sprint-ready | claudriel v1.5.1 (6 issues) |
| **Projects & Workspaces** | Application | claudriel (+ waaseyaa placeholder) | Planned | claudriel v1.8 (32 issues) |
| **Agent Expansion** | Application | claudriel | Future | claudriel v1.6 proposed (new tools) |
| **Language Revitalization** | Application | minoo | Active | Anishinaabemowin Localization, dictionary research |
| **GIS & Community** | Application | minoo | Paused | v0.7 closed but geo domain incomplete |
| **Production Infra** | Foundational | waaseyaa | Future | waaseyaa v1.6 (cache, queue, mail) |
| **Search** | Cross-repo | waaseyaa, minoo | Future | waaseyaa v1.7, minoo v1.1 |
| **Workflow Engine** | Cross-repo | waaseyaa, minoo | Future | waaseyaa v1.9, minoo v1.3 |
| **Voice I/O** | Application | claudriel | Aspirational | claudriel v2.0 proposed |
| **Schema Evolution** | Foundational | waaseyaa, minoo | Future | waaseyaa v2.0 proposed |

### Track Dependencies

```
Foundational:  DBAL Migration → Production Infra → Schema Evolution
Cross-repo:    GraphQL Maturity → Admin Surface → Search → Workflow Engine
App (claudriel): Framework Compliance → OAuth/Production → Projects & Workspaces → Agent Expansion → Autonomous Ops → Voice I/O
App (minoo):    Launch Polish → Admin Surface → Community Accounts → Content Authoring → PWA/Offline
```

---

## E) Multi-Phase Roadmap Proposal

### Phase 1: Immediate (next 2-4 weeks)

**Goal:** Finish active work, stabilize both apps, clear blockers.

| Milestone | Repo | Issues | Why Now |
|-----------|------|--------|---------|
| v1.3 finish (7 remaining) | waaseyaa | 7 open | 71% done, unblocks Minoo GraphQL |
| v1.5.1 Google OAuth (6) | claudriel | 6 open | Production readiness, external dependency (Google review) |
| v1.5.2 Framework Compliance (5) | claudriel | 5 open | Mechanical, cleans entity layer for v1.8 |
| Page Enrichment finish (3) | minoo | 3 open | 88% done, launch quality |
| v0.9 Stabilization (8) | minoo | 8 orphans | Assign and fix outstanding bugs |

**Cross-repo deps:** None in this phase. All parallel.
**Total issues:** ~29
**Why this order:** Clears all near-complete work and bug backlog. No new features, just finishing and stabilizing.

---

### Phase 2: Near-Term (1-2 months)

**Goal:** Major framework upgrade, app infrastructure hardening.

| Milestone | Repo | Issues | Why Now |
|-----------|------|--------|---------|
| v1.4 DBAL Migration (17) | waaseyaa | 17 open | Largest tech debt, blocks both apps |
| v1.5.3 User Settings (1+) | claudriel | 1+ open | Google connection management |
| v1.5.4 Infrastructure (3+) | claudriel | #225, #226, #227 + new | PHP-FPM logging, database-legacy, sidebar |
| Waaseyaa Alpha Upgrade (2) | minoo | 2 open | Align with framework v1.4 |
| Anishinaabemowin Localization (1) | minoo | 1 open | Small, can run in parallel |

**Cross-repo deps:** claudriel #226 blocked until waaseyaa v1.4 completes. Minoo upgrade blocked until waaseyaa v1.4 completes.
**Total issues:** ~24
**Why this order:** DBAL migration is the critical path. Everything else runs in parallel where possible.

---

### Phase 3: Mid-Term (2-4 months)

**Goal:** New features, cross-repo infrastructure.

| Milestone | Repo | Issues | Why Now |
|-----------|------|--------|---------|
| v1.5 Admin Surface Completion | waaseyaa | ~5-8 new | Enables Minoo admin |
| v1.6 Production Infra | waaseyaa | ~8-12 new | Cache, queue, mail backends |
| v1.8 Projects & Workspaces | claudriel | 32 open | Major feature track |
| v1.6 Agent Tool Expansion | claudriel | ~8-10 new | Core ops tools for agent |
| v1.0 Admin Surface | minoo | 4 open | Wire framework admin |
| GraphQL API | minoo | ~4-6 new | API-first access |

**Cross-repo deps:** Minoo Admin blocked by waaseyaa v1.5. Claudriel v1.9 (future) blocked by waaseyaa v1.6.
**Total issues:** ~60-70
**Why this order:** Framework infrastructure enables app features. Projects & Workspaces is the biggest Claudriel feature.

---

### Phase 4: Long-Term (4-8 months)

**Goal:** Advanced features, autonomy, community engagement.

| Milestone | Repo | Issues | Why Now |
|-----------|------|--------|---------|
| v1.7 Search Provider | waaseyaa | ~6-8 new | Framework search |
| v1.9 Workflow Engine | waaseyaa | ~8-12 new | Editorial workflows |
| v2.0 Schema Evolution | waaseyaa | ~5-8 new | Auto-ALTER tables |
| v1.7 Notification System | claudriel | ~6-8 new | Temporal notification delivery |
| v1.9 Autonomous Operations | claudriel | ~8-10 new | Scheduled jobs, auto-ingestion |
| v2.0 Voice I/O | claudriel | ~8-12 new | Speech-to-text, text-to-speech |
| v1.1 Local Search Fallback | minoo | ~4-6 new | SQLite FTS5 search |
| v1.2 Community Accounts | minoo | ~8-12 new | Public registration, profiles |
| v1.3 Content Authoring | minoo | ~8-10 new | Elder/teacher submissions |

**Cross-repo deps:** Minoo Search blocked by waaseyaa v1.7. Minoo Authoring blocked by waaseyaa v1.9. Claudriel Autonomous Ops blocked by waaseyaa v1.6.
**Why this order:** These are large, complex features that depend on the infrastructure laid in Phase 2-3.

---

### Visual Roadmap

```
Week:    1   2   3   4   5   6   7   8   9  10  11  12  13  14  15  16  17+

WAASEYAA ├─v1.3 finish─┤
                        ├────────v1.4 DBAL Migration────────┤
                                                            ├──v1.5 Admin Surface──┤
                                                            ├──v1.6 Prod Infra─────┤
                                                                                    ├─v1.7+ ...

CLAUDRIEL├─v1.5.1 OAuth─┤
         ├─v1.5.2 Compliance─┤
                        ├─v1.5.3──┤
                        ├─v1.5.4 Infra──┤
                                        ├──────────v1.8 Projects & Workspaces──────┤
                                                   ├─v1.6 Agent Tools─┤
                                                                       ├─v1.7+ ...

MINOO    ├─Enrichment──┤
         ├─v0.9 Stabil.┤
                        ├─Alpha Upgrade──┤
                        ├─Localization───┤
                                         ├──v1.0 Admin Surface──┤
                                         ├──GraphQL API─────────┤
                                                                 ├─v1.1+ ...
```

---

## F) Repo-Specific Recommendations

### Waaseyaa

**Milestone cleanup:**
- v1.3: Finish remaining 7 issues, close milestone
- v1.4: This is the priority. 17 issues, needs focused execution
- v1.8: Keep as placeholder, add description note about trigger conditions

**New milestones:**
- v1.5 Admin Surface Completion
- v1.6 Production Infrastructure (cache/queue/mail)
- v1.7 Search Provider
- v1.9 Workflow Engine
- v2.0 Schema Evolution

**Label taxonomy:**
- Add: `breaking-change`, `blocked`, `framework-contract`
- Standardize: `bug`, `enhancement`, `chore`, `docs`, `refactor`

**Structural refactors:**
- Complete admin-surface Controller implementation
- Extract production cache/queue backends from MemoryBackend stubs
- Add `DatabaseTestCase` to testing package

---

### Minoo

**Milestone cleanup:**
- Close "GraphQL API" (0 open issues, premature)
- Rename "Page Enrichment & Polishing for Waaseyaa Flagship Launch" → "v0.9 — Launch Polish"
- Rename "Waaseyaa Alpha Upgrade" → "v0.10 — Framework Upgrade"
- Close "v0.6 — Navigation & App Structure" (1 remaining issue should be reassigned)

**Issue reassignments:**
- #270 (pre-push hook) → v0.9 Stabilization or standalone maintenance
- #276 (dictionary research) → Anishinaabemowin Localization
- #277 (untranslated strings) → Anishinaabemowin Localization (already there)
- #278-#281 (bugs) → v0.9 Stabilization
- #241 (deploy png) → v0.9 Stabilization
- #245, #246 (quality) → v0.9 Stabilization

**New milestones:**
- v0.9 — Stabilization (consolidate orphan bugs)
- v1.1 — Local Search Fallback
- v1.2 — Community Accounts
- v1.3 — Content Authoring

**Label taxonomy:**
- Has richest labels already (bug, enhancement, quality, research, language, data-source, audit)
- Add: `blocked`, `framework-dependency`
- Standardize with waaseyaa/claudriel: same base labels

**Structural refactors:**
- Extract NorthCloudClient search into a proper search adapter
- Schema drift: implement or adopt waaseyaa's schema:check + auto-ALTER

---

### Claudriel

**Milestone cleanup:**
- Close v1.6 "Voice Input" and v1.7 "Speech Output" (empty, aspirational)
- Create v2.0 "Voice I/O" as combined future milestone
- Reassign orphans: #225, #226, #227 → new v1.5.4 Infrastructure

**Issue reassignments:**
- #225 (PHP-FPM) → v1.5.4 Infrastructure
- #226 (database-legacy) → v1.5.4 Infrastructure (mark blocked by waaseyaa v1.4)
- #227 (sidebar) → v1.5.4 Infrastructure or v1.5.1

**New milestones:**
- v1.5.4 — Infrastructure Hardening
- v1.6 — Agent Tool Expansion (repurpose number from Voice Input)
- v1.7 — Notification System (repurpose number from Speech Output)
- v1.9 — Autonomous Operations
- v2.0 — Voice I/O

**Label taxonomy:**
- Currently almost no labels. Add full taxonomy:
  - Type: `bug`, `enhancement`, `chore`, `refactor`, `docs`
  - Domain: `entity`, `frontend`, `agent`, `ingestion`, `infra`, `auth`, `git`
  - Status: `blocked`, `sprint-ready`
  - Priority: `P0-critical`, `P1-high`, `P2-medium`, `P3-low`

**Structural refactors:**
- Split ClaudrielServiceProvider (#230) — mechanical, do in v1.5.2
- Add access policies (#231) — security priority
- Implement TemporalNotification delivery (currently a dead-end entity)
- Extract GitOperator + GitRepositoryManager into src/Domain/Git/ (currently split across src/Layer2/ and src/Service/)

---

## G) Clarifying Questions

1. **Adopt the proposed milestone structure?** The report proposes renumbering Claudriel's v1.6/v1.7 (Voice) → v2.0, and using v1.6/v1.7 for Agent Tools and Notifications instead. This changes the roadmap order. Acceptable?

2. **Create new milestones now?** Should I create the proposed new milestones (waaseyaa v1.5-v1.9, minoo v0.9-v1.3, claudriel v1.5.4-v2.0) now, or wait until each becomes active?

3. **Close empty/stale milestones?** Should I close: Claudriel v1.6/v1.7 (empty), Minoo GraphQL API (stale), Minoo v0.6 Navigation (1 remaining issue)?

4. **Assign orphan issues?** Should I assign Minoo's 8 and Claudriel's 3 orphan issues to milestones per the recommendations?

5. **Standardize milestone naming?** Should I rename Minoo's descriptive milestones to versioned format (e.g., "Page Enrichment" → "v0.9 — Launch Polish")?

6. **Create shared label taxonomy?** Should I create a consistent label set across all three repos?

7. **Waaseyaa v1.4 priority:** The DBAL migration is 17 issues and blocks both apps. Should this be the single focus for waaseyaa in Phase 2, or split into sub-phases?

8. **Claudriel v1.8 timing:** Projects & Workspaces (32 issues) is large. Should it wait for v1.5.4 Infrastructure, or can it start in parallel once v1.5.2 completes?

9. **Minoo admin surface:** Currently blocked by waaseyaa. Should Minoo create a temporary admin solution, or wait for the framework?

10. **Cross-repo milestone tracking:** Should all three repos share a project board or tracking mechanism for cross-repo dependencies, or keep tracking informal via issue cross-references?
