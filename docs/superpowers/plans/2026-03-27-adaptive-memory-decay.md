# Adaptive Memory Decay Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Wire up the rehearsal boost (access tracking updates importance_score) and importance-based ranking in the day brief, completing #293.

**Architecture:** A new `RehearsalService` handles entity importance updates on access. `ChatStreamController` calls it after recording `MemoryAccessEvent`s. `DayBriefAssembler` sorts results by `importance_score`. All wiring goes through `MemoryServiceProvider` and `ChatServiceProvider`.

**Tech Stack:** PHP 8.4, Waaseyaa entity system, PHPUnit

---

### Task 1: RehearsalService — Test

**Files:**
- Create: `tests/Unit/Domain/Memory/RehearsalServiceTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Memory;

use Claudriel\Domain\Memory\RehearsalService;
use Claudriel\Entity\Person;
use Claudriel\Entity\Commitment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class RehearsalServiceTest extends TestCase
{
    private EntityRepository $personRepo;
    private EntityRepository $commitmentRepo;
    private RehearsalService $service;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher;

        $this->personRepo = new EntityRepository(
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
        $this->commitmentRepo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $this->service = new RehearsalService([
            'person' => $this->personRepo,
            'commitment' => $this->commitmentRepo,
        ]);
    }

    public function test_access_increments_count_and_sets_timestamp(): void
    {
        $person = new Person(['pid' => 1, 'uuid' => 'p-1', 'name' => 'Alice', 'importance_score' => 0.5, 'access_count' => 2]);
        $this->personRepo->save($person);

        $this->service->recordAccess('person', 'p-1');

        $updated = $this->personRepo->findBy(['uuid' => 'p-1']);
        self::assertCount(1, $updated);
        self::assertSame(3, $updated[0]->get('access_count'));
        self::assertNotNull($updated[0]->get('last_accessed_at'));
    }

    public function test_access_applies_rehearsal_boost_capped_at_one(): void
    {
        $person = new Person(['pid' => 1, 'uuid' => 'p-1', 'name' => 'Bob', 'importance_score' => 0.5]);
        $this->personRepo->save($person);

        $this->service->recordAccess('person', 'p-1');

        $updated = $this->personRepo->findBy(['uuid' => 'p-1']);
        self::assertEqualsWithDelta(0.55, $updated[0]->get('importance_score'), 0.001);
    }

    public function test_boost_caps_at_one(): void
    {
        $person = new Person(['pid' => 1, 'uuid' => 'p-1', 'name' => 'Cap', 'importance_score' => 0.98]);
        $this->personRepo->save($person);

        $this->service->recordAccess('person', 'p-1');

        $updated = $this->personRepo->findBy(['uuid' => 'p-1']);
        self::assertEqualsWithDelta(1.0, $updated[0]->get('importance_score'), 0.001);
    }

    public function test_unknown_entity_type_is_silently_ignored(): void
    {
        $this->service->recordAccess('unknown_type', 'some-uuid');

        // No exception thrown
        self::assertTrue(true);
    }

    public function test_nonexistent_uuid_is_silently_ignored(): void
    {
        $this->service->recordAccess('person', 'nonexistent-uuid');

        // No exception thrown
        self::assertTrue(true);
    }

    public function test_multiple_accesses_compound(): void
    {
        $person = new Person(['pid' => 1, 'uuid' => 'p-1', 'name' => 'Multi', 'importance_score' => 0.5, 'access_count' => 0]);
        $this->personRepo->save($person);

        $this->service->recordAccess('person', 'p-1');
        $this->service->recordAccess('person', 'p-1');

        $updated = $this->personRepo->findBy(['uuid' => 'p-1']);
        self::assertEqualsWithDelta(0.60, $updated[0]->get('importance_score'), 0.001);
        self::assertSame(2, $updated[0]->get('access_count'));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Domain/Memory/RehearsalServiceTest.php`
Expected: FAIL — `RehearsalService` class not found

