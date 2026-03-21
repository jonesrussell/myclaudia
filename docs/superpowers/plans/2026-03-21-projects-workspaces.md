# Projects & Workspaces Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement Claudriel's Project/Workspace/Repo entity model with explicit junction entities, cascade delete, access policies, CRUD API routes, and read-only GraphQL.

**Architecture:** Existing Project and Workspace entities are refactored (fields removed, no JSON blobs). New Repo entity and three junction entities (ProjectRepo, WorkspaceProject, WorkspaceRepo) are added. A single JunctionCascadeSubscriber handles post-delete cleanup. REST API provides full CRUD; GraphQL is read-only with junction-resolved relationships.

**Tech Stack:** PHP 8.4, Waaseyaa entity system (ContentEntityBase, EntityType, SqlEntityStorage, SqlSchemaHandler), Symfony EventDispatcher, DBALDatabase (Doctrine DBAL), PHPUnit 10.5.

**Spec:** `docs/superpowers/specs/2026-03-21-projects-workspaces-design.md`

---

## File Structure

### Entities (create/modify)

| File | Responsibility |
|------|---------------|
| `src/Entity/Project.php` | **Modify** — remove `metadata`, `settings`, `context` defaults |
| `src/Entity/Workspace.php` | **Modify** — remove repo/project fields, add `saved_context` |
| `src/Entity/Repo.php` | **Create** — GitHub repository entity |
| `src/Entity/ProjectRepo.php` | **Create** — junction: project ↔ repo |
| `src/Entity/WorkspaceProject.php` | **Create** — junction: workspace ↔ project |
| `src/Entity/WorkspaceRepo.php` | **Create** — junction: workspace ↔ repo |

### Service Providers (modify)

| File | Responsibility |
|------|---------------|
| `src/Provider/ProjectServiceProvider.php` | **Modify** — update field definitions, register ProjectRepo junction |
| `src/Provider/WorkspaceServiceProvider.php` | **Modify** — update field definitions, register WorkspaceProject + WorkspaceRepo junctions |
| `src/Provider/RepoServiceProvider.php` | **Create** — register Repo entity type |

### Access Policies (create/modify)

| File | Responsibility |
|------|---------------|
| `src/Access/ProjectAccessPolicy.php` | **Create** — owner CRUD, tenant read-only |
| `src/Access/WorkspaceAccessPolicy.php` | **Modify** — owner CRUD, no tenant access |
| `src/Access/RepoAccessPolicy.php` | **Create** — owner CRUD, tenant read-only |

### Event Subscriber (create)

| File | Responsibility |
|------|---------------|
| `src/Subscriber/JunctionCascadeSubscriber.php` | **Create** — cascade-delete junction rows on parent delete |

### Controllers (create/modify)

| File | Responsibility |
|------|---------------|
| `src/Controller/ProjectController.php` | **Create** — CRUD + junction ops (link/unlink repos) |
| `src/Controller/WorkspaceController.php` | **Create** — CRUD + junction ops (link/unlink projects, repos) |
| `src/Controller/RepoController.php` | **Create** — CRUD |

### Routes (modify)

| File | Responsibility |
|------|---------------|
| `src/Provider/ClaudrielServiceProvider.php` | **Modify** — add routes for all new endpoints |

### Tests

| File | Responsibility |
|------|---------------|
| `tests/Feature/Entity/RepoEntityTest.php` | Repo CRUD |
| `tests/Feature/Entity/JunctionEntityTest.php` | Junction CRUD + duplicate prevention |
| `tests/Feature/Subscriber/JunctionCascadeTest.php` | Cascade delete behavior |
| `tests/Feature/Access/ProjectAccessPolicyTest.php` | Project access rules |
| `tests/Feature/Access/WorkspaceAccessPolicyTest.php` | Workspace access rules |
| `tests/Feature/Access/RepoAccessPolicyTest.php` | Repo access rules |

---

## Task 1: Create Repo Entity + Service Provider

**Files:**
- Create: `src/Entity/Repo.php`
- Create: `src/Provider/RepoServiceProvider.php`
- Modify: `composer.json` (add provider to auto-discovery)
- Test: `tests/Feature/Entity/RepoEntityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);

namespace Claudriel\Tests\Feature\Entity;

use Claudriel\Entity\Repo;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

#[CoversClass(Repo::class)]
final class RepoEntityTest extends TestCase
{
    private EntityTypeManager $manager;
    private SqlEntityStorage $repoStorage;

    protected function setUp(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();

        $this->manager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($db, $dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $db))->ensureTable();
                return new SqlEntityStorage($definition, $db, $dispatcher);
            },
        );

        $this->manager->registerEntityType(new EntityType(
            id: 'repo',
            label: 'Repo',
            class: Repo::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'rid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'owner' => ['type' => 'string', 'required' => true],
                'name' => ['type' => 'string', 'required' => true],
                'full_name' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'default_branch' => ['type' => 'string'],
                'local_path' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->repoStorage = $this->manager->getStorage('repo');
    }

    #[Test]
    public function it_creates_a_repo_with_defaults(): void
    {
        $repo = new Repo([
            'owner' => 'jonesrussell',
            'name' => 'waaseyaa',
        ]);

        self::assertSame('jonesrussell', $repo->get('owner'));
        self::assertSame('waaseyaa', $repo->get('name'));
        self::assertSame('jonesrussell/waaseyaa', $repo->get('full_name'));
        self::assertSame('main', $repo->get('default_branch'));
        self::assertNull($repo->get('local_path'));
    }

    #[Test]
    public function it_saves_and_loads_a_repo(): void
    {
        $repo = new Repo([
            'owner' => 'jonesrussell',
            'name' => 'claudriel',
            'url' => 'https://github.com/jonesrussell/claudriel',
            'tenant_id' => 'default',
        ]);
        $repo->enforceIsNew();
        $this->repoStorage->save($repo);

        $loaded = $this->repoStorage->loadMultiple();
        self::assertCount(1, $loaded);
        self::assertSame('jonesrussell', $loaded[0]->get('owner'));
        self::assertSame('claudriel', $loaded[0]->get('name'));
        self::assertSame('jonesrussell/claudriel', $loaded[0]->get('full_name'));
    }

    #[Test]
    public function it_updates_a_repo(): void
    {
        $repo = new Repo([
            'owner' => 'jonesrussell',
            'name' => 'waaseyaa',
            'default_branch' => 'main',
            'tenant_id' => 'default',
        ]);
        $repo->enforceIsNew();
        $this->repoStorage->save($repo);

        $loaded = $this->repoStorage->loadMultiple();
        $loaded[0]->set('default_branch', 'develop');
        $this->repoStorage->save($loaded[0]);

        $reloaded = $this->repoStorage->loadMultiple();
        self::assertSame('develop', $reloaded[0]->get('default_branch'));
    }

    #[Test]
    public function it_deletes_a_repo(): void
    {
        $repo = new Repo([
            'owner' => 'jonesrussell',
            'name' => 'waaseyaa',
            'tenant_id' => 'default',
        ]);
        $repo->enforceIsNew();
        $this->repoStorage->save($repo);

        $loaded = $this->repoStorage->loadMultiple();
        self::assertCount(1, $loaded);

        $this->repoStorage->delete($loaded);
        $remaining = $this->repoStorage->loadMultiple();
        self::assertCount(0, $remaining);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit tests/Feature/Entity/RepoEntityTest.php`
