<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\WorkspacePullCommand;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Artifact;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class WorkspacePullCommandTest extends TestCase
{
    private EntityRepository $workspaceRepo;

    private EntityRepository $artifactRepo;

    private ?string $tmpDir = null;

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

        $this->tmpDir = sys_get_temp_dir().'/claudriel-pull-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    public function test_pulls_workspace_repository_and_updates_commit(): void
    {
        $workspace = new Workspace(['name' => 'Workspace Beta']);
        $this->workspaceRepo->save($workspace);

        $repoPath = $this->tmpDir.'/repo';
        mkdir($repoPath.'/.git', 0755, true);

        $artifact = new Artifact([
            'workspace_uuid' => $workspace->get('uuid'),
            'type' => 'repo',
            'repo_url' => 'git@github.com:jonesrussell/claudriel.git',
            'local_path' => $repoPath,
        ]);
        $this->artifactRepo->save($artifact);

        $manager = new GitRepositoryManager('/tmp/unused', function (string $command): array {
            if (str_contains($command, 'rev-parse HEAD')) {
                return ['exit_code' => 0, 'output' => "def456\n"];
            }

            return ['exit_code' => 0, 'output' => ''];
        });

        $tester = new CommandTester(new WorkspacePullCommand($this->workspaceRepo, $this->artifactRepo, $manager));
        $tester->execute(['workspace_uuid' => $workspace->get('uuid')]);

        self::assertSame(0, $tester->getStatusCode());

        $artifacts = $this->artifactRepo->findBy(['workspace_uuid' => $workspace->get('uuid'), 'type' => 'repo']);
        self::assertCount(1, $artifacts);
        self::assertSame('def456', $artifacts[0]->get('last_commit'));
    }

    public function test_fails_when_repository_artifact_is_missing(): void
    {
        $workspace = new Workspace(['name' => 'Workspace Beta']);
        $this->workspaceRepo->save($workspace);

        $manager = new GitRepositoryManager('/tmp/unused', fn (string $command): array => ['exit_code' => 0, 'output' => '']);

        $tester = new CommandTester(new WorkspacePullCommand($this->workspaceRepo, $this->artifactRepo, $manager));
        $tester->execute(['workspace_uuid' => $workspace->get('uuid')]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Repository artifact not found', $tester->getDisplay());
    }

    private function removeDirectory(string $path): void
    {
        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $itemPath = $path.'/'.$item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);

                continue;
            }

            unlink($itemPath);
        }

        rmdir($path);
    }
}
