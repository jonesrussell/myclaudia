# Memory Domain Spec

## File Map

| File | Purpose |
|------|---------|
| `src/Domain/Memory/RehearsalService.php` | Importance scoring via spaced-repetition access tracking |
| `src/Domain/Memory/DuplicateDetector.php` | Person entity deduplication via multi-field scoring |
| `src/Entity/MemoryAccessEvent.php` | Audit trail for entity access events |
| `src/Entity/MergeCandidate.php` | Duplicate pair detection results |
| `src/Entity/MergeAuditLog.php` | Merge operation history |
| `src/Provider/MemoryServiceProvider.php` | DI wiring, entity type registration |
| `tests/Unit/Domain/Memory/RehearsalServiceTest.php` | 5 tests: access recording, boost capping, edge cases |
| `tests/Unit/Domain/Memory/DuplicateDetectorTest.php` | 3 tests: email, name, threshold matching |

## Interface / API

### RehearsalService

Injected as singleton. Called during chat message handling (`ChatStreamController`, `ChatServiceProvider`).

- `recordAccess(string $entityType, string $uuid)`: Increments `access_count`, updates `last_accessed_at` (ISO 8601), applies +0.05 importance boost (capped at 1.0). Silently ignores unknown entity types or missing UUIDs.
- Operates on repositories: `person`, `commitment`, `mc_event`

### DuplicateDetector

Instantiated directly in `ConsolidateCommand` (CLI consolidation workflow).

- `detect(array $persons, float $threshold = 0.8): array` — Returns candidates with: `source_uuid`, `target_uuid`, `similarity_score` (float), `match_reasons` (string[])

Scoring algorithms:
- Exact email match: 1.0
- Normalized name match: 0.9
- Phone number match: 0.95
- Levenshtein name distance <= 2 + same email domain + both names present: 0.8

## Data Flow

```
Chat message → ChatStreamController → RehearsalService::recordAccess()
  → load entity by UUID → increment access_count → boost importance_score → save

CLI consolidate → DuplicateDetector::detect(persons)
  → pairwise comparison → score each pair → filter by threshold
  → MergeCandidate entities (status: pending) → MergeAuditLog on merge
```

## Entity Types

| Entity | ID Key | Notable Fields |
|--------|--------|----------------|
| `memory_access_event` | maeid | entity_type, entity_uuid, tool_name, accessed_at, metadata (text_long), tenant_id |
| `merge_candidate` | mcid | source/target entity type+uuid, similarity_score (float), match_reasons (text_long), status (default: pending), tenant_id |
| `merge_audit_log` | maid | merge_candidate_uuid, action, source/target/result_snapshot (text_long), tenant_id |

All entity types registered with field definitions in `MemoryServiceProvider`.

## Config Vars

No environment variables. Hardcoded defaults:
- DuplicateDetector threshold: 0.8
- RehearsalService boost increment: 0.05
- Max importance score: 1.0

## Known Constraints

- RehearsalService silently fails on invalid entity types/UUIDs (no exceptions thrown)
- DuplicateDetector phone normalization strips all non-digits (`preg_replace('/\D+/', '')`)
- Name normalization: lowercase + non-alphanumeric replaced with space, then trim
- Email normalization: lowercase + trim only
- Synchronous repository updates (no async/queue support)
- Multi-tenant: all entities track `tenant_id`
