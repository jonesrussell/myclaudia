<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\WorkspaceCloneCommand;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Artifact;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceRepo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class WorkspaceCloneCommandTest extends TestCase
{
    private EntityRepository $workspaceRepo;

    private EntityRepository $artifactRepo;

    private EntityRepository $repoRepo;

    private EntityRepository $workspaceRepoRepo;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher;

        $this->workspaceRepo = new EntityRepository(
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $this->artifactRepo = new EntityRepository(
            new EntityType(id: 'artifact', label: 'Artifact', class: Artifact::class, keys: ['id' => 'artid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $this->repoRepo = new EntityRepository(
            new EntityType(id: 'repo', label: 'Repo', class: Repo::class, keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $this->workspaceRepoRepo = new EntityRepository(
            new EntityType(id: 'workspace_repo', label: 'WorkspaceRepo', class: WorkspaceRepo::class, keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
    }

    public function test_clones_workspace_repository_and_saves_artifact(): void
    {
        $workspace = new Workspace(['name' => 'Workspace Alpha']);
        $this->workspaceRepo->save($workspace);

        $manager = new GitRepositoryManager('/tmp/claudriel-tests', function (string $command): array {
            if (str_contains($command, 'rev-parse HEAD')) {
                return ['exit_code' => 0, 'output' => "abc123\n"];
            }

            return ['exit_code' => 0, 'output' => ''];
        });

        $tester = new CommandTester(new WorkspaceCloneCommand($this->workspaceRepo, $this->artifactRepo, $manager, $this->repoRepo, $this->workspaceRepoRepo));
        $tester->execute([
            'workspace_uuid' => $workspace->get('uuid'),
            'repo_url' => 'git@github.com:jonesrussell/claudriel.git',
            '--branch' => 'main',
        ]);

        self::assertSame(0, $tester->getStatusCode());

        $artifacts = $this->artifactRepo->findBy(['workspace_uuid' => $workspace->get('uuid'), 'type' => 'repo']);
        self::assertCount(1, $artifacts);
        self::assertSame('git@github.com:jonesrussell/claudriel.git', $artifacts[0]->get('repo_url'));
        self::assertSame('/tmp/claudriel-tests/'.$workspace->get('uuid').'/repo', $artifacts[0]->get('local_path'));
        self::assertSame('abc123', $artifacts[0]->get('last_commit'));

        $localPath = '/tmp/claudriel-tests/'.$workspace->get('uuid').'/repo';
        $repos = $this->repoRepo->findBy(['local_path' => $localPath]);
        self::assertCount(1, $repos);
        self::assertSame('git@github.com:jonesrussell/claudriel.git', $repos[0]->get('url'));

        $junctions = $this->workspaceRepoRepo->findBy(['workspace_uuid' => $workspace->get('uuid')]);
        self::assertCount(1, $junctions);
        self::assertSame((string) $repos[0]->get('uuid'), $junctions[0]->get('repo_uuid'));
    }

    public function test_fails_when_workspace_is_missing(): void
    {
        $manager = new GitRepositoryManager('/tmp/claudriel-tests', fn (string $command): array => ['exit_code' => 0, 'output' => '']);

        $tester = new CommandTester(new WorkspaceCloneCommand($this->workspaceRepo, $this->artifactRepo, $manager, $this->repoRepo, $this->workspaceRepoRepo));
        $tester->execute([
            'workspace_uuid' => 'missing-workspace',
            'repo_url' => 'git@github.com:jonesrussell/claudriel.git',
        ]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Workspace not found', $tester->getDisplay());
    }
}
