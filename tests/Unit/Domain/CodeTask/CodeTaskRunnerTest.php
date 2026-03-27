<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\CodeTask;

use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Entity\CodeTask;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CodeTaskRunnerTest extends TestCase
{
    /** No-op command runner for tests — all shell commands (git, gh) succeed silently. */
    private static function noOpCommandRunner(): \Closure
    {
        return fn (string $command) => ['exit_code' => 0, 'output' => ''];
    }

    public function test_run_completes_successfully(): void
    {
        $task = new CodeTask([
            'uuid' => 'task-1',
            'workspace_uuid' => 'ws-1',
            'repo_uuid' => 'repo-1',
            'prompt' => 'Fix the bug',
            'branch_name' => 'claudriel/fix-the-bug',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->expects($this->atLeastOnce())->method('save');

        $runner = new CodeTaskRunner($repo, fn () => [
            'exit_code' => 0,
            'output' => json_encode([
                'result' => 'I fixed the login bug by updating the session handler.',
            ]),
        ], self::noOpCommandRunner());

        $runner->run($task, '/tmp/test-repo');

        $this->assertSame('completed', $task->get('status'));
        $this->assertNotNull($task->get('completed_at'));
    }

    public function test_run_sets_status_to_failed_on_non_zero_exit(): void
    {
        $task = new CodeTask([
            'uuid' => 'task-1',
            'workspace_uuid' => 'ws-1',
            'repo_uuid' => 'repo-1',
            'prompt' => 'Fix the bug',
            'branch_name' => 'claudriel/fix-the-bug',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->expects($this->atLeastOnce())->method('save');

        $runner = new CodeTaskRunner($repo, fn () => [
            'exit_code' => 1,
            'output' => 'Error: something went wrong',
        ], self::noOpCommandRunner());

        $runner->run($task, '/tmp/test-repo');

        $this->assertSame('failed', $task->get('status'));
        $this->assertStringContainsString('something went wrong', (string) $task->get('error'));
        $this->assertNotNull($task->get('completed_at'));
    }

    public function test_generate_branch_name(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $runner = new CodeTaskRunner($repo);

        $result = $runner->generateBranchName('Fix the login bug in auth module!');
        $this->assertSame('claudriel/fix-the-login-bug-in-auth-module', $result);
    }

    public function test_generate_branch_name_truncates(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $runner = new CodeTaskRunner($repo);

        $longPrompt = str_repeat('a very long prompt that goes on ', 5);
        $result = $runner->generateBranchName($longPrompt);
        $this->assertStringStartsWith('claudriel/', $result);
        $this->assertLessThanOrEqual(60, strlen($result));
    }

    public function test_run_fails_when_process_runner_throws(): void
    {
        $task = new CodeTask([
            'uuid' => 'task-1',
            'workspace_uuid' => 'ws-1',
            'repo_uuid' => 'repo-1',
            'prompt' => 'Fix the bug',
            'branch_name' => 'claudriel/fix-the-bug',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->expects($this->atLeastOnce())->method('save');

        $runner = new CodeTaskRunner($repo, function () {
            throw new \RuntimeException('Command failed (exit 128): fatal: not a git repository');
        }, self::noOpCommandRunner());

        $runner->run($task, '/tmp/test-repo');

        $this->assertSame('failed', $task->get('status'));
        $this->assertStringContainsString('not a git repository', (string) $task->get('error'));
        $this->assertNotNull($task->get('completed_at'));
    }

    public function test_run_fails_when_git_command_fails(): void
    {
        $task = new CodeTask([
            'uuid' => 'task-1',
            'workspace_uuid' => 'ws-1',
            'repo_uuid' => 'repo-1',
            'prompt' => 'Fix the bug',
            'branch_name' => 'claudriel/fix-the-bug',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->expects($this->atLeastOnce())->method('save');

        $runner = new CodeTaskRunner($repo, fn () => [
            'exit_code' => 0,
            'output' => 'Done',
        ], fn () => ['exit_code' => 128, 'output' => 'fatal: cannot create branch']);

        $runner->run($task, '/tmp/test-repo');

        $this->assertSame('failed', $task->get('status'));
        $this->assertStringContainsString('cannot create branch', (string) $task->get('error'));
    }
}
