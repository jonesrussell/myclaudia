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

        $loaded = $this->repoStorage->load($repo->id());
        self::assertNotNull($loaded);
        self::assertSame('jonesrussell', $loaded->get('owner'));
        self::assertSame('claudriel', $loaded->get('name'));
        self::assertSame('jonesrussell/claudriel', $loaded->get('full_name'));
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

        $loaded = $this->repoStorage->load($repo->id());
        self::assertNotNull($loaded);
        $loaded->set('default_branch', 'develop');
        $this->repoStorage->save($loaded);

        $reloaded = $this->repoStorage->load($loaded->id());
        self::assertNotNull($reloaded);
        self::assertSame('develop', $reloaded->get('default_branch'));
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

        $id = $repo->id();
        self::assertNotNull($this->repoStorage->load($id));

        $this->repoStorage->delete([$repo]);
        self::assertNull($this->repoStorage->load($id));
    }
}
