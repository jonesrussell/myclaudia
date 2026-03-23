<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\CLI;

use Claudriel\CLI\WorkspaceLinkRepoCommand;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceRepo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class WorkspaceLinkRepoCommandTest extends TestCase
{
    public function test_links_repository_to_workspace(): void
    {
        $dispatcher = new EventDispatcher;

        $workspaceRepo = new EntityRepository(
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $repoRepo = new EntityRepository(
            new EntityType(id: 'repo', label: 'Repo', class: Repo::class, keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $workspaceRepoRepo = new EntityRepository(
            new EntityType(id: 'workspace_repo', label: 'WorkspaceRepo', class: WorkspaceRepo::class, keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $workspace = new Workspace(['name' => 'Claudriel System']);
        $workspaceRepo->save($workspace);

        $tester = new CommandTester(new WorkspaceLinkRepoCommand($workspaceRepo, $repoRepo, $workspaceRepoRepo));
        $tester->execute([
            'workspace_uuid' => $workspace->get('uuid'),
            'repo_path' => '/home/jones/dev/claudriel',
            'repo_url' => 'git@github.com:jonesrussell/claudriel.git',
        ]);

        self::assertSame(0, $tester->getStatusCode());

        $repos = $repoRepo->findBy(['local_path' => '/home/jones/dev/claudriel']);
        self::assertCount(1, $repos);
        self::assertSame('/home/jones/dev/claudriel', $repos[0]->get('local_path'));
        self::assertSame('git@github.com:jonesrussell/claudriel.git', $repos[0]->get('url'));

        $junctions = $workspaceRepoRepo->findBy(['workspace_uuid' => $workspace->get('uuid')]);
        self::assertCount(1, $junctions);
        self::assertSame((string) $repos[0]->get('uuid'), $junctions[0]->get('repo_uuid'));
    }
}
