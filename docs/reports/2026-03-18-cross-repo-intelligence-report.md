# Cross-Repository Intelligence Report
**Date:** 2026-03-18
**Repos:** waaseyaa/framework, minoo/minoo, jonesrussell/claudriel

---

## A) Repository Architecture Analysis

### 1. Waaseyaa (Framework)

**Purpose:** Entity-first, AI-native PHP 8.4+ CMS framework. 43-package monorepo built on Symfony 7.x components.

**Architecture:** 7-layer DDD with strict upward-import discipline.

| Layer | Name | Packages | Purpose |
|-------|------|----------|---------|
| 0 | Foundation | foundation, cache, plugin, typed-data, database-legacy, testing, i18n, queue, state, validation, mail | Kernel, middleware, events, config, migrations |
| 1 | Core Data | entity, entity-storage, access, user, config, field | Entity type system, storage drivers, access control |
| 2 | Content Types | node, taxonomy, media, path, menu, note, relationship | Domain entities (content, tagging, assets, URLs) |
| 3 | Services | workflows, search | Business logic, discovery |
| 4 | API | api, routing | HTTP/JSON:API, route matching |
| 5 | AI | ai-schema, ai-agent, ai-pipeline, ai-vector | LLM, embeddings, agent execution, MCP |
| 6 | Interfaces | cli, admin, admin-surface, graphql, mcp, ssr, telescope | CLI, admin SPA, GraphQL, SSR, diagnostics |

**Key capabilities:**
- ContentEntityBase / ConfigEntityBase hierarchy
- SqlEntityStorage with JSON `_data` blob for dynamic fields
- Auto-table creation via SqlSchemaHandler (no migration files needed for entities)
- GraphQL auto-schema from EntityType.fieldDefinitions
- Access control: entity-level deny-by-default, field-level allow-by-default
- Admin SPA: Nuxt 3 + Vue 3 + TypeScript
- AI pipeline, vector store, MCP server

**Reusable components:** Entity system, access policies, GraphQL auto-schema, admin surface, SSR/Twig, CLI framework, AI pipeline, validation constraints.

**Tech debt:**
- `database-legacy` package (PdoDatabase) slated for removal in v1.4
- 17 open issues for DBAL migration
- admin-surface/src/Controller is empty placeholder

**Stats:** ~618 PHP source files, ~466 test files, 55 frontend tests.

---

### 2. Minoo (Application)

**Purpose:** Ojibwe language revitalization platform. Ingests cultural language data, presents learning resources, community events, and elder support workflows.

**Architecture:** Thin application layer on Waaseyaa. SSR-first (Twig 3), no SPA.

**Entity types (17):**
- Language: DictionaryEntry, ExampleSentence, WordPart, Speaker
- Community: Community, CulturalGroup, CulturalCollection
- Events/Teachings: Event, Group, Teaching, TeachingType
- People: Person, ElderSupportRequest, ResourcePerson
- Config: EventType, GroupType, FeaturedItem, IngestLog

**Key subsystems:**
- 13 service providers, 10 access policies, 14 HTTP controllers
- NorthCloud data ingestion pipeline (CSV/JSON → EntityMapper → Materializer)
- Geo domain: CommunityFinder, LocationService, VolunteerRanker (Haversine)
- Search via NorthCloud integration
- Vanilla CSS (CSS layers), Twig 3 SSR templates
- SQLite database

