<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\WorkspacesCommand;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class WorkspacesCommandTest extends TestCase
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

    public function test_lists_workspaces(): void
    {
        $this->repo->save(new Workspace(['wid' => 1, 'name' => 'Alpha Project', 'description' => 'First workspace']));
        $this->repo->save(new Workspace(['wid' => 2, 'name' => 'Beta Campaign', 'description' => 'Second workspace']));

        $tester = new CommandTester(new WorkspacesCommand($this->repo));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        self::assertStringContainsString('Alpha Project', $display);
        self::assertStringContainsString('Beta Campaign', $display);
    }

    public function test_shows_message_when_no_workspaces(): void
    {
        $tester = new CommandTester(new WorkspacesCommand($this->repo));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No workspaces found.', $tester->getDisplay());
    }
}
