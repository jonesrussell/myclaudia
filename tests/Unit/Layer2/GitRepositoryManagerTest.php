<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Layer2;

use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Artifact;
use PHPUnit\Framework\TestCase;

final class GitRepositoryManagerTest extends TestCase
{
    private ?string $tmpDir = null;

    protected function tearDown(): void
    {
        if ($this->tmpDir !== null && is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    public function test_clone_builds_git_clone_command(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/claudriel-git-'.bin2hex(random_bytes(4));
        $commands = [];
        $manager = new GitRepositoryManager($this->tmpDir, function (string $command) use (&$commands): array {
            $commands[] = $command;

            return ['exit_code' => 0, 'output' => ''];
        });

        $manager->clone('git@github.com:jonesrussell/claudriel.git', $this->tmpDir.'/abc/repo', 'develop');

        self::assertSame(
            "git clone --branch 'develop' --single-branch 'git@github.com:jonesrussell/claudriel.git' '".$this->tmpDir."/abc/repo'",
            $commands[0],
        );
        self::assertDirectoryExists($this->tmpDir.'/abc');
    }

    public function test_ensure_local_copy_clones_when_repository_missing(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/claudriel-git-'.bin2hex(random_bytes(4));
        $commands = [];
        $manager = new GitRepositoryManager($this->tmpDir, function (string $command) use (&$commands): array {
            $commands[] = $command;

            if (str_contains($command, 'rev-parse HEAD')) {
                return ['exit_code' => 0, 'output' => "abc123\n"];
            }

            return ['exit_code' => 0, 'output' => ''];
        });

        $artifact = new Artifact([
            'workspace_uuid' => 'workspace-1',
            'type' => 'repo',
            'repo_url' => 'git@github.com:jonesrussell/claudriel.git',
        ]);

        $manager->ensureLocalCopy($artifact);

        self::assertSame($this->tmpDir.'/workspace-1/repo', $artifact->get('local_path'));
        self::assertSame('abc123', $artifact->get('last_commit'));
        self::assertStringContainsString('git clone --branch', $commands[0]);
        self::assertStringContainsString('rev-parse HEAD', $commands[1]);
    }

    public function test_ensure_local_copy_pulls_when_repository_exists(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/claudriel-git-'.bin2hex(random_bytes(4));
        mkdir($this->tmpDir.'/workspace-2/repo/.git', 0755, true);

        $commands = [];
        $manager = new GitRepositoryManager($this->tmpDir, function (string $command) use (&$commands): array {
            $commands[] = $command;

            if (str_contains($command, 'rev-parse HEAD')) {
                return ['exit_code' => 0, 'output' => "def456\n"];
            }

            return ['exit_code' => 0, 'output' => ''];
        });

        $artifact = new Artifact([
            'workspace_uuid' => 'workspace-2',
            'type' => 'repo',
            'repo_url' => 'git@github.com:jonesrussell/claudriel.git',
            'local_path' => $this->tmpDir.'/workspace-2/repo',
        ]);

        $manager->ensureLocalCopy($artifact);

        self::assertSame('def456', $artifact->get('last_commit'));
        self::assertStringContainsString("git -C '".$this->tmpDir."/workspace-2/repo' pull --ff-only", $commands[0]);
        self::assertStringContainsString('rev-parse HEAD', $commands[1]);
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