- [ ] **Step 3: Commit test file**

```bash
git add tests/Unit/Domain/Memory/RehearsalServiceTest.php
git commit -m "test(#293): add RehearsalService unit tests (red)"
```

---

### Task 2: RehearsalService — Implementation

**Files:**
- Create: `src/Domain/Memory/RehearsalService.php`

- [ ] **Step 1: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain\Memory;

use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class RehearsalService
{
    private const float REHEARSAL_BOOST = 0.05;
    private const float MAX_SCORE = 1.0;

    /**
     * @param array<string, EntityRepositoryInterface> $repositories
     */
    public function __construct(
        private readonly array $repositories,
    ) {}

    public function recordAccess(string $entityType, string $entityUuid): void
    {
        $repo = $this->repositories[$entityType] ?? null;
        if ($repo === null) {
            return;
        }

        $entities = $repo->findBy(['uuid' => $entityUuid]);
        if ($entities === []) {
            return;
        }

        $entity = $entities[0];
        assert($entity instanceof ContentEntityInterface);

        $accessCount = $entity->get('access_count');
        $entity->set('access_count', (is_int($accessCount) ? $accessCount : 0) + 1);
        $entity->set('last_accessed_at', (new \DateTimeImmutable)->format('c'));

        $currentScore = $entity->get('importance_score');
        $score = is_numeric($currentScore) ? (float) $currentScore : 1.0;
        $entity->set('importance_score', min(self::MAX_SCORE, $score + self::REHEARSAL_BOOST));

        $repo->save($entity);
    }
}
```

- [ ] **Step 2: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Domain/Memory/RehearsalServiceTest.php`
Expected: All 6 tests PASS

- [ ] **Step 3: Commit**

```bash
git add src/Domain/Memory/RehearsalService.php
git commit -m "feat(#293): implement RehearsalService for access-based importance boost"
```

---

### Task 3: Wire RehearsalService into MemoryServiceProvider

**Files:**
- Modify: `src/Provider/MemoryServiceProvider.php`

- [ ] **Step 1: Add service registration to MemoryServiceProvider**

Add a `boot()` method that registers `RehearsalService` as a singleton. The three decayable entity repos come from the entity type manager.

Add import at top of file:
```php
use Claudriel\Domain\Memory\RehearsalService;
use Waaseyaa\Entity\EntityTypeManager;
```

Add after the `register()` method:
```php
public function boot(): void
{
    $resolver = $this->getServiceResolver();
    if ($resolver === null) {
        return;
    }

    $resolver->singleton(RehearsalService::class, function () use ($resolver): RehearsalService {
        /** @var EntityTypeManager $etm */
        $etm = $resolver->get(EntityTypeManager::class);
        $personRepo = new \Claudriel\Support\StorageRepositoryAdapter(
            new \Waaseyaa\EntityStorage\SqlEntityStorage($etm->getDefinition('person'), $resolver->get(\Waaseyaa\Database\DatabaseInterface::class), new \Symfony\Component\EventDispatcher\EventDispatcher),
        );
        $commitmentRepo = new \Claudriel\Support\StorageRepositoryAdapter(
            new \Waaseyaa\EntityStorage\SqlEntityStorage($etm->getDefinition('commitment'), $resolver->get(\Waaseyaa\Database\DatabaseInterface::class), new \Symfony\Component\EventDispatcher\EventDispatcher),
        );
        $eventRepo = new \Claudriel\Support\StorageRepositoryAdapter(
            new \Waaseyaa\EntityStorage\SqlEntityStorage($etm->getDefinition('mc_event'), $resolver->get(\Waaseyaa\Database\DatabaseInterface::class), new \Symfony\Component\EventDispatcher\EventDispatcher),
        );

        return new RehearsalService([
            'person' => $personRepo,
            'commitment' => $commitmentRepo,
            'mc_event' => $eventRepo,
        ]);
    });
}
```

