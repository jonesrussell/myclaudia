<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\WorkspaceCreateCommand;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class WorkspaceCreateCommandTest extends TestCase
{
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new EntityRepository(
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    public function test_creates_workspace(): void
    {
        $tester = new CommandTester(new WorkspaceCreateCommand($this->repo));
        $tester->execute(['name' => 'TestWorkspace']);

        self::assertSame(0, $tester->getStatusCode());

        $all = $this->repo->findBy([]);
        self::assertCount(1, $all);
        self::assertSame('TestWorkspace', $all[0]->get('name'));
    }

    public function test_creates_workspace_with_description(): void
    {
        $tester = new CommandTester(new WorkspaceCreateCommand($this->repo));
        $tester->execute(['name' => 'TestWorkspace', '--description' => 'A detailed description']);

        self::assertSame(0, $tester->getStatusCode());

        $workspaces = $this->repo->findBy([]);
        self::assertCount(1, $workspaces);
        self::assertSame('A detailed description', $workspaces[0]->get('description'));
    }
}