Expected: FAIL — `Claudriel\Entity\Repo` class not found.

- [ ] **Step 3: Create Repo entity class**

Create `src/Entity/Repo.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Repo extends ContentEntityBase
{
    protected string $entityTypeId = 'repo';

    protected array $entityKeys = [
        'id' => 'rid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        // Compute full_name from owner + name before parent constructor
        if (isset($values['owner'], $values['name']) && !isset($values['full_name'])) {
            $values['full_name'] = $values['owner'] . '/' . $values['name'];
        }

        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('default_branch') === null) {
            $this->set('default_branch', 'main');
        }
        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default');
        }
    }
}
```

- [ ] **Step 4: Create RepoServiceProvider**

Create `src/Provider/RepoServiceProvider.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Entity\Repo;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class RepoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'repo',
            label: 'Repo',
            class: Repo::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'rid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'owner' => ['type' => 'string', 'required' => true],
                'name' => ['type' => 'string', 'required' => true],
                'full_name' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'default_branch' => ['type' => 'string'],
                'local_path' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}
```

- [ ] **Step 5: Add provider to composer.json auto-discovery**

Add `"Claudriel\\Provider\\RepoServiceProvider"` to the `extra.waaseyaa.providers` array in `composer.json`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit tests/Feature/Entity/RepoEntityTest.php`
Expected: 4 tests, 4 assertions, all PASS.

- [ ] **Step 7: Commit**

```bash
git add src/Entity/Repo.php src/Provider/RepoServiceProvider.php tests/Feature/Entity/RepoEntityTest.php composer.json
git commit -m "feat(#430): add Repo entity and RepoServiceProvider"
```

---

## Task 2: Create Junction Entities

**Files:**
- Create: `src/Entity/ProjectRepo.php`
- Create: `src/Entity/WorkspaceProject.php`
- Create: `src/Entity/WorkspaceRepo.php`
- Modify: `src/Provider/ProjectServiceProvider.php` — register ProjectRepo
- Modify: `src/Provider/WorkspaceServiceProvider.php` — register WorkspaceProject + WorkspaceRepo
- Test: `tests/Feature/Entity/JunctionEntityTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Entity;

use Claudriel\Entity\ProjectRepo;
use Claudriel\Entity\WorkspaceProject;
use Claudriel\Entity\WorkspaceRepo;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

#[CoversNothing]
final class JunctionEntityTest extends TestCase
{
    private DBALDatabase $db;
    private EntityTypeManager $manager;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();