- [ ] **Step 2: Run full test suite to verify no regressions**

Run: `vendor/bin/phpunit tests/Unit/Domain/Memory/`
Expected: All tests pass

- [ ] **Step 3: Commit**

```bash
git add src/Provider/MemoryServiceProvider.php
git commit -m "feat(#293): wire RehearsalService in MemoryServiceProvider"
```

---

### Task 4: Integrate RehearsalService into ChatStreamController

**Files:**
- Modify: `src/Controller/ChatStreamController.php`
- Modify: `src/Provider/ChatServiceProvider.php`

- [ ] **Step 1: Add RehearsalService to ChatStreamController constructor**

Add import at top of `ChatStreamController.php`:
```php
use Claudriel\Domain\Memory\RehearsalService;
```

Change the constructor from:
```php
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $agentClientFactory = null,
        private readonly ?IssueOrchestrator $orchestrator = null,
    ) {}
```

To:
```php
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $agentClientFactory = null,
        private readonly ?IssueOrchestrator $orchestrator = null,
        private readonly ?RehearsalService $rehearsalService = null,
    ) {}
```

- [ ] **Step 2: Call RehearsalService in recordMemoryAccessEvents()**

In `recordMemoryAccessEvents()`, after the `$storage->save($event)` line (line 397), add the rehearsal call:

Change:
```php
            $storage->save($event);
        }
```

To:
```php
            $storage->save($event);

            if ($this->rehearsalService !== null && is_string($ref['entity_type']) && is_string($ref['entity_uuid'])) {
                $this->rehearsalService->recordAccess($ref['entity_type'], $ref['entity_uuid']);
            }
        }
```

- [ ] **Step 3: Register ChatStreamController as singleton in ChatServiceProvider**

This ensures the framework injects `RehearsalService` instead of trying to resolve the constructor by reflection (per CLAUDE.md gotcha about ambiguous constructor types).

In `ChatServiceProvider`, add to the `boot()` or `register()` method (wherever other singletons are registered). Add imports (if not already present):

```php
use Claudriel\Domain\Chat\IssueOrchestrator;  // may already be imported
use Claudriel\Domain\Memory\RehearsalService;
```

Add singleton registration in the routes method (or a boot method if one exists), after the `$resolver` is available:

```php
$resolver->singleton(ChatStreamController::class, function () use ($resolver): ChatStreamController {
    return new ChatStreamController(
        $resolver->get(EntityTypeManager::class),
        null,
        $resolver->has(IssueOrchestrator::class) ? $resolver->get(IssueOrchestrator::class) : null,
        $resolver->has(RehearsalService::class) ? $resolver->get(RehearsalService::class) : null,
    );
});
```

- [ ] **Step 4: Run existing ChatStreamController tests**

Run: `vendor/bin/phpunit tests/Unit/Controller/`
Expected: All pass (RehearsalService is nullable, so existing tests constructing ChatStreamController without it still work)

- [ ] **Step 5: Commit**

```bash
git add src/Controller/ChatStreamController.php src/Provider/ChatServiceProvider.php
git commit -m "feat(#293): wire RehearsalService into ChatStreamController"
```

---

### Task 5: DayBriefAssembler importance ranking — Test

**Files:**
- Modify: `tests/Unit/DayBrief/DayBriefAssemblerTest.php`

- [ ] **Step 1: Add importance ranking tests**

Add these test methods to `DayBriefAssemblerTest`:

