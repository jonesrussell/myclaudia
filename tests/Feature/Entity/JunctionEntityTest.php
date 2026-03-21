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
        $dispatcher = new EventDispatcher;

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

        $loaded = $storage->load($link->id());
        self::assertNotNull($loaded);
        self::assertSame('proj-111', $loaded->get('project_uuid'));
        self::assertSame('repo-222', $loaded->get('repo_uuid'));
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

        $loaded = $storage->load($link->id());
        self::assertNotNull($loaded);
        self::assertSame('ws-111', $loaded->get('workspace_uuid'));
        self::assertSame('proj-222', $loaded->get('project_uuid'));
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

        $loaded = $storage->load($link->id());
        self::assertNotNull($loaded);
        self::assertTrue((bool) $loaded->get('is_active'));
    }

    #[Test]
    public function it_detects_duplicate_project_repo_link(): void
    {
        $storage = $this->manager->getStorage('project_repo');

        $link1 = new ProjectRepo([
            'project_uuid' => 'proj-111',
            'repo_uuid' => 'repo-222',
        ]);
        $link1->enforceIsNew();
        $storage->save($link1);

        // Query for existing link with same UUIDs
        $existing = $storage->getQuery()
            ->condition('project_uuid', 'proj-111')
            ->condition('repo_uuid', 'repo-222')
            ->execute();
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

        $id = $link->id();
        self::assertNotNull($storage->load($id));

        $storage->delete([$link]);
        self::assertNull($storage->load($id));
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