**Tech debt:**
- Schema drift: adding fieldDefinitions doesn't auto-ALTER tables (#273)
- @layer utilities broken (#273)
- Homepage nearby fallback issues with NULL community status
- Stale manifest cache requires manual deletion

**Stats:** 208 tests, 501 assertions.

---

### 3. Claudriel (Application)

**Purpose:** AI personal operations system. Ingests Gmail, extracts commitments via AI, surfaces daily brief and chat interface.

**Architecture:** Layered on Waaseyaa. Has both SSR and SPA (Nuxt 3 admin). Python agent subprocess for chat.

**Entity types (19):**
- Core: Account, Tenant, Workspace
- Ingestion: McEvent, Person, Commitment, CommitmentExtractionLog
- Chat: ChatSession, ChatMessage
- Operations: Operation, IssueRun, Integration, Skill, Artifact
- Schedule: ScheduleEntry, TriageEntry, TemporalNotification
- Auth: AccountPasswordResetToken, AccountVerificationToken

**Key subsystems:**
- Ingestion: Gmail → GmailMessageNormalizer → EventHandler → McEvent + Person
- Pipeline: CommitmentExtractionStep (AI), WorkspaceClassificationStep, ActionabilityStep
- DayBrief: DayBriefAssembler, DriftDetector (commitment staleness)
- Chat: SubprocessChatClient → agent/main.py (Python, Anthropic Messages API)
- Git: GitRepositoryManager (clone/pull), GitOperator (diff/status/commit/push)
- 19 controllers, 13 CLI commands + 6 workspace CLI utilities
- Admin: Nuxt 3 SPA with GraphQL, entity CRUD, telescope

**Tech debt:**
- Access/, Search/, Seed/ are empty reserved namespaces
- 13 of 19 entity types missing fieldDefinitions (#229)
- Monolithic ClaudrielServiceProvider (#230)
- Entity constructors use string literals instead of $this->entityTypeId (#228)
- ConsoleKernel auto-discovery may need explicit wiring (#9)

**Stats:** 19 entity types, Python agent with 5 tools, 13 docs/specs files.

---

### Cross-Repo Dependencies

```
waaseyaa/framework
  ├── minoo/minoo         (depends on: entity, entity-storage, access, foundation, routing, ssr, cli, field, validation, admin-surface)
  └── claudriel/claudriel (depends on: entity, entity-storage, foundation, routing, cli, api, graphql, ai-pipeline)
```

Both apps depend on waaseyaa but not on each other.
Waaseyaa v1.4 (DBAL migration) is a blocking dependency for both apps.

---

## B) Milestones Overview

### Waaseyaa (3 open, 23 closed)

| # | Title | Open | Closed | Status |
|---|-------|------|--------|--------|
| 24 | v1.3 — GraphQL & Cleanup | 7 | 17 | **Active** — near completion |
| 25 | v1.4 — Remove database-legacy & Unify Under DBAL | 17 | 0 | **Active** — large, not started |
| 26 | v1.8 Projects & Workspaces | 0 | 0 | **Placeholder** — empty |

**Assessment:** v1.3 is 71% complete. v1.4 is the largest open work block across all repos (17 issues, 0 closed). v1.8 is a cross-repo placeholder.

### Minoo (5 open, 20 closed)

| # | Title | Open | Closed | Status |
|---|-------|------|--------|--------|
| 20 | v1.0 - Admin Surface | 4 | 0 | **Active** — not started |
| 21 | Anishinaabemowin Localization | 1 | 0 | **Active** — small |
| 23 | Page Enrichment & Polishing for Waaseyaa Flagship Launch | 3 | 23 | **Active** — near completion |
| 24 | Waaseyaa Alpha Upgrade | 2 | 2 | **Active** — half done |
| 25 | GraphQL API | 0 | 1 | **Stale** — 1 closed, 0 open, appears abandoned |

**Assessment:** Minoo has 5 issues with NO milestone (#241, #245, #246, #270, #276-#281). Page Enrichment is 88% complete. GraphQL API milestone may be premature (depends on waaseyaa v1.3 completing).

### Claudriel (6 open, 18 closed)

| # | Title | Open | Closed | Status |
|---|-------|------|--------|--------|
| 17 | v1.6 Voice Input | 0 | 0 | **Empty** — aspirational |
| 18 | v1.7 Speech Output | 0 | 0 | **Empty** — aspirational |
| 19 | v1.5.1 Google OAuth Verification | 6 | 1 | **Active** |
| 22 | v1.5.2 Framework Compliance | 5 | 0 | **Active** — not started |
| 23 | v1.5.3 User Settings & Account | 1 | 0 | **Active** — small |
| 24 | v1.8 Projects & Workspaces | 32 | 1 | **Active** — large, just created |

**Assessment:** v1.6/v1.7 are empty placeholders with no code or issues. v1.5.x is the active work track. v1.8 is the largest milestone (32 open). Claudriel also has unassigned issues (#225, #226, #227).

---

## C) Issues Overview

### Waaseyaa Open Issues (24 total)

**v1.3 — GraphQL & Cleanup (7 open):**
- Framework-level GraphQL issues. Actionable, no blockers visible.

**v1.4 — Remove database-legacy (17 open):**
- Complete DBAL migration. Large, sequential dependency chain. This is the most impactful framework work — both apps depend on database-legacy.

### Minoo Open Issues (23 total)

**v1.0 - Admin Surface (4 open):**
- #247 Implement AdminSurfaceHost, #248 Define catalog, #249 Wire access policies, #250 Expose endpoints
- Depends on waaseyaa admin-surface package being stable

**Page Enrichment (3 open):**
- #305 Playwright smoke tests, #306 Lighthouse audit, #307 verification checklist
- Near completion, quality-gate issues

**Waaseyaa Alpha Upgrade (2 open):**
- #309 adopt migration system, #312 deploy to production
- Depends on waaseyaa being stable

**Anishinaabemowin Localization (1 open):**
- #277 sweep untranslated strings

**No milestone (8 open):**
- #270 fix pre-push hook (audit)
- #276 research: mine dictionary data (research)
- #277-#281 bugs and chores (should be milestoned)

### Claudriel Open Issues (47 total)

**v1.5.1 Google OAuth Verification (6 open):**
- #186 submit OAuth app, #187 production environment, #200 scope docs, #221 admin schema bug, #222 ansible vault, #223 dashboard widget error

**v1.5.2 Framework Compliance (5 open):**
- #228-#232: entity constructors, fieldDefinitions, provider split, access policies, controller signatures

**v1.5.3 User Settings & Account (1 open):**
- #233 Google OAuth connection management

**v1.8 Projects & Workspaces (32 open):**
- #234-#266: full project/workspace system (just created this session)

**No milestone (3 open):**
- #225 PHP-FPM logging (infra)
- #226 remove database-legacy dep (infra, related to waaseyaa v1.4)
- #227 sidebar open by default (UI)

---

## D) Cross-Repo Alignment Analysis

### 1. Orphans and Duplicates

**Orphan milestones (exist but empty):**
- Claudriel v1.6 Voice Input (0 issues)
- Claudriel v1.7 Speech Output (0 issues)
- Waaseyaa v1.8 Projects & Workspaces (placeholder, intentional)

**Orphan issues (no milestone):**
- Waaseyaa: 0 open without milestone (good)
- Minoo: 8 open without milestone (#241, #245, #246, #270, #276-#281)
- Claudriel: 3 open without milestone (#225, #226, #227)

**Duplicated milestones across repos:**
- v1.8 Projects & Workspaces exists in both waaseyaa and claudriel (intentional, waaseyaa is placeholder)

**Duplicated issues across repos:**
- Claudriel #226 ("remove waaseyaa/database-legacy dependency") overlaps with waaseyaa v1.4 milestone scope. Claudriel's issue may become a downstream consequence of waaseyaa v1.4 completing, not independent work.

### 2. Inconsistencies

**Milestone numbering:**
- Waaseyaa: v1.3, v1.4 (sequential, clean)
- Minoo: v0.6, v0.7, v0.8, v1.0, then named milestones ("Waaseyaa Alpha Upgrade", "Page Enrichment", "GraphQL API", "Anishinaabemowin Localization") — mixed versioned and named
- Claudriel: v1.5.1, v1.5.2, v1.5.3, v1.6, v1.7, v1.8 (sequential with sub-versions)

**Label taxonomy:**
- Waaseyaa: uses labels (bug, enhancement, etc.)
- Minoo: uses labels (bug, enhancement, quality, research, language, data-source, audit)
- Claudriel: uses labels (audit) — very sparse labeling

**Naming conventions:**
- Minoo milestones mix version numbers with descriptive names
- Minoo's "GraphQL API" milestone (25) has 0 open issues but state=open (should close or repurpose)

### 3. Cross-Repo Dependencies

| App Issue | Depends On (Framework) | Nature |
|-----------|----------------------|--------|
| Claudriel #226 (remove database-legacy) | Waaseyaa v1.4 completion | Blocked until framework migrates |
| Claudriel #229 (fieldDefinitions) | Waaseyaa GraphQL auto-schema | Depends on stable schema generation |
| Minoo #309 (adopt migration system) | Waaseyaa migration tooling | Framework feature dependency |
| Minoo v1.0 Admin Surface | Waaseyaa admin-surface package | Framework feature dependency |
| Minoo GraphQL API | Waaseyaa v1.3 (GraphQL) | Blocked until framework GraphQL is stable |

### 4. Sprint-Ready Clusters

**Cluster A: Framework Stabilization (waaseyaa-first)**
- Waaseyaa v1.3 remaining 7 issues → enables Minoo GraphQL, Claudriel GraphQL stability
- Sequential, no cross-repo blockers

**Cluster B: Claudriel Production Readiness (parallel with A)**
- v1.5.1 (#186, #187, #200, #222, #225) — OAuth verification + infra
- v1.5.2 (#228-#232) — framework compliance
- v1.5.3 (#233) — user settings
- Can run in parallel with waaseyaa v1.3

**Cluster C: Minoo Launch Polish (parallel with A, B)**
- Page Enrichment remaining 3 issues
- Unassigned bugs (#278-#281)
- Can run independently

**Cluster D: DBAL Migration (waaseyaa v1.4, after A)**
- 17 issues, sequential
- Blocks Claudriel #226
- Should complete before v1.8 Projects & Workspaces

**Cluster E: Projects & Workspaces (claudriel v1.8, after B+D)**
- 32 issues with dependency DAG
- Root: #234 (Project entity)
- Longest path: #234 → #237 → #240 → #241 → #242 → #248 → #250

---

## E) Architectural Recommendations

### Milestone Cleanup

1. **Close Minoo "GraphQL API" milestone (#25)** — 0 open issues, 1 closed. Premature; depends on waaseyaa v1.3 completing. Reopen when ready.
2. **Close or repurpose Claudriel v1.6/v1.7** — empty aspirational milestones with no issues or code. Either add placeholder issues or close and recreate when ready.
3. **Assign Minoo orphan issues** — 8 open issues without milestones. #278-#281 (bugs) should go into a bugfix milestone or Page Enrichment. #270 (pre-push hook) into a maintenance milestone. #276 (research) into Anishinaabemowin Localization.
4. **Assign Claudriel orphan issues** — #225 (PHP-FPM) and #227 (sidebar) should go into v1.5.1 or a new v1.5.x infra milestone. #226 should reference waaseyaa v1.4 as a blocker.

### Issue Consolidation

1. **Claudriel #226** should be marked as blocked by waaseyaa v1.4 and not worked independently.
2. **Claudriel v1.5.2 (#228-#232)** could be executed as a single PR batch (mechanical changes).

### Label Taxonomy Alignment

All three repos should share a common label set:
- `bug`, `enhancement`, `chore`, `refactor`, `docs`
- `blocked`, `breaking-change`
- Domain labels: `entity`, `frontend`, `infra`, `agent`, `ingestion`, `auth`
- Priority: `P0-critical`, `P1-high`, `P2-medium`, `P3-low`

Claudriel currently has almost no labels. Minoo has the richest set.

### Cross-Repo Milestone Numbering

Current state is inconsistent. Options:
- **Option A:** Each repo has independent version numbers (current state, but Minoo mixes named+versioned)
- **Option B:** Shared major versions, independent minors (e.g., all repos release v1.x together)
- **Recommendation:** Keep independent versioning but standardize format. All milestones should be `vX.Y — Description`. Minoo should rename "Page Enrichment & Polishing" to "v0.9 — Page Enrichment" etc.

### Suggested New Milestones

1. **Minoo: v0.9 — Stabilization & Bugs** — consolidate the 8 unassigned issues
2. **Claudriel: v1.5.4 — Infrastructure** — group #225 (PHP-FPM), #226 (database-legacy), #227 (sidebar)
3. **Waaseyaa: v1.5 — Admin Surface Enhancements** — if Minoo v1.0 Admin Surface reveals framework gaps

### Suggested Sequencing

```
Phase 1 (now):     Waaseyaa v1.3 (finish GraphQL)
                   + Claudriel v1.5.1/v1.5.2 (production readiness)
                   + Minoo Page Enrichment (finish)

Phase 2 (next):    Waaseyaa v1.4 (DBAL migration — largest block)
                   + Claudriel v1.5.3 (user settings)
                   + Minoo v1.0 Admin Surface

Phase 3 (after):   Claudriel v1.8 (Projects & Workspaces)
                   + Minoo Anishinaabemowin Localization
                   + Minoo Waaseyaa Alpha Upgrade (align with v1.4)

Phase 4 (future):  Claudriel v1.6/v1.7 (Voice I/O)
```

### Framework-Level Abstractions for Waaseyaa

1. **No git package needed** — git operations are app-level (Claudriel-specific). Don't add to framework.
2. **Admin Surface needs work** — admin-surface/src/Controller is empty. Minoo v1.0 Admin Surface depends on this.
3. **DBAL migration is critical path** — blocks both apps from removing database-legacy.
4. **Consider a `waaseyaa/workspace` package** only if Minoo also needs workspace concepts (currently it doesn't).

### Application-Level Recommendations

**Claudriel:**
- v1.5.2 Framework Compliance is mechanical — batch into 1-2 PRs
- v1.8 has a clear dependency DAG — execute in topological order starting from #234
- Agent tools (#250, #252-#257) should wait until workspace infrastructure is solid

**Minoo:**
- Assign the 8 orphan issues before starting new work
- Close the empty GraphQL API milestone
- Page Enrichment is 88% done — finish it first

---

## F) Clarifying Questions

1. **Minoo milestone naming:** Should Minoo's named milestones (e.g., "Page Enrichment & Polishing") be renamed to versioned format (e.g., "v0.9 — Page Enrichment") for consistency?

2. **Claudriel v1.6/v1.7:** Should these empty milestones be closed until there's a concrete plan, or kept as roadmap placeholders?

3. **Claudriel #226 vs Waaseyaa v1.4:** Should #226 be closed in favor of a cross-repo dependency on waaseyaa v1.4 completing? Or kept as the Claudriel-side tracking issue?

4. **Shared labels:** Do you want me to create a consistent label taxonomy across all three repos?

5. **Minoo orphan issues:** Where should the 8 unassigned Minoo issues go? New stabilization milestone, or distributed into existing ones?

6. **Waaseyaa v1.4 timing:** The DBAL migration (17 issues) is the biggest blocker. Should it precede Claudriel v1.8, or can they run in parallel if v1.8 doesn't touch database internals?

7. **Cross-repo milestone for DBAL:** Should waaseyaa v1.4 have companion milestones in Minoo and Claudriel for their respective database-legacy removal work?

8. **Minoo GraphQL API milestone:** Close it, or keep it open for when waaseyaa v1.3 completes?