```php
public function test_pending_commitments_sorted_by_importance_score_descending(): void
{
    $low = new Commitment(['cid' => 1, 'uuid' => 'c-low', 'title' => 'Low', 'workflow_state' => 'pending', 'status' => 'pending', 'tenant_id' => 'test-tenant', 'importance_score' => 0.3]);
    $high = new Commitment(['cid' => 2, 'uuid' => 'c-high', 'title' => 'High', 'workflow_state' => 'pending', 'status' => 'pending', 'tenant_id' => 'test-tenant', 'importance_score' => 0.9]);
    $mid = new Commitment(['cid' => 3, 'uuid' => 'c-mid', 'title' => 'Mid', 'workflow_state' => 'pending', 'status' => 'pending', 'tenant_id' => 'test-tenant', 'importance_score' => 0.6]);

    $this->commitmentRepo->save($low);
    $this->commitmentRepo->save($high);
    $this->commitmentRepo->save($mid);

    $result = $this->assembler->assemble('test-tenant', new \DateTimeImmutable('-7 days'));

    $pending = $result['commitments']['pending'];
    self::assertCount(3, $pending);
    self::assertSame('c-high', $pending[0]->get('uuid'));
    self::assertSame('c-mid', $pending[1]->get('uuid'));
    self::assertSame('c-low', $pending[2]->get('uuid'));
}

public function test_people_sorted_by_importance_score_descending(): void
{
    $now = new \DateTimeImmutable;
    $lowImportance = new Person([
        'pid' => 1, 'uuid' => 'p-low', 'name' => 'Low',
        'email' => 'low@test.com', 'tenant_id' => 'test-tenant',
        'last_inbox_category' => 'people',
        'last_interaction_at' => $now->format('c'),
        'latest_summary' => 'Test',
        'importance_score' => 0.2,
    ]);
    $highImportance = new Person([
        'pid' => 2, 'uuid' => 'p-high', 'name' => 'High',
        'email' => 'high@test.com', 'tenant_id' => 'test-tenant',
        'last_inbox_category' => 'people',
        'last_interaction_at' => $now->format('c'),
        'latest_summary' => 'Test',
        'importance_score' => 0.9,
    ]);

    $this->personRepo->save($lowImportance);
    $this->personRepo->save($highImportance);

    $result = $this->assembler->assemble('test-tenant', new \DateTimeImmutable('-7 days'));

    $people = $result['people'];
    self::assertCount(2, $people);
    self::assertSame('High', $people[0]['person_name']);
    self::assertSame('Low', $people[1]['person_name']);
}
```

- [ ] **Step 2: Run to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/DayBrief/DayBriefAssemblerTest.php --filter="test_pending_commitments_sorted|test_people_sorted"`
Expected: FAIL — commitments/people not sorted by importance

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/DayBrief/DayBriefAssemblerTest.php
git commit -m "test(#293): add importance ranking tests for DayBriefAssembler (red)"
```

---

### Task 6: DayBriefAssembler importance ranking — Implementation

**Files:**
- Modify: `src/Domain/DayBrief/Assembler/DayBriefAssembler.php`

- [ ] **Step 1: Add importance sorting helper method**

Add this private method to `DayBriefAssembler`:

```php
/**
 * @param ContentEntityInterface[] $entities
 * @return ContentEntityInterface[]
 */
private function sortByImportanceDesc(array $entities): array
{
    usort($entities, function (ContentEntityInterface $a, ContentEntityInterface $b): int {
        $scoreA = is_numeric($a->get('importance_score')) ? (float) $a->get('importance_score') : 1.0;
        $scoreB = is_numeric($b->get('importance_score')) ? (float) $b->get('importance_score') : 1.0;

        return $scoreB <=> $scoreA;
    });

    return $entities;
}
```

- [ ] **Step 2: Sort pending, drifting, and waiting_on commitments by importance**

In `assemble()`, change lines 141-149 from:

```php
        $pending = array_values(array_filter(
            $allCommitments,
            fn (ContentEntityInterface $c) => $this->entityMatchesTenant($c, $tenantId) && ($c->get('workflow_state') ?? $c->get('status')) === 'pending',
        ));
        $drifting = $this->driftDetector->findDrifting($tenantId);
        $waitingOn = array_values(array_filter(
            $pending,
            static fn (ContentEntityInterface $c) => $c->get('direction') === 'inbound',
        ));
```

To:

