# Day Brief Specification

## File Map

| File | Purpose |
|------|---------|
| `src/DayBrief/DayBriefAssembler.php` | Assembles the daily brief data structure |
| `src/DriftDetector.php` | Finds active commitments with no recent activity |
| `src/Controller/DayBriefController.php` | `GET /brief` → JSON response |
| `src/Command/BriefCommand.php` | `claudriel:brief` CLI command |
| `templates/day-brief.html.twig` | Twig template (currently unused by controller — controller returns JSON) |

## Interface Signatures

```php
// DayBriefAssembler
public function assemble(string $tenantId, \DateTimeImmutable $since): array
// Returns: array{
//   recent_events: ContentEntityInterface[],
//   pending_commitments: ContentEntityInterface[],
//   drifting_commitments: ContentEntityInterface[]
// }

// DriftDetector
public function findDrifting(string $tenantId): ContentEntityInterface[]
// Returns active commitments where updated_at < (now - 48h)
```

## Data Flow

```
DayBriefAssembler::assemble($tenantId, $since)
  ├── eventRepo->findBy(['tenant_id' => $tenantId])
  │   filter: occurred >= $since
  │   → recent_events[]
  │
  ├── commitmentRepo->findBy(['status' => 'pending', 'tenant_id' => $tenantId])
  │   → pending_commitments[]
  │
  └── DriftDetector::findDrifting($tenantId)
      commitmentRepo->findBy(['status' => 'active', 'tenant_id' => $tenantId])
      filter: updated_at < (now - 48h)
      → drifting_commitments[]
```

## DriftDetector Logic

```php
const DRIFT_HOURS = 48;
$cutoff = new \DateTimeImmutable('-48 hours');
// Only checks status='active' — pending commitments are NOT drift-checked
// updated_at field must exist; falls back to 'now' if missing (never drifts)
```

## DayBriefController

`GET /brief` — registered as route `claudriel.brief` with `->allowAll()->methods('GET')`.

Returns JSON:
```json
{
  "recent_events": [...],
  "pending_commitments": [...],
  "drifting_commitments": [...]
}
```

Note: the Twig `day-brief.html.twig` template exists but the controller currently returns JSON directly, not rendered HTML.

## Dependencies (Constructor Injection)

```php
DayBriefAssembler(
    EntityRepositoryInterface $eventRepo,      // mc_event
    EntityRepositoryInterface $commitmentRepo, // commitment
    DriftDetector $driftDetector
)

DriftDetector(
    EntityRepositoryInterface $repo  // commitment
)
```

## Testing Pattern

Use `InMemoryStorageDriver` for both repos. Create commitments with explicit `updated_at` values to test drift detection:

```php
// Drifting: updated 3 days ago, status='active'
new Commitment(['status' => 'active', 'updated_at' => (new \DateTimeImmutable('-3 days'))->format('Y-m-d H:i:s'), 'tenant_id' => 'user-1'])
// Not drifting: updated 1 hour ago
new Commitment(['status' => 'active', 'updated_at' => (new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), 'tenant_id' => 'user-1'])
```
