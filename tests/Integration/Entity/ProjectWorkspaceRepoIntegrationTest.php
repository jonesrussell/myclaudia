<?php

declare(strict_types=1);

namespace Claudriel\Tests\Integration\Entity;

use Claudriel\Entity\Project;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

#[CoversClass(Project::class)]
#[CoversClass(Workspace::class)]
#[CoversClass(Repo::class)]
final class ProjectWorkspaceRepoIntegrationTest extends TestCase
{
    private EntityTypeManager $manager;

    private SqlEntityStorage $projectStorage;

    private SqlEntityStorage $workspaceStorage;

    private SqlEntityStorage $repoStorage;

    protected function setUp(): void
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;

        $this->manager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($db, $dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $db))->ensureTable();

                return new SqlEntityStorage($definition, $db, $dispatcher);
            },
        );

        $this->manager->registerEntityType(new EntityType(
            id: 'project',
            label: 'Project',
            class: Project::class,
            keys: ['id' => 'prid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'prid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true, 'maxLength' => 255],
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
                'saved_context' => ['type' => 'text_long'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'mode' => ['type' => 'string'],
                'status' => ['type' => 'string'],
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

        $this->projectStorage = $this->manager->getStorage('project');
        $this->workspaceStorage = $this->manager->getStorage('workspace');
        $this->repoStorage = $this->manager->getStorage('repo');
    }

    // ── Project CRUD ──────────────────────────────────────────────

    #[Test]
    public function it_creates_a_project_with_valid_data(): void
    {
        $project = new Project([
            'name' => 'Claudriel',
            'description' => 'AI operations system',
            'tenant_id' => 'default',
        ]);
        $project->enforceIsNew();
        $this->projectStorage->save($project);

        self::assertNotNull($project->id());
        self::assertSame('Claudriel', $project->get('name'));
        self::assertSame('AI operations system', $project->get('description'));
        self::assertSame('active', $project->get('status'));
    }

    #[Test]
    public function it_reads_a_project_by_id(): void
    {
        $project = new Project([
            'name' => 'TestProject',
            'tenant_id' => 'default',
        ]);
        $project->enforceIsNew();
        $this->projectStorage->save($project);

        $loaded = $this->projectStorage->load($project->id());

        self::assertNotNull($loaded);
        self::assertSame('TestProject', $loaded->get('name'));
    }

    #[Test]
    public function it_updates_project_fields(): void
    {
        $project = new Project([
            'name' => 'Original',
            'tenant_id' => 'default',
        ]);
        $project->enforceIsNew();
        $this->projectStorage->save($project);

        $loaded = $this->projectStorage->load($project->id());
        self::assertNotNull($loaded);
        $loaded->set('name', 'Updated');
        $loaded->set('description', 'New description');
        $this->projectStorage->save($loaded);

        $reloaded = $this->projectStorage->load($project->id());
        self::assertNotNull($reloaded);
        self::assertSame('Updated', $reloaded->get('name'));
        self::assertSame('New description', $reloaded->get('description'));
    }

    #[Test]
    public function it_deletes_a_project(): void
    {
        $project = new Project([
            'name' => 'ToDelete',
            'tenant_id' => 'default',
        ]);
        $project->enforceIsNew();
        $this->projectStorage->save($project);

        $id = $project->id();
        self::assertNotNull($this->projectStorage->load($id));

        $this->projectStorage->delete([$project]);
        self::assertNull($this->projectStorage->load($id));
    }

    #[Test]
    public function it_finds_projects_by_criteria(): void
    {
        foreach (['Alpha', 'Beta', 'Gamma'] as $name) {
            $project = new Project([
                'name' => $name,
                'status' => 'active',
                'tenant_id' => 'default',
            ]);
            $project->enforceIsNew();
            $this->projectStorage->save($project);
        }

        $query = $this->projectStorage->getQuery();
        $query->condition('status', 'active');
        $ids = $query->execute();

        self::assertCount(3, $ids);
    }

    // ── Workspace CRUD ────────────────────────────────────────────

    #[Test]
    public function it_creates_a_workspace_with_valid_data(): void
    {
        $workspace = new Workspace([
            'name' => 'Dev Workspace',
            'description' => 'Development workspace',
            'tenant_id' => 'default',
        ]);
        $workspace->enforceIsNew();
        $this->workspaceStorage->save($workspace);

        self::assertNotNull($workspace->id());
        self::assertSame('Dev Workspace', $workspace->get('name'));
        self::assertSame('persistent', $workspace->get('mode'));
        self::assertSame('active', $workspace->get('status'));
    }

    #[Test]
    public function it_reads_a_workspace_by_id(): void
    {
        $workspace = new Workspace([
            'name' => 'ReadTest',
            'tenant_id' => 'default',
        ]);
        $workspace->enforceIsNew();
        $this->workspaceStorage->save($workspace);

        $loaded = $this->workspaceStorage->load($workspace->id());

        self::assertNotNull($loaded);
        self::assertSame('ReadTest', $loaded->get('name'));
    }

    #[Test]
    public function it_updates_workspace_fields(): void
    {
        $workspace = new Workspace([
            'name' => 'Original WS',
            'tenant_id' => 'default',
        ]);
        $workspace->enforceIsNew();
        $this->workspaceStorage->save($workspace);

        $loaded = $this->workspaceStorage->load($workspace->id());
        self::assertNotNull($loaded);
        $loaded->set('name', 'Updated WS');
        $loaded->set('mode', 'ephemeral');
        $this->workspaceStorage->save($loaded);

        $reloaded = $this->workspaceStorage->load($workspace->id());
        self::assertNotNull($reloaded);
        self::assertSame('Updated WS', $reloaded->get('name'));
        self::assertSame('ephemeral', $reloaded->get('mode'));
    }

    #[Test]
    public function it_deletes_a_workspace(): void
    {
        $workspace = new Workspace([
            'name' => 'ToDelete WS',
            'tenant_id' => 'default',
        ]);
        $workspace->enforceIsNew();
        $this->workspaceStorage->save($workspace);

        $id = $workspace->id();
        self::assertNotNull($this->workspaceStorage->load($id));

        $this->workspaceStorage->delete([$workspace]);
        self::assertNull($this->workspaceStorage->load($id));
    }

    #[Test]
    public function it_finds_workspaces_by_criteria(): void
    {
        foreach (['WS-A', 'WS-B'] as $name) {
            $workspace = new Workspace([
                'name' => $name,
                'mode' => 'persistent',
                'tenant_id' => 'default',
            ]);
            $workspace->enforceIsNew();
            $this->workspaceStorage->save($workspace);
        }

        $ephemeral = new Workspace([
            'name' => 'WS-C',
            'mode' => 'ephemeral',
            'tenant_id' => 'default',
        ]);
        $ephemeral->enforceIsNew();
        $this->workspaceStorage->save($ephemeral);

        $query = $this->workspaceStorage->getQuery();
        $query->condition('mode', 'persistent');
        $ids = $query->execute();

        self::assertCount(2, $ids);
    }

    // ── Repo CRUD ─────────────────────────────────────────────────

    #[Test]
    public function it_creates_a_repo_with_valid_data(): void
    {
        $repo = new Repo([
            'owner' => 'jonesrussell',
            'name' => 'claudriel',
            'tenant_id' => 'default',
        ]);
        $repo->enforceIsNew();
        $this->repoStorage->save($repo);

        self::assertNotNull($repo->id());
        self::assertSame('jonesrussell', $repo->get('owner'));
        self::assertSame('claudriel', $repo->get('name'));
        self::assertSame('jonesrussell/claudriel', $repo->get('full_name'));
        self::assertSame('main', $repo->get('default_branch'));
    }

    #[Test]
    public function it_reads_a_repo_by_id(): void
    {
        $repo = new Repo([
            'owner' => 'jonesrussell',
            'name' => 'waaseyaa',
            'tenant_id' => 'default',
        ]);
        $repo->enforceIsNew();
        $this->repoStorage->save($repo);

        $loaded = $this->repoStorage->load($repo->id());

        self::assertNotNull($loaded);
        self::assertSame('waaseyaa', $loaded->get('name'));
        self::assertSame('jonesrussell', $loaded->get('owner'));
    }

    #[Test]
    public function it_updates_repo_fields(): void
    {
        $repo = new Repo([
            'owner' => 'jonesrussell',
            'name' => 'old-name',
            'tenant_id' => 'default',
        ]);
        $repo->enforceIsNew();
        $this->repoStorage->save($repo);

        $loaded = $this->repoStorage->load($repo->id());
        self::assertNotNull($loaded);
        $loaded->set('url', 'https://github.com/jonesrussell/new-repo');
        $loaded->set('local_path', '/home/jones/dev/new-repo');
        $this->repoStorage->save($loaded);

        $reloaded = $this->repoStorage->load($repo->id());
        self::assertNotNull($reloaded);
        self::assertSame('https://github.com/jonesrussell/new-repo', $reloaded->get('url'));
        self::assertSame('/home/jones/dev/new-repo', $reloaded->get('local_path'));
    }

    #[Test]
    public function it_deletes_a_repo(): void
    {
        $repo = new Repo([
            'owner' => 'jonesrussell',
            'name' => 'to-delete',
            'tenant_id' => 'default',
        ]);
        $repo->enforceIsNew();
        $this->repoStorage->save($repo);

        $id = $repo->id();
        self::assertNotNull($this->repoStorage->load($id));

        $this->repoStorage->delete([$repo]);
        self::assertNull($this->repoStorage->load($id));
    }

    #[Test]
    public function it_finds_repos_by_criteria(): void
    {
        foreach (['repo-a', 'repo-b', 'repo-c'] as $name) {
            $repo = new Repo([
                'owner' => 'jonesrussell',
                'name' => $name,
                'tenant_id' => 'default',
            ]);
            $repo->enforceIsNew();
            $this->repoStorage->save($repo);
        }

        $query = $this->repoStorage->getQuery();
        $query->condition('owner', 'jonesrussell');
        $ids = $query->execute();

        self::assertCount(3, $ids);
    }
}
