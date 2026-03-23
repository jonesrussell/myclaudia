<?php

declare(strict_types=1);

namespace Claudriel\Tests\Integration\Entity;

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

#[CoversClass(ProjectRepo::class)]
#[CoversClass(WorkspaceProject::class)]
#[CoversClass(WorkspaceRepo::class)]
#[CoversClass(JunctionCascadeSubscriber::class)]
final class JunctionIntegrationTest extends TestCase
{
    private EntityTypeManager $manager;

    private SqlEntityStorage $projectStorage;

    private SqlEntityStorage $workspaceStorage;

    private SqlEntityStorage $repoStorage;

    private SqlEntityStorage $projectRepoStorage;

    private SqlEntityStorage $workspaceProjectStorage;

    private SqlEntityStorage $workspaceRepoStorage;

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

        // Register parent entity types.
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

        // Register junction entity types.
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

        // Wire the cascade subscriber.
        $dispatcher->addSubscriber(new JunctionCascadeSubscriber($this->manager));

        $this->projectStorage = $this->manager->getStorage('project');
        $this->workspaceStorage = $this->manager->getStorage('workspace');
        $this->repoStorage = $this->manager->getStorage('repo');
        $this->projectRepoStorage = $this->manager->getStorage('project_repo');
        $this->workspaceProjectStorage = $this->manager->getStorage('workspace_project');
        $this->workspaceRepoStorage = $this->manager->getStorage('workspace_repo');
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function createProject(string $name = 'TestProject'): Project
    {
        $project = new Project(['name' => $name, 'tenant_id' => 'default']);
        $project->enforceIsNew();
        $this->projectStorage->save($project);

        return $project;
    }

    private function createWorkspace(string $name = 'TestWorkspace'): Workspace
    {
        $workspace = new Workspace(['name' => $name, 'tenant_id' => 'default']);
        $workspace->enforceIsNew();
        $this->workspaceStorage->save($workspace);

        return $workspace;
    }

    private function createRepo(string $name = 'test-repo'): Repo
    {
        $repo = new Repo(['owner' => 'jonesrussell', 'name' => $name, 'tenant_id' => 'default']);
        $repo->enforceIsNew();
        $this->repoStorage->save($repo);

        return $repo;
    }

    // ── ProjectRepo junction ──────────────────────────────────────

