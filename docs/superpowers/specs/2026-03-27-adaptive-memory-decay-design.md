# Adaptive Memory Decay - Completion Design

**Issue:** #293
**Milestone:** v2.2 - Memory and Graph Improvements
**Date:** 2026-03-27

## Context

The scaffolding for adaptive memory decay already exists:
- `importance_score`, `access_count`, `last_accessed_at` fields on Person, Commitment, McEvent
- `DecayCommand` applies exponential decay daily (rate * score, capped at min_threshold)
- `MemoryAccessEvent` entity records when chat tools reference entities
- Field definitions in providers expose these fields for GraphQL

What's missing is the wiring: access events don't update entity importance, and the day brief doesn't use importance for ranking.

## Components

### 1. RehearsalService

**File:** `src/Domain/Memory/RehearsalService.php`

New service that applies the rehearsal boost when an entity is accessed.

```php
final class RehearsalService
{
    private const float REHEARSAL_BOOST = 0.05;
    private const float MAX_SCORE = 1.0;

    public function __construct(
        private readonly array $repositories, // ['person' => repo, 'commitment' => repo, 'mc_event' => repo]
    ) {}

    public function recordAccess(string $entityType, string $entityUuid): void
    {
        // 1. Look up repo for entityType; silently return if unknown type
        // 2. Find entity by uuid
        // 3. Increment access_count
        // 4. Set last_accessed_at to now
        // 5. Apply rehearsal boost: importance_score = min(MAX_SCORE, score + REHEARSAL_BOOST)
        // 6. Save entity
    }
}
```

**Behavior:**
- Unknown entity types are silently ignored (MemoryAccessEvents can reference non-decayable types)
- Entity not found by UUID is silently ignored (entity may have been deleted)
- Rehearsal boost is +0.05 per access, capped at 1.0
- `access_count` is incremented by 1
- `last_accessed_at` is set to current ISO 8601 timestamp

### 2. ChatStreamController Integration

**File:** `src/Controller/ChatStreamController.php`

In `recordMemoryAccessEvents()`, after saving each `MemoryAccessEvent`, call `RehearsalService::recordAccess()` for events with non-null `entity_type` and `entity_uuid`.

**Changes:**
- Add `RehearsalService` as a constructor dependency
- After the `$storage->save($event)` call in the foreach loop, call `$this->rehearsalService->recordAccess($ref['entity_type'], $ref['entity_uuid'])` when both values are non-null

### 3. MemoryServiceProvider Wiring

**File:** `src/Provider/MemoryServiceProvider.php`

Register `RehearsalService` as a singleton in the service container with the three decayable entity repos injected.

### 4. DayBriefAssembler Importance Ranking

**File:** `src/Domain/DayBrief/Assembler/DayBriefAssembler.php`

Sort commitment and people arrays by `importance_score` descending:

- **Pending commitments** (line ~144): after filtering, sort by importance_score desc
- **Drifting commitments** (line ~145): sort result by importance_score desc
- **Waiting_on** (line ~149): sort by importance_score desc
- **People** (line ~531): use importance_score as primary sort, last_interaction_at as tiebreaker

**Not included in this phase:** Filtering entities below `min_importance_threshold` from briefs. Ranking low is better than hiding. Filtering can be added in a follow-up if briefs become noisy.

### 5. GraphQL Verification

The `importance_score`, `access_count`, and `last_accessed_at` fields are already in `fieldDefinitions` for all three entity types. Waaseyaa auto-generates GraphQL schema from these. Verify during implementation; add schema contract test if not already covered.

## Test Plan

### RehearsalServiceTest (`tests/Unit/Domain/Memory/RehearsalServiceTest.php`)
- Access on known entity type increments access_count and sets last_accessed_at
- Access on known entity type applies +0.05 rehearsal boost, capped at 1.0
- Access on unknown entity type is silently ignored
- Access on non-existent UUID is silently ignored
- Multiple accesses compound correctly (0.5 + 0.05 + 0.05 = 0.6)

### DayBriefAssembler ranking tests
- Commitments with higher importance_score appear first in pending array
- People sorted by importance_score desc, then last_interaction_at desc

### Existing test verification
- `DecayCommandTest` continues to pass (decay is independent of rehearsal)
- `ChatStreamControllerTest` updated for RehearsalService dependency

## Acceptance Criteria Mapping

| Criteria (from issue) | Status |
|----------------------|--------|
| importance_score, access_count, last_accessed_at fields | Already exists |
| Account settings: decay_rate_daily, min_importance_threshold | Already exists |
| CLI: claudriel:decay with --tenant, --dry-run, --verbose | Already exists |
| DayBriefAssembler uses importance_score in ranking | This design |
| GraphQL: importanceScore field exposed | Verify (likely already works) |
| Decay is idempotent | Already implemented |
| Rehearsal boost on access (+0.05, capped at 1.0) | This design |
| Entities below threshold excluded from briefs | Deferred (rank low instead) |
| 10k entities in < 30s | Already met by DecayCommand |
| Tenant-isolated | Already implemented |

## Also Closes

- **#302** (Decay schema migration + CLI command): Already fully implemented, just not closed.
