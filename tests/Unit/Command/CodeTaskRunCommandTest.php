<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\CodeTaskRunCommand;
use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Entity\CodeTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\RuntimeException;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CodeTaskRunCommandTest extends TestCase
{
    private function makeRunner(?EntityRepositoryInterface $repo = null, ?callable $processRunner = null): CodeTaskRunner
    {
        $repo ??= $this->createMock(EntityRepositoryInterface::class);
        $noOpCommandRunner = fn (string $command) => ['exit_code' => 0, 'output' => ''];

        return new CodeTaskRunner($repo, $processRunner, $noOpCommandRunner);
    }

    public function test_requires_uuid_argument(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $runner = $this->makeRunner($repo);

        $command = new CodeTaskRunCommand($repo, $runner);
        $app = new Application;
        $app->add($command);

        $tester = new CommandTester($command);
        $this->expectException(RuntimeException::class);
        $tester->execute([]);
    }

    public function test_fails_when_task_not_found(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([]);

        $runner = $this->makeRunner($repo);

        $command = new CodeTaskRunCommand($repo, $runner);
        $app = new Application;
        $app->add($command);

        $tester = new CommandTester($command);
        $tester->execute(['uuid' => 'nonexistent']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function test_runs_task_successfully(): void
    {
        $task = new CodeTask([
            'uuid' => 'task-1',
            'workspace_uuid' => 'ws-1',
            'repo_uuid' => 'repo-1',
            'prompt' => 'Fix bug',
            'branch_name' => 'claudriel/fix-bug',
            'status' => 'queued',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$task]);

        $runner = $this->makeRunner($repo, fn () => [
            'exit_code' => 0,
            'output' => 'Done.',
        ]);

        $command = new CodeTaskRunCommand($repo, $runner);
        $app = new Application;
        $app->add($command);

        $tester = new CommandTester($command);
        $tester->execute(['uuid' => 'task-1']);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
