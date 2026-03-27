<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\CodeTask;

use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Entity\CodeTask;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CodeTaskRunnerTest extends TestCase
{
    public function test_run_sets_status_to_running(): void
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
        ]);

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
        ]);

        $runner->run($task, '/tmp/test-repo');

        $this->assertSame('failed', $task->get('status'));
        $this->assertStringContainsString('something went wrong', (string) $task->get('error'));
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
}