    #[Test]
    public function it_links_a_project_to_a_repo(): void
    {
        $project = $this->createProject();
        $repo = $this->createRepo();

        $junction = new ProjectRepo([
            'project_uuid' => $project->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->projectRepoStorage->save($junction);

        self::assertNotNull($junction->id());
    }

    #[Test]
    public function it_finds_project_repo_link_by_foreign_key(): void
    {
        $project = $this->createProject();
        $repo = $this->createRepo();

        $junction = new ProjectRepo([
            'project_uuid' => $project->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->projectRepoStorage->save($junction);

        $query = $this->projectRepoStorage->getQuery();
        $query->condition('project_uuid', $project->get('uuid'));
        $ids = $query->execute();

        self::assertCount(1, $ids);
    }

    #[Test]
    public function it_allows_duplicate_project_repo_links(): void
    {
        $project = $this->createProject();
        $repo = $this->createRepo();

        $j1 = new ProjectRepo([
            'project_uuid' => $project->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $j1->enforceIsNew();
        $this->projectRepoStorage->save($j1);

        $j2 = new ProjectRepo([
            'project_uuid' => $project->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $j2->enforceIsNew();
        $this->projectRepoStorage->save($j2);

        $query = $this->projectRepoStorage->getQuery();
        $query->condition('project_uuid', $project->get('uuid'));
        $ids = $query->execute();

        // Both records are saved (no unique constraint at storage level).
        self::assertCount(2, $ids);
    }

    #[Test]
    public function it_unlinks_a_project_repo_junction(): void
    {
        $project = $this->createProject();
        $repo = $this->createRepo();

        $junction = new ProjectRepo([
            'project_uuid' => $project->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->projectRepoStorage->save($junction);

        $id = $junction->id();
        $this->projectRepoStorage->delete([$junction]);

        self::assertNull($this->projectRepoStorage->load($id));
    }

    #[Test]
    public function it_cascades_project_deletion_to_project_repo_junctions(): void
    {
        $project = $this->createProject();
        $repo = $this->createRepo();

        $junction = new ProjectRepo([
            'project_uuid' => $project->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->projectRepoStorage->save($junction);

        $junctionId = $junction->id();

        // Delete the parent project; cascade subscriber should clean junction.
        $this->projectStorage->delete([$project]);

        self::assertNull($this->projectRepoStorage->load($junctionId));
    }

    // ── WorkspaceProject junction ─────────────────────────────────

    #[Test]
    public function it_links_a_workspace_to_a_project(): void
    {
        $workspace = $this->createWorkspace();
        $project = $this->createProject();

        $junction = new WorkspaceProject([
            'workspace_uuid' => $workspace->get('uuid'),
            'project_uuid' => $project->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->workspaceProjectStorage->save($junction);

        self::assertNotNull($junction->id());
    }

    #[Test]
    public function it_finds_workspace_project_link_by_foreign_key(): void
    {
        $workspace = $this->createWorkspace();
        $project = $this->createProject();

        $junction = new WorkspaceProject([
            'workspace_uuid' => $workspace->get('uuid'),
            'project_uuid' => $project->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->workspaceProjectStorage->save($junction);

        $query = $this->workspaceProjectStorage->getQuery();
        $query->condition('workspace_uuid', $workspace->get('uuid'));
        $ids = $query->execute();

        self::assertCount(1, $ids);
    }

    #[Test]
    public function it_unlinks_a_workspace_project_junction(): void
    {
        $workspace = $this->createWorkspace();
        $project = $this->createProject();

        $junction = new WorkspaceProject([
            'workspace_uuid' => $workspace->get('uuid'),
            'project_uuid' => $project->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->workspaceProjectStorage->save($junction);

        $id = $junction->id();
        $this->workspaceProjectStorage->delete([$junction]);

        self::assertNull($this->workspaceProjectStorage->load($id));
    }

    #[Test]
    public function it_cascades_workspace_deletion_to_workspace_project_junctions(): void
    {
        $workspace = $this->createWorkspace();
        $project = $this->createProject();

        $junction = new WorkspaceProject([
            'workspace_uuid' => $workspace->get('uuid'),
            'project_uuid' => $project->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->workspaceProjectStorage->save($junction);

        $junctionId = $junction->id();

        $this->workspaceStorage->delete([$workspace]);

        self::assertNull($this->workspaceProjectStorage->load($junctionId));
    }

    #[Test]
    public function it_cascades_project_deletion_to_workspace_project_junctions(): void
    {
        $workspace = $this->createWorkspace();
        $project = $this->createProject();

        $junction = new WorkspaceProject([
            'workspace_uuid' => $workspace->get('uuid'),
            'project_uuid' => $project->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->workspaceProjectStorage->save($junction);

        $junctionId = $junction->id();

        $this->projectStorage->delete([$project]);

        self::assertNull($this->workspaceProjectStorage->load($junctionId));
    }

    // ── WorkspaceRepo junction ────────────────────────────────────

    #[Test]
    public function it_links_a_workspace_to_a_repo(): void
    {
        $workspace = $this->createWorkspace();
        $repo = $this->createRepo();

        $junction = new WorkspaceRepo([
            'workspace_uuid' => $workspace->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->workspaceRepoStorage->save($junction);

        self::assertNotNull($junction->id());
        self::assertTrue($junction->get('is_active'));
    }

    #[Test]
    public function it_finds_workspace_repo_link_by_foreign_key(): void
    {
        $workspace = $this->createWorkspace();
        $repo = $this->createRepo();

        $junction = new WorkspaceRepo([
            'workspace_uuid' => $workspace->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->workspaceRepoStorage->save($junction);

        $query = $this->workspaceRepoStorage->getQuery();
        $query->condition('repo_uuid', $repo->get('uuid'));
        $ids = $query->execute();

        self::assertCount(1, $ids);
    }

    #[Test]
    public function it_unlinks_a_workspace_repo_junction(): void
    {
        $workspace = $this->createWorkspace();
        $repo = $this->createRepo();

        $junction = new WorkspaceRepo([
            'workspace_uuid' => $workspace->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->workspaceRepoStorage->save($junction);

        $id = $junction->id();
        $this->workspaceRepoStorage->delete([$junction]);

        self::assertNull($this->workspaceRepoStorage->load($id));
    }

    #[Test]
    public function it_cascades_workspace_deletion_to_workspace_repo_junctions(): void
    {
        $workspace = $this->createWorkspace();
        $repo = $this->createRepo();

        $junction = new WorkspaceRepo([
            'workspace_uuid' => $workspace->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $junction->enforceIsNew();
        $this->workspaceRepoStorage->save($junction);

        $junctionId = $junction->id();

        $this->workspaceStorage->delete([$workspace]);

        self::assertNull($this->workspaceRepoStorage->load($junctionId));
    }

    #[Test]
    public function it_cascades_repo_deletion_to_project_repo_and_workspace_repo_junctions(): void
    {
        $project = $this->createProject();
        $workspace = $this->createWorkspace();
        $repo = $this->createRepo();

        $prJunction = new ProjectRepo([
            'project_uuid' => $project->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $prJunction->enforceIsNew();
        $this->projectRepoStorage->save($prJunction);

        $wrJunction = new WorkspaceRepo([
            'workspace_uuid' => $workspace->get('uuid'),
            'repo_uuid' => $repo->get('uuid'),
        ]);
        $wrJunction->enforceIsNew();
        $this->workspaceRepoStorage->save($wrJunction);

        $prId = $prJunction->id();
        $wrId = $wrJunction->id();

        // Delete the repo; both junction types should be cleaned.
        $this->repoStorage->delete([$repo]);

        self::assertNull($this->projectRepoStorage->load($prId));
        self::assertNull($this->workspaceRepoStorage->load($wrId));
    }
}