        $this->manager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $this->db))->ensureTable();
                return new SqlEntityStorage($definition, $this->db, $dispatcher);
            },
        );

        $this->registerJunctionTypes();
    }

    #[Test]
    public function it_creates_a_project_repo_link(): void
    {
        $storage = $this->manager->getStorage('project_repo');

        $link = new ProjectRepo([
            'project_uuid' => 'proj-111',
            'repo_uuid' => 'repo-222',
        ]);
        $link->enforceIsNew();
        $storage->save($link);

        $loaded = $storage->loadMultiple();
        self::assertCount(1, $loaded);
        self::assertSame('proj-111', $loaded[0]->get('project_uuid'));
        self::assertSame('repo-222', $loaded[0]->get('repo_uuid'));
    }

    #[Test]
    public function it_creates_a_workspace_project_link(): void
    {
        $storage = $this->manager->getStorage('workspace_project');

        $link = new WorkspaceProject([
            'workspace_uuid' => 'ws-111',
            'project_uuid' => 'proj-222',
        ]);
        $link->enforceIsNew();
        $storage->save($link);

        $loaded = $storage->loadMultiple();
        self::assertCount(1, $loaded);
        self::assertSame('ws-111', $loaded[0]->get('workspace_uuid'));
        self::assertSame('proj-222', $loaded[0]->get('project_uuid'));
    }

    #[Test]
    public function it_creates_a_workspace_repo_link_with_is_active(): void
    {
        $storage = $this->manager->getStorage('workspace_repo');

        $link = new WorkspaceRepo([
            'workspace_uuid' => 'ws-111',
            'repo_uuid' => 'repo-222',
        ]);
        $link->enforceIsNew();
        $storage->save($link);

        $loaded = $storage->loadMultiple();
        self::assertCount(1, $loaded);
        self::assertTrue((bool) $loaded[0]->get('is_active'));
    }

    #[Test]
    public function it_prevents_duplicate_project_repo_link(): void
    {
        $storage = $this->manager->getStorage('project_repo');

        $link1 = new ProjectRepo([
            'project_uuid' => 'proj-111',
            'repo_uuid' => 'repo-222',
        ]);
        $link1->enforceIsNew();
        $storage->save($link1);

        // Second link with same UUIDs — should be detectable
        $existing = $storage->findBy([
            'project_uuid' => 'proj-111',
            'repo_uuid' => 'repo-222',
        ]);
        self::assertCount(1, $existing, 'Duplicate check should find existing link');
    }

    #[Test]
    public function it_deletes_a_junction_link(): void
    {
        $storage = $this->manager->getStorage('project_repo');

        $link = new ProjectRepo([
            'project_uuid' => 'proj-111',
            'repo_uuid' => 'repo-222',
        ]);
        $link->enforceIsNew();
        $storage->save($link);

        $loaded = $storage->loadMultiple();
        $storage->delete($loaded);

        self::assertCount(0, $storage->loadMultiple());
    }

    private function registerJunctionTypes(): void
    {
        $this->manager->registerEntityType(new EntityType(
            id: 'project_repo',
            label: 'Project Repo',
            class: ProjectRepo::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'project_uuid' => ['type' => 'string', 'required' => true],
                'repo_uuid' => ['type' => 'string', 'required' => true],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'workspace_project',
            label: 'Workspace Project',
            class: WorkspaceProject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'project_uuid' => ['type' => 'string', 'required' => true],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'workspace_repo',
            label: 'Workspace Repo',
            class: WorkspaceRepo::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'repo_uuid' => ['type' => 'string', 'required' => true],
                'is_active' => ['type' => 'boolean'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit tests/Feature/Entity/JunctionEntityTest.php`
Expected: FAIL — junction entity classes not found.

- [ ] **Step 3: Create ProjectRepo entity**

Create `src/Entity/ProjectRepo.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ProjectRepo extends ContentEntityBase
{
    protected string $entityTypeId = 'project_repo';

    protected array $entityKeys = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
```

- [ ] **Step 4: Create WorkspaceProject entity**

Create `src/Entity/WorkspaceProject.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class WorkspaceProject extends ContentEntityBase
{
    protected string $entityTypeId = 'workspace_project';

    protected array $entityKeys = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
```

- [ ] **Step 5: Create WorkspaceRepo entity**

Create `src/Entity/WorkspaceRepo.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class WorkspaceRepo extends ContentEntityBase
{
    protected string $entityTypeId = 'workspace_repo';

    protected array $entityKeys = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('is_active') === null) {
            $this->set('is_active', true);
        }
    }
}
```

- [ ] **Step 6: Register junction types in service providers**

Modify `src/Provider/ProjectServiceProvider.php` — add ProjectRepo entity type registration in `register()`.

Modify `src/Provider/WorkspaceServiceProvider.php` — add WorkspaceProject and WorkspaceRepo entity type registrations in `register()`.

Add field definitions matching the test setup above.

- [ ] **Step 7: Run tests to verify they pass**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit tests/Feature/Entity/JunctionEntityTest.php`
Expected: 5 tests, all PASS.

- [ ] **Step 8: Commit**

```bash
git add src/Entity/ProjectRepo.php src/Entity/WorkspaceProject.php src/Entity/WorkspaceRepo.php \
  src/Provider/ProjectServiceProvider.php src/Provider/WorkspaceServiceProvider.php \
  tests/Feature/Entity/JunctionEntityTest.php
git commit -m "feat(#431): add junction entities (ProjectRepo, WorkspaceProject, WorkspaceRepo)"
```

---

## Task 3: Refactor Existing Project and Workspace Entities

**Files:**
- Modify: `src/Entity/Project.php`
- Modify: `src/Entity/Workspace.php`
- Modify: `src/Provider/ProjectServiceProvider.php`
- Modify: `src/Provider/WorkspaceServiceProvider.php`

- [ ] **Step 1: Update Project entity — remove blob defaults**

In `src/Entity/Project.php`, remove constructor defaults for `metadata`, `settings`, `context`. Keep `name`, `description`, `status`, `account_id`, `tenant_id`.

- [ ] **Step 2: Update Project field definitions**

In `src/Provider/ProjectServiceProvider.php`, remove `metadata`, `settings`, `context` from `fieldDefinitions`. Final field list: `prid`, `uuid`, `name`, `description`, `status`, `account_id`, `tenant_id`, `created_at`, `updated_at`.

- [ ] **Step 3: Update Workspace entity — remove repo/project fields**

In `src/Entity/Workspace.php`, remove constructor defaults for: `repo_path`, `repo_url`, `branch`, `codex_model`, `last_commit_hash`, `ci_status`, `project_id`, `metadata`. Add default for `saved_context` (null). Keep `name`, `description`, `status`, `mode`, `account_id`, `tenant_id`.

- [ ] **Step 4: Update Workspace field definitions**

In `src/Provider/WorkspaceServiceProvider.php`, remove from `fieldDefinitions`: `repo_path`, `repo_url`, `branch`, `codex_model`, `last_commit_hash`, `ci_status`, `project_id`, `metadata`. Add `saved_context` with type `text_long`. Final field list: `wid`, `uuid`, `name`, `description`, `status`, `mode`, `saved_context`, `account_id`, `tenant_id`, `created_at`, `updated_at`.

- [ ] **Step 5: Find and fix references to removed fields**

Before running tests, search the codebase for references to removed fields:

```bash
grep -rn 'repo_path\|repo_url\|codex_model\|last_commit_hash\|ci_status\|project_id' src/ tests/ --include='*.php' | grep -v 'Entity/Repo' | grep -v 'ProjectRepo'
grep -rn "->get('metadata')\|->set('metadata')\|->get('settings')\|->set('settings')\|->get('context')\|->set('context')" src/ tests/ --include='*.php'
```

Known files likely affected (verify before editing):
- `src/Controller/InternalWorkspaceController.php` — references `repo_path`, `repo_url`, `branch`, `project_id`
- `src/Domain/Workspace/WorkspaceLifecycleManager.php` — references `repo_path`, `repo_url`
- `src/Domain/Workspace/WorkspaceDiffProvider.php` — references `repo_path`
- `src/Domain/Project/ProjectContextProvider.php` — may reference `metadata`, `settings`, `context`
- Tests referencing these fields in workspace/project creation

Update each reference: repo fields → use Repo entity via junction; `project_id` → use WorkspaceProject junction; JSON blobs → use explicit fields or remove.

- [ ] **Step 6: Run existing tests**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit`
Expected: All tests pass after field reference updates.

- [ ] **Step 7: Commit**

```bash
git add src/Entity/Project.php src/Entity/Workspace.php \
  src/Provider/ProjectServiceProvider.php src/Provider/WorkspaceServiceProvider.php
git commit -m "refactor(#432): remove obsolete fields from Project and Workspace entities"
```

---

## Task 4: Create JunctionCascadeSubscriber

**Files:**
- Create: `src/Subscriber/JunctionCascadeSubscriber.php`
- Modify: `src/Provider/WorkspaceServiceProvider.php` — register subscriber in `boot()`
- Test: `tests/Feature/Subscriber/JunctionCascadeTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Feature\Subscriber;

use Claudriel\Entity\Project;
use Claudriel\Entity\ProjectRepo;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceProject;
use Claudriel\Entity\WorkspaceRepo;
use Claudriel\Subscriber\JunctionCascadeSubscriber;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

#[CoversClass(JunctionCascadeSubscriber::class)]
final class JunctionCascadeTest extends TestCase
{
    private DBALDatabase $db;
    private EntityTypeManager $manager;

    protected function setUp(): void
    {
        $this->db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();

        // Register cascade subscriber
        $subscriber = new JunctionCascadeSubscriber($this->db);
        $dispatcher->addSubscriber($subscriber);

        $this->manager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $this->db))->ensureTable();
                return new SqlEntityStorage($definition, $this->db, $dispatcher);
            },
        );

        $this->registerAllTypes();
    }

    #[Test]
    public function deleting_project_removes_project_repo_and_workspace_project_junctions(): void
    {
        $projectStorage = $this->manager->getStorage('project');
        $prStorage = $this->manager->getStorage('project_repo');
        $wpStorage = $this->manager->getStorage('workspace_project');

        // Create project
        $project = new Project(['name' => 'Test Project', 'tenant_id' => 'default']);
        $project->enforceIsNew();
        $projectStorage->save($project);
        $projectUuid = $projectStorage->loadMultiple()[0]->get('uuid');

        // Create junction rows
        $pr = new ProjectRepo(['project_uuid' => $projectUuid, 'repo_uuid' => 'repo-1']);
        $pr->enforceIsNew();
        $prStorage->save($pr);

        $wp = new WorkspaceProject(['workspace_uuid' => 'ws-1', 'project_uuid' => $projectUuid]);
        $wp->enforceIsNew();
        $wpStorage->save($wp);

        // Delete project
        $loaded = $projectStorage->loadMultiple();
        $projectStorage->delete($loaded);

        // Junctions should be gone
        self::assertCount(0, $prStorage->loadMultiple());
        self::assertCount(0, $wpStorage->loadMultiple());
    }

    #[Test]
    public function deleting_repo_removes_project_repo_and_workspace_repo_junctions(): void
    {
        $repoStorage = $this->manager->getStorage('repo');
        $prStorage = $this->manager->getStorage('project_repo');
        $wrStorage = $this->manager->getStorage('workspace_repo');

        // Create repo
        $repo = new Repo(['owner' => 'jonesrussell', 'name' => 'waaseyaa', 'tenant_id' => 'default']);
        $repo->enforceIsNew();
        $repoStorage->save($repo);
        $repoUuid = $repoStorage->loadMultiple()[0]->get('uuid');

        // Create junction rows
        $pr = new ProjectRepo(['project_uuid' => 'proj-1', 'repo_uuid' => $repoUuid]);
        $pr->enforceIsNew();
        $prStorage->save($pr);

        $wr = new WorkspaceRepo(['workspace_uuid' => 'ws-1', 'repo_uuid' => $repoUuid]);
        $wr->enforceIsNew();
        $wrStorage->save($wr);

        // Delete repo
        $loaded = $repoStorage->loadMultiple();
        $repoStorage->delete($loaded);

        // Junctions should be gone
        self::assertCount(0, $prStorage->loadMultiple());
        self::assertCount(0, $wrStorage->loadMultiple());
    }

    #[Test]
    public function deleting_workspace_removes_workspace_project_and_workspace_repo_junctions(): void
    {
        $wsStorage = $this->manager->getStorage('workspace');
        $wpStorage = $this->manager->getStorage('workspace_project');
        $wrStorage = $this->manager->getStorage('workspace_repo');

        // Create workspace
        $ws = new Workspace(['name' => 'Test Workspace', 'tenant_id' => 'default']);
        $ws->enforceIsNew();
        $wsStorage->save($ws);
        $wsUuid = $wsStorage->loadMultiple()[0]->get('uuid');

        // Create junction rows
        $wp = new WorkspaceProject(['workspace_uuid' => $wsUuid, 'project_uuid' => 'proj-1']);
        $wp->enforceIsNew();
        $wpStorage->save($wp);

        $wr = new WorkspaceRepo(['workspace_uuid' => $wsUuid, 'repo_uuid' => 'repo-1']);
        $wr->enforceIsNew();
        $wrStorage->save($wr);

        // Delete workspace
        $loaded = $wsStorage->loadMultiple();
        $wsStorage->delete($loaded);

        // Junctions should be gone
        self::assertCount(0, $wpStorage->loadMultiple());
        self::assertCount(0, $wrStorage->loadMultiple());
    }

    #[Test]
    public function deleting_project_does_not_delete_repos_or_workspaces(): void
    {
        $projectStorage = $this->manager->getStorage('project');
        $repoStorage = $this->manager->getStorage('repo');
        $wsStorage = $this->manager->getStorage('workspace');
        $prStorage = $this->manager->getStorage('project_repo');

        // Create repo and workspace
        $repo = new Repo(['owner' => 'jonesrussell', 'name' => 'waaseyaa', 'tenant_id' => 'default']);
        $repo->enforceIsNew();
        $repoStorage->save($repo);

        $ws = new Workspace(['name' => 'My Workspace', 'tenant_id' => 'default']);
        $ws->enforceIsNew();
        $wsStorage->save($ws);

        // Create project and link
        $project = new Project(['name' => 'Test Project', 'tenant_id' => 'default']);
        $project->enforceIsNew();
        $projectStorage->save($project);
        $projectUuid = $projectStorage->loadMultiple()[0]->get('uuid');
        $repoUuid = $repoStorage->loadMultiple()[0]->get('uuid');

        $pr = new ProjectRepo(['project_uuid' => $projectUuid, 'repo_uuid' => $repoUuid]);
        $pr->enforceIsNew();
        $prStorage->save($pr);

        // Delete project
        $loaded = $projectStorage->loadMultiple();
        $projectStorage->delete($loaded);

        // Repo and workspace should still exist
        self::assertCount(1, $repoStorage->loadMultiple());
        self::assertCount(1, $wsStorage->loadMultiple());
    }

    private function registerAllTypes(): void
    {
        $this->manager->registerEntityType(new EntityType(
            id: 'project',
            label: 'Project',
            class: Project::class,
            keys: ['id' => 'prid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'prid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'wid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'mode' => ['type' => 'string'],
                'saved_context' => ['type' => 'text_long'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'repo',
            label: 'Repo',
            class: Repo::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'rid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'owner' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'full_name' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'default_branch' => ['type' => 'string'],
                'local_path' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'project_repo',
            label: 'Project Repo',
            class: ProjectRepo::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'project_uuid' => ['type' => 'string', 'required' => true],
                'repo_uuid' => ['type' => 'string', 'required' => true],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'workspace_project',
            label: 'Workspace Project',
            class: WorkspaceProject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'project_uuid' => ['type' => 'string', 'required' => true],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->manager->registerEntityType(new EntityType(
            id: 'workspace_repo',
            label: 'Workspace Repo',
            class: WorkspaceRepo::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'repo_uuid' => ['type' => 'string', 'required' => true],
                'is_active' => ['type' => 'boolean'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit tests/Feature/Subscriber/JunctionCascadeTest.php`
Expected: FAIL — `JunctionCascadeSubscriber` class not found.

- [ ] **Step 3: Create JunctionCascadeSubscriber**

Create `src/Subscriber/JunctionCascadeSubscriber.php`:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Subscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\Event\EntityEvent;
use Waaseyaa\Entity\Event\EntityEvents;

final class JunctionCascadeSubscriber implements EventSubscriberInterface
{
    private const JUNCTION_MAP = [
        'project' => [
            ['table' => 'project_repo', 'column' => 'project_uuid'],
            ['table' => 'workspace_project', 'column' => 'project_uuid'],
        ],
        'workspace' => [
            ['table' => 'workspace_project', 'column' => 'workspace_uuid'],
            ['table' => 'workspace_repo', 'column' => 'workspace_uuid'],
        ],
        'repo' => [
            ['table' => 'project_repo', 'column' => 'repo_uuid'],
            ['table' => 'workspace_repo', 'column' => 'repo_uuid'],
        ],
    ];

    public function __construct(
        private readonly DatabaseInterface $database,
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            EntityEvents::POST_DELETE->value => 'onPostDelete',
        ];
    }

    public function onPostDelete(EntityEvent $event): void
    {
        $entity = $event->entity;
        $entityTypeId = $entity->getEntityTypeId();

        $junctions = self::JUNCTION_MAP[$entityTypeId] ?? [];
        if ($junctions === []) {
            return;
        }

        $uuid = $entity->get('uuid');
        if ($uuid === null || $uuid === '') {
            return;
        }

        foreach ($junctions as $junction) {
            try {
                $this->database->delete($junction['table'], [
                    $junction['column'] => $uuid,
                ]);
            } catch (\Throwable $e) {
                error_log(sprintf(
                    'JunctionCascadeSubscriber: failed to clean %s.%s=%s: %s',
                    $junction['table'],
                    $junction['column'],
                    $uuid,
                    $e->getMessage(),
                ));
            }
        }
    }
}
```

- [ ] **Step 4: Register subscriber in WorkspaceServiceProvider boot()**

Add `boot()` method to `src/Provider/WorkspaceServiceProvider.php`:

```php
public function boot(): void
{
    $dispatcher = $this->resolve(\Symfony\Contracts\EventDispatcher\EventDispatcherInterface::class);
    if ($dispatcher instanceof \Symfony\Component\EventDispatcher\EventDispatcherInterface) {
        $dispatcher->addSubscriber(new \Claudriel\Subscriber\JunctionCascadeSubscriber(
            $this->resolve(\Waaseyaa\Database\DatabaseInterface::class),
        ));
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit tests/Feature/Subscriber/JunctionCascadeTest.php`
Expected: 4 tests, all PASS.

- [ ] **Step 6: Commit**

```bash
git add src/Subscriber/JunctionCascadeSubscriber.php \
  src/Provider/WorkspaceServiceProvider.php \
  tests/Feature/Subscriber/JunctionCascadeTest.php
git commit -m "feat(#431): add JunctionCascadeSubscriber for cascade delete"
```

---

## Task 5: Create Access Policies

**Files:**
- Create: `src/Access/ProjectAccessPolicy.php`
- Modify: `src/Access/WorkspaceAccessPolicy.php`
- Create: `src/Access/RepoAccessPolicy.php`
- Test: `tests/Feature/Access/ProjectAccessPolicyTest.php`
- Test: `tests/Feature/Access/WorkspaceAccessPolicyTest.php`
- Test: `tests/Feature/Access/RepoAccessPolicyTest.php`

- [ ] **Step 1: Write ProjectAccessPolicy test**

Test owner CRUD, tenant read-only, anonymous denied. Use anonymous class implementing `AccountInterface` for test accounts with specific `id()`, `getTenantId()`, `isAuthenticated()`.

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit tests/Feature/Access/ProjectAccessPolicyTest.php`

- [ ] **Step 3: Implement ProjectAccessPolicy**

Create `src/Access/ProjectAccessPolicy.php`:
- `access()`: owner (`account_id` match via `(string) $account->id()`) gets `allowed()` for all operations. Same tenant (`getTenantId()` match) gets `allowed()` for `view`, `neutral()` for update/delete. Anonymous gets `forbidden()`.
- `createAccess()`: authenticated users with a `getTenantId()` get `allowed()`. Anonymous gets `forbidden()`.

- [ ] **Step 4: Run test to verify it passes**

- [ ] **Step 5: Write WorkspaceAccessPolicy test**

Test owner CRUD, non-owner denied (even same tenant), anonymous denied.

- [ ] **Step 6: Update WorkspaceAccessPolicy**

Modify `src/Access/WorkspaceAccessPolicy.php`:
- Fix ownership check: change `$entity->get('owner_id')` to `$entity->get('account_id')` (existing bug — field was always `account_id`)
- Owner gets `allowed()` for all operations. Everyone else gets `forbidden()`. No tenant-level read access.
- Must implement both `access()` and `createAccess()` methods.

- [ ] **Step 7: Run test to verify it passes**

- [ ] **Step 8: Write RepoAccessPolicy test**

Test owner CRUD, tenant read-only, anonymous denied. Same pattern as ProjectAccessPolicy.

- [ ] **Step 9: Implement RepoAccessPolicy**

Create `src/Access/RepoAccessPolicy.php`:
- `access()`: same owner/tenant pattern as ProjectAccessPolicy — owner gets full CRUD, tenant gets read-only, anonymous denied.
- `createAccess()`: authenticated users with a `getTenantId()` get `allowed()`. Anonymous gets `forbidden()`.

- [ ] **Step 10: Run test to verify it passes**

- [ ] **Step 11: Run full test suite**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 12: Commit**

```bash
git add src/Access/ProjectAccessPolicy.php src/Access/WorkspaceAccessPolicy.php \
  src/Access/RepoAccessPolicy.php tests/Feature/Access/
git commit -m "feat(#434): add access policies for Project, Workspace, Repo"
```

---

## Task 6: Create CRUD Controllers + API Routes

**Files:**
- Create: `src/Controller/ProjectController.php`
- Create: `src/Controller/WorkspaceController.php`
- Create: `src/Controller/RepoController.php`
- Modify: `src/Provider/ClaudrielServiceProvider.php` — add routes

- [ ] **Step 1: Create ProjectController**

Create `src/Controller/ProjectController.php`. Follow existing `InternalWorkspaceController` patterns:

```php
<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\SSR\SsrResponse;

final class ProjectController
{
    public function __construct(
        private readonly SqlEntityStorage $projectStorage,
        private readonly SqlEntityStorage $projectRepoStorage,
        private readonly SqlEntityStorage $repoStorage,
        private readonly string $tenantId,
    ) {}

    // CRUD methods: list, create, show, update, delete
    // All follow this signature:
    // public function list(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse

    // Junction methods: listRepos, linkRepo, unlinkRepo
    // linkRepo reads JSON body: { "repo_uuid": "..." }
    // linkRepo checks for duplicate: findBy(['project_uuid' => $uuid, 'repo_uuid' => $repoUuid])
    // Returns 409 if duplicate found, 404 if target not found
}
```

Methods to implement:
- `list()` — query by `tenant_id`, return `{ "data": [...], "count": N }`
- `create()` — read JSON body, create entity with `enforceIsNew()`, return 201
- `show()` — load by UUID, return 404 if not found
- `update()` — load by UUID, apply changes via `set()`, save
- `delete()` — load by UUID, call `$storage->delete([$entity])` (cascade handled by subscriber)
- `listRepos()` — query `project_repo` by `project_uuid`, load matching Repo entities
- `linkRepo()` — read `{ "repo_uuid": "..." }` from body, check for duplicate (409), verify repo exists (404), create ProjectRepo junction
- `unlinkRepo()` — find junction by both UUIDs, delete it, return 204

- [ ] **Step 2: Create WorkspaceController**

Create `src/Controller/WorkspaceController.php` — same CRUD pattern as ProjectController. Additional junction methods:
- `listProjects()`, `linkProject()`, `unlinkProject()` — junction body: `{ "project_uuid": "..." }`
- `listRepos()`, `linkRepo()`, `unlinkRepo()` — junction body: `{ "repo_uuid": "..." }`

Constructor injects: `$workspaceStorage`, `$workspaceProjectStorage`, `$workspaceRepoStorage`, `$projectStorage`, `$repoStorage`, `$tenantId`.

- [ ] **Step 3: Create RepoController**

Create `src/Controller/RepoController.php` — standard CRUD only (list, create, show, update, delete). No junction endpoints. Same constructor pattern: `$repoStorage`, `$tenantId`.

- [ ] **Step 4: Add routes to ClaudrielServiceProvider**

In `src/Provider/ClaudrielServiceProvider.php` `routes()` method, add all routes using `RouteBuilder`:

```php
// Project CRUD
$router->addRoute('claudriel.api.projects.list',
    RouteBuilder::create('/api/projects')->controller(ProjectController::class.'::list')
        ->methods('GET')->options(['_gate' => 'project'])->build());
$router->addRoute('claudriel.api.projects.create',
    RouteBuilder::create('/api/projects')->controller(ProjectController::class.'::create')
        ->methods('POST')->options(['_gate' => 'project'])->build());
$router->addRoute('claudriel.api.projects.show',
    RouteBuilder::create('/api/projects/{uuid}')->controller(ProjectController::class.'::show')
        ->methods('GET')->options(['_gate' => 'project'])->build());
$router->addRoute('claudriel.api.projects.update',
    RouteBuilder::create('/api/projects/{uuid}')->controller(ProjectController::class.'::update')
        ->methods('PATCH')->options(['_gate' => 'project'])->build());
$router->addRoute('claudriel.api.projects.delete',
    RouteBuilder::create('/api/projects/{uuid}')->controller(ProjectController::class.'::delete')
        ->methods('DELETE')->options(['_gate' => 'project'])->build());

// Project junction routes
$router->addRoute('claudriel.api.projects.repos.list',
    RouteBuilder::create('/api/projects/{uuid}/repos')->controller(ProjectController::class.'::listRepos')
        ->methods('GET')->options(['_gate' => 'project'])->build());
$router->addRoute('claudriel.api.projects.repos.link',
    RouteBuilder::create('/api/projects/{uuid}/repos')->controller(ProjectController::class.'::linkRepo')
        ->methods('POST')->options(['_gate' => 'project'])->build());
$router->addRoute('claudriel.api.projects.repos.unlink',
    RouteBuilder::create('/api/projects/{uuid}/repos/{repo_uuid}')->controller(ProjectController::class.'::unlinkRepo')
        ->methods('DELETE')->options(['_gate' => 'project'])->build());

// Repeat same pattern for Workspace CRUD + junction routes and Repo CRUD
// Workspace routes: /api/workspaces, /api/workspaces/{uuid}/projects, /api/workspaces/{uuid}/repos
// Repo routes: /api/repos (CRUD only)
```

- [ ] **Step 5: Wire controller dependencies in ClaudrielServiceProvider**

In `register()`, add factory closures for each controller. Example:

```php
$this->bind(ProjectController::class, fn () => new ProjectController(
    $this->resolve(EntityTypeManager::class)->getStorage('project'),
    $this->resolve(EntityTypeManager::class)->getStorage('project_repo'),
    $this->resolve(EntityTypeManager::class)->getStorage('repo'),
    $this->config['tenant_id'] ?? 'default',
));
```

- [ ] **Step 6: Run full test suite**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 7: Commit**

```bash
git add src/Controller/ProjectController.php src/Controller/WorkspaceController.php \
  src/Controller/RepoController.php src/Provider/ClaudrielServiceProvider.php
git commit -m "feat(#433): add CRUD controllers and API routes for Project, Workspace, Repo"
```

---

## Task 7: GraphQL Schema Updates

**Files:**
- Modify: `src/Provider/ClaudrielServiceProvider.php` — GraphQL mutation overrides

**Context:** Waaseyaa's GraphQL package auto-generates types from registered entity type `fieldDefinitions`. Project, Workspace, and Repo will be exposed automatically once registered. The only provider-level override point is `graphqlMutationOverrides()`.

**Limitation:** Waaseyaa's GraphQL package does not support custom field overrides or relationship field injection from the application layer. The junction-resolved relationship fields (`Project.repos`, `Workspace.projects`, etc.) **cannot be added in v1.8 without a Waaseyaa framework change**. These relationships are accessible via the REST junction endpoints instead.

If relationship fields in GraphQL are needed later, file a Waaseyaa issue for a `graphqlFieldOverrides()` extension point.

- [ ] **Step 1: Verify auto-generated GraphQL types**

Confirm that Project, Workspace, and Repo entity types appear in the GraphQL schema after their entity types are registered. The auto-generated types will include all `fieldDefinitions` fields — no custom code needed.

Run the dev server and query `{ __type(name: "Project") { fields { name } } }` to verify.

- [ ] **Step 2: Verify cascade delete works via GraphQL mutations**

The auto-generated `deleteProject`, `deleteWorkspace`, `deleteRepo` mutations use `SqlEntityStorage::delete()` which dispatches `EntityEvents::POST_DELETE`. The `JunctionCascadeSubscriber` will fire automatically. Verify with a test:
- Create a Project + ProjectRepo junction via REST
- Delete the project via GraphQL mutation
- Verify junction row is gone

If auto-generated delete does not dispatch events, add overrides in `graphqlMutationOverrides()` that call `$storage->delete()` explicitly.

- [ ] **Step 3: Run full test suite**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
git add src/Provider/ClaudrielServiceProvider.php
git commit -m "feat(#435): verify GraphQL schema for Project, Workspace, Repo entities"
```

---

## Task 8: Update Existing Code References

**Files:**
- Search and update any controllers, commands, domain services that reference removed fields

- [ ] **Step 1: Find all references to removed fields**

Search the codebase for: `repo_path`, `repo_url`, `codex_model`, `last_commit_hash`, `ci_status`, `project_id` (on workspace), `metadata` (on project), `settings` (on project), `context` (on project).

- [ ] **Step 2: Update each reference**

For each reference found:
- If it's setting/getting a repo field → use the Repo entity via junction lookup instead
- If it's reading `project_id` from workspace → query `workspace_project` junction instead
- If it's reading `metadata`/`settings`/`context` from project → determine if the data is still needed; if so, add an explicit field; if not, remove the reference

- [ ] **Step 3: Run full test suite**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit`
Expected: All tests pass.

- [ ] **Step 4: Commit**

```bash
# Add each modified file by name (do not use `git add -u` or `git add .`)
# Example: git add src/Controller/InternalWorkspaceController.php src/Domain/Workspace/WorkspaceDiffProvider.php ...
git commit -m "refactor(#432): update code references to removed Workspace/Project fields"
```

---

## Task 9: Database Migrations

**Files:**
- Create: `migrations/Version20260321_CreateProjectWorkspaceRepoTables.php`
- Create: `migrations/Version20260321_MigrateWorkspaceProjectData.php`

**Context:** `SqlSchemaHandler::ensureTable()` handles new table creation, but dropping columns from existing tables requires explicit migrations. Waaseyaa's migration system runs via `bin/claudriel migrate:up`.

- [ ] **Step 1: Create Migration 1 — new tables**

Create `migrations/Version20260321_CreateProjectWorkspaceRepoTables.php`:
- `up()`: Create tables `repo`, `project_repo`, `workspace_project`, `workspace_repo` with all columns per the spec. Use `$this->connection->executeStatement()` with `CREATE TABLE IF NOT EXISTS` SQL.
- `down()`: Drop the 4 tables.

Note: `ensureTable()` may have already created these tables from the entity type registrations. The migration uses `IF NOT EXISTS` to be idempotent.

- [ ] **Step 2: Create Migration 2 — data migration + column drops**

Create `migrations/Version20260321_MigrateWorkspaceProjectData.php`:
- `up()`:
  1. For each workspace row with `repo_url` not null: insert a `repo` row (extracting `owner`, `name`, `full_name` from URL), insert a `workspace_repo` junction row
  2. For each workspace row with `project_id` not null: insert a `workspace_project` junction row
  3. Add `saved_context` column to `workspace` (if not exists)
  4. Drop columns from `workspace`: `repo_path`, `repo_url`, `branch`, `codex_model`, `last_commit_hash`, `ci_status`, `project_id`, `metadata`
  5. Drop columns from `project`: `metadata`, `settings`, `context`
- `down()`: Re-add dropped columns (data cannot be restored)

- [ ] **Step 3: Test migrations on a copy of the database**

```bash
cp claudriel.sqlite claudriel_backup.sqlite
bin/claudriel migrate:up
bin/claudriel migrate:status
```

Verify: new tables exist, old columns gone, data migrated.

- [ ] **Step 4: Commit**

```bash
git add migrations/
git commit -m "feat(#432): add database migrations for Project/Workspace/Repo schema changes"
```

---

## Task 10: Final Integration Test Suite

**Files:**
- Verify: `tests/Feature/Entity/RepoEntityTest.php`
- Verify: `tests/Feature/Entity/JunctionEntityTest.php`
- Verify: `tests/Feature/Subscriber/JunctionCascadeTest.php`
- Verify: `tests/Feature/Access/ProjectAccessPolicyTest.php`
- Verify: `tests/Feature/Access/WorkspaceAccessPolicyTest.php`
- Verify: `tests/Feature/Access/RepoAccessPolicyTest.php`

- [ ] **Step 1: Run full test suite**

Run: `cd /home/jones/dev/claudriel && ./vendor/bin/phpunit`
Expected: All tests pass, including pre-existing tests.

- [ ] **Step 2: Verify test coverage gaps**

Check that the following scenarios are covered:
- All entity CRUD paths (create, read, update, delete)
- Junction link/unlink/list
- Junction duplicate prevention (same UUID pair)
- Cascade delete (all three parent types)
- Access policies (owner, tenant, anonymous)
- Other parent entities survive cascade delete

Add any missing test cases.

- [ ] **Step 3: Commit any additions**

```bash
git add tests/
git commit -m "test(#436): complete integration test coverage for Projects & Workspaces"
```

---

## Dependency Order

```
Task 1 (Repo entity)
  ↓
Task 2 (Junction entities) ← depends on Repo
  ↓
Task 3 (Refactor Project/Workspace) ← depends on junctions existing
  ↓
Task 4 (JunctionCascadeSubscriber) ← depends on all entities
  ↓
Task 5 (Access policies) ← depends on entities
  ↓
Task 6 (Controllers + routes) ← depends on entities + policies
  ↓
Task 7 (GraphQL verification) ← depends on entities
  ↓
Task 8 (Update references) ← depends on refactored entities
  ↓
Task 9 (Migrations) ← depends on all entity/field changes being finalized
  ↓
Task 10 (Final integration tests) ← depends on everything
```

Tasks 1-4 are strictly sequential. Tasks 5-7 can run in parallel after Task 4. Task 8 can run after Task 3. Task 9 runs after all schema changes are finalized. Task 10 is the final gate.