```php
        $pending = $this->sortByImportanceDesc(array_values(array_filter(
            $allCommitments,
            fn (ContentEntityInterface $c) => $this->entityMatchesTenant($c, $tenantId) && ($c->get('workflow_state') ?? $c->get('status')) === 'pending',
        )));
        $drifting = $this->sortByImportanceDesc($this->driftDetector->findDrifting($tenantId));
        $waitingOn = $this->sortByImportanceDesc(array_values(array_filter(
            $pending,
            static fn (ContentEntityInterface $c) => $c->get('direction') === 'inbound',
        )));
```

- [ ] **Step 3: Sort people by importance_score (primary), last_interaction_at (secondary)**

In `buildNormalizedPeople()`, change the usort at line 531 from:

```php
        usort($people, fn ($a, $b): int => ((string) $this->getEntityValue($b, 'last_interaction_at')) <=> ((string) $this->getEntityValue($a, 'last_interaction_at')));
```

To:

```php
        usort($people, function ($a, $b): int {
            $scoreA = is_numeric($this->getEntityValue($a, 'importance_score')) ? (float) $this->getEntityValue($a, 'importance_score') : 1.0;
            $scoreB = is_numeric($this->getEntityValue($b, 'importance_score')) ? (float) $this->getEntityValue($b, 'importance_score') : 1.0;
            if ($scoreB !== $scoreA) {
                return $scoreB <=> $scoreA;
            }

            return ((string) $this->getEntityValue($b, 'last_interaction_at')) <=> ((string) $this->getEntityValue($a, 'last_interaction_at'));
        });
```

- [ ] **Step 4: Run the new tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/DayBrief/DayBriefAssemblerTest.php --filter="test_pending_commitments_sorted|test_people_sorted"`
Expected: PASS

- [ ] **Step 5: Run full DayBrief test suite for regressions**

Run: `vendor/bin/phpunit tests/Unit/DayBrief/`
Expected: All tests PASS

- [ ] **Step 6: Commit**

```bash
git add src/Domain/DayBrief/Assembler/DayBriefAssembler.php
git commit -m "feat(#293): sort brief commitments and people by importance_score"
```

---

### Task 7: GraphQL verification

**Files:**
- No changes expected; verification only

- [ ] **Step 1: Verify importance fields are in field definitions**

Run: `grep -n 'importance_score\|access_count\|last_accessed_at' src/Provider/IngestionServiceProvider.php src/Provider/CommitmentServiceProvider.php`

Expected: All three fields present in field definitions for person, commitment, and mc_event entity types.

- [ ] **Step 2: Run full test suite**

Run: `vendor/bin/phpunit`
Expected: All tests PASS

- [ ] **Step 3: Commit (only if any test fixes were needed)**

If no changes needed, skip this step.

---

### Task 8: Close issues

- [ ] **Step 1: Close #302 (already implemented)**

```bash
gh issue close 302 --repo jonesrussell/claudriel --comment "Decay schema + CLI was implemented in fa89863. Fields exist, DecayCommand works. Closing."
```

- [ ] **Step 2: Create feature branch and PR for #293**

```bash
git checkout -b feat/293-adaptive-memory-decay
git push -u origin feat/293-adaptive-memory-decay
gh pr create --title "feat(#293): complete adaptive memory decay wiring" --body "$(cat <<'EOF'
## Summary
- Adds `RehearsalService` that boosts entity importance on access (+0.05, capped at 1.0)
- Wires rehearsal into `ChatStreamController` so chat tool lookups update `access_count`, `last_accessed_at`, and `importance_score`
- Sorts day brief commitments and people by `importance_score` descending

Closes #293, closes #302

## Test plan
- [ ] RehearsalServiceTest: 6 unit tests covering boost, cap, unknown type, missing UUID, compounding
- [ ] DayBriefAssemblerTest: 2 new tests for importance-based sorting
- [ ] Full test suite passes
- [ ] Verify GraphQL exposes importanceScore fields

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```
