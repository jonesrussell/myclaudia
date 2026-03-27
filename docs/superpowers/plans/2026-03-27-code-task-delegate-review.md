# Code Task: Delegate & Review — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Enable users to ask Claudriel in chat to make code changes to a GitHub repo, with Claudriel spawning Claude Code CLI to do the work and creating a PR.

**Architecture:** New `CodeTask` entity tracks lifecycle (queued → running → completed/failed). `CodeTaskRunner` service orchestrates: clone repo, create branch, invoke Claude Code CLI, parse output, push, create PR. Two new agent tools (`code_task_create`, `code_task_status`) expose this through chat. Ansible roles install Claude Code CLI and GitHub CLI on the server.

**Tech Stack:** PHP 8.4 (Waaseyaa framework), Python 3 (agent tools), Ansible, Claude Code CLI, GitHub CLI (`gh`)

**Spec:** `docs/superpowers/specs/2026-03-27-code-task-delegate-review-design.md`

**Issues:** #572, #573, #574, #575, #576, #577

---

## File Structure

| File | Action | Purpose |
|---|---|---|
| `src/Entity/CodeTask.php` | Create | CodeTask entity |
| `src/Provider/CodeTaskServiceProvider.php` | Create | Entity registration + DI wiring |
| `src/Domain/CodeTask/CodeTaskRunner.php` | Create | Orchestrates Claude Code invocation |
| `src/Command/CodeTaskRunCommand.php` | Create | Background CLI command |
| `src/Controller/InternalCodeTaskController.php` | Create | Internal API endpoints |
| `src/Provider/ClaudrielServiceProvider.php` | Modify | Register CodeTaskServiceProvider |
| `agent/tools/code_task_create.py` | Create | Agent tool: create code task |
| `agent/tools/code_task_status.py` | Create | Agent tool: check task status |
| `tests/Unit/Entity/CodeTaskTest.php` | Create | Entity unit test |
| `tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php` | Create | Runner unit test |
| `tests/Unit/Command/CodeTaskRunCommandTest.php` | Create | CLI command test |
| `tests/Unit/Controller/InternalCodeTaskControllerTest.php` | Create | Controller test |
| `agent/tests/test_code_task_tools.py` | Create | Agent tool tests |
| `~/dev/northcloud-ansible/roles/claude-code/tasks/main.yml` | Create | Ansible: install Claude Code CLI |
| `~/dev/northcloud-ansible/roles/claude-code/defaults/main.yml` | Create | Ansible: role defaults |
| `~/dev/northcloud-ansible/roles/github-cli/tasks/main.yml` | Create | Ansible: install gh CLI |
| `~/dev/northcloud-ansible/roles/github-cli/defaults/main.yml` | Create | Ansible: role defaults |
| `~/dev/northcloud-ansible/playbooks/site.yml` | Modify | Add new roles |

---

## Task 1: CodeTask Entity (#572)

**Files:**
- Create: `src/Entity/CodeTask.php`
- Create: `tests/Unit/Entity/CodeTaskTest.php`

- [ ] **Step 1: Write the entity test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\CodeTask;
use PHPUnit\Framework\TestCase;

final class CodeTaskTest extends TestCase
{
    public function testConstructDefaults(): void
    {
        $task = new CodeTask(['workspace_uuid' => 'ws-1', 'repo_uuid' => 'repo-1', 'prompt' => 'Fix the bug']);

        $this->assertSame('queued', $task->get('status'));
        $this->assertSame('ws-1', $task->get('workspace_uuid'));
        $this->assertSame('repo-1', $task->get('repo_uuid'));
        $this->assertSame('Fix the bug', $task->get('prompt'));
        $this->assertNull($task->get('pr_url'));
        $this->assertNull($task->get('summary'));
        $this->assertNull($task->get('diff_preview'));
        $this->assertNull($task->get('error'));
        $this->assertNull($task->get('started_at'));
        $this->assertNull($task->get('completed_at'));
    }

    public function testConstructWithExplicitStatus(): void
    {
        $task = new CodeTask(['status' => 'running']);

        $this->assertSame('running', $task->get('status'));
    }

    public function testBranchNameGeneration(): void
    {
        $task = new CodeTask(['branch_name' => 'claudriel/fix-login']);

        $this->assertSame('claudriel/fix-login', $task->get('branch_name'));
    }

    public function testEntityTypeId(): void
    {
        $task = new CodeTask();
        $this->assertSame('code_task', $task->getEntityTypeId());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Entity/CodeTaskTest.php`
Expected: FAIL — class `Claudriel\Entity\CodeTask` not found

- [ ] **Step 3: Create the entity**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class CodeTask extends ContentEntityBase
{
    protected string $entityTypeId = 'code_task';

    protected array $entityKeys = [
        'id' => 'ctid',
        'uuid' => 'uuid',
        'label' => 'prompt',
    ];

    public function __construct(array $values = [])
    {
        if (! array_key_exists('status', $values)) {
            $values['status'] = 'queued';
        }
        if (! array_key_exists('pr_url', $values)) {
            $values['pr_url'] = null;
        }
        if (! array_key_exists('summary', $values)) {
            $values['summary'] = null;
        }
        if (! array_key_exists('diff_preview', $values)) {
            $values['diff_preview'] = null;
        }
        if (! array_key_exists('error', $values)) {
            $values['error'] = null;
        }
        if (! array_key_exists('claude_output', $values)) {
            $values['claude_output'] = null;
        }
        if (! array_key_exists('started_at', $values)) {
            $values['started_at'] = null;
        }
        if (! array_key_exists('completed_at', $values)) {
            $values['completed_at'] = null;
        }

        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Unit/Entity/CodeTaskTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Entity/CodeTask.php tests/Unit/Entity/CodeTaskTest.php
git commit -m "feat(#572): add CodeTask entity with defaults and tests"
```

---

## Task 2: CodeTaskServiceProvider (#572)

**Files:**
- Create: `src/Provider/CodeTaskServiceProvider.php`
- Modify: `src/Provider/ClaudrielServiceProvider.php`

- [ ] **Step 1: Create the service provider**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Command\CodeTaskRunCommand;
use Claudriel\Controller\InternalCodeTaskController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\CodeTask;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Storage\SqlEntityStorage;
use Waaseyaa\Entity\Storage\StorageRepositoryAdapter;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class CodeTaskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(CodeTaskRunner::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManagerInterface::class);
            $database = $this->resolve(DatabaseInterface::class);
            $dispatcher = $this->resolve(EventDispatcherInterface::class);
            $entityType = $entityTypeManager->getDefinition('code_task');
            $storage = new SqlEntityStorage($entityType, $database, $dispatcher);
            $repo = new StorageRepositoryAdapter($storage);

            return new CodeTaskRunner(
                $repo,
                new GitRepositoryManager,
            );
        });

        $this->singleton(InternalCodeTaskController::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManagerInterface::class);
            $database = $this->resolve(DatabaseInterface::class);
            $dispatcher = $this->resolve(EventDispatcherInterface::class);

            $codeTaskType = $entityTypeManager->getDefinition('code_task');
            $codeTaskStorage = new SqlEntityStorage($codeTaskType, $database, $dispatcher);
            $codeTaskRepo = new StorageRepositoryAdapter($codeTaskStorage);

            $workspaceType = $entityTypeManager->getDefinition('workspace');
            $workspaceStorage = new SqlEntityStorage($workspaceType, $database, $dispatcher);
            $workspaceRepo = new StorageRepositoryAdapter($workspaceStorage);

            $repoType = $entityTypeManager->getDefinition('repo');
            $repoStorage = new SqlEntityStorage($repoType, $database, $dispatcher);
            $repoRepo = new StorageRepositoryAdapter($repoStorage);

            $workspaceRepoType = $entityTypeManager->getDefinition('workspace_repo');
            $workspaceRepoStorage = new SqlEntityStorage($workspaceRepoType, $database, $dispatcher);
            $workspaceRepoRepo = new StorageRepositoryAdapter($workspaceRepoStorage);

            return new InternalCodeTaskController(
                $codeTaskRepo,
                $workspaceRepo,
                $repoRepo,
                $workspaceRepoRepo,
                $this->resolve(InternalApiTokenGenerator::class),
                $this->resolve(CodeTaskRunner::class),
                new GitRepositoryManager,
            );
        });

        $this->entityType(new EntityType(
            id: 'code_task',
            label: 'Code Task',
            class: CodeTask::class,
            keys: ['id' => 'ctid', 'uuid' => 'uuid', 'label' => 'prompt'],
            fieldDefinitions: [
                'ctid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'repo_uuid' => ['type' => 'string', 'required' => true],
                'prompt' => ['type' => 'text_long', 'required' => true],
                'status' => ['type' => 'string'],
                'branch_name' => ['type' => 'string'],
                'pr_url' => ['type' => 'string'],
                'summary' => ['type' => 'text_long'],
                'diff_preview' => ['type' => 'text_long'],
                'error' => ['type' => 'text_long'],
                'claude_output' => ['type' => 'text_long'],
                'started_at' => ['type' => 'string'],
                'completed_at' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }

    public function commands(
        EntityTypeManager $entityTypeManager,
        DatabaseInterface $database,
        EventDispatcherInterface $dispatcher,
    ): array {
        $entityType = $entityTypeManager->getDefinition('code_task');
        $storage = new SqlEntityStorage($entityType, $database, $dispatcher);
        $repo = new StorageRepositoryAdapter($storage);

        return [
            new CodeTaskRunCommand(
                $repo,
                $this->resolve(CodeTaskRunner::class),
            ),
        ];
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $create = RouteBuilder::create('/api/internal/code-tasks/create')
            ->controller(InternalCodeTaskController::class.'::create')
            ->allowAll()
            ->methods('POST')
            ->build();
        $create->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.code_tasks.create', $create);

        $status = RouteBuilder::create('/api/internal/code-tasks/{uuid}/status')
            ->controller(InternalCodeTaskController::class.'::status')
            ->allowAll()
            ->methods('GET')
            ->build();
        $status->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.code_tasks.status', $status);
    }
}
```

- [ ] **Step 2: Register the service provider in ClaudrielServiceProvider**

In `src/Provider/ClaudrielServiceProvider.php`, find the list of service provider registrations and add:

```php
$this->app->register(new CodeTaskServiceProvider($this->app));
```

Add the import at the top:

```php
use Claudriel\Provider\CodeTaskServiceProvider;
```

- [ ] **Step 3: Verify the app boots without errors**

Run: `php public/router.php` (or `php -S localhost:8081 -t public` briefly)
Expected: No fatal errors on boot

- [ ] **Step 4: Commit**

```bash
git add src/Provider/CodeTaskServiceProvider.php src/Provider/ClaudrielServiceProvider.php
git commit -m "feat(#572): add CodeTaskServiceProvider with entity registration and route wiring"
```

---

## Task 3: CodeTaskRunner Service (#573)

**Files:**
- Create: `src/Domain/CodeTask/CodeTaskRunner.php`
- Create: `tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php`

- [ ] **Step 1: Write the runner test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\CodeTask;

use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\CodeTask;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CodeTaskRunnerTest extends TestCase
{
    public function testRunSetsStatusToRunning(): void
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

        $gitManager = $this->createMock(GitRepositoryManager::class);

        $runner = new CodeTaskRunner($repo, $gitManager, fn () => [
            'exit_code' => 0,
            'output' => json_encode([
                'result' => 'I fixed the login bug by updating the session handler.',
            ]),
        ]);

        $runner->run($task, '/tmp/test-repo');

        $this->assertSame('completed', $task->get('status'));
        $this->assertNotNull($task->get('completed_at'));
    }

    public function testRunSetsStatusToFailedOnNonZeroExit(): void
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

        $gitManager = $this->createMock(GitRepositoryManager::class);

        $runner = new CodeTaskRunner($repo, $gitManager, fn () => [
            'exit_code' => 1,
            'output' => 'Error: something went wrong',
        ]);

        $runner->run($task, '/tmp/test-repo');

        $this->assertSame('failed', $task->get('status'));
        $this->assertStringContainsString('something went wrong', (string) $task->get('error'));
    }

    public function testGenerateBranchName(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $gitManager = $this->createMock(GitRepositoryManager::class);
        $runner = new CodeTaskRunner($repo, $gitManager);

        $result = $runner->generateBranchName('Fix the login bug in auth module!');
        $this->assertSame('claudriel/fix-the-login-bug-in-auth-module', $result);
    }

    public function testGenerateBranchNameTruncates(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $gitManager = $this->createMock(GitRepositoryManager::class);
        $runner = new CodeTaskRunner($repo, $gitManager);

        $longPrompt = str_repeat('a very long prompt that goes on ', 5);
        $result = $runner->generateBranchName($longPrompt);
        $this->assertStringStartsWith('claudriel/', $result);
        $this->assertLessThanOrEqual(60, strlen($result));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php`
Expected: FAIL — class `Claudriel\Domain\CodeTask\CodeTaskRunner` not found

- [ ] **Step 3: Write the CodeTaskRunner**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Domain\CodeTask;

use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\CodeTask;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CodeTaskRunner
{
    private const MAX_DIFF_LINES = 200;

    private const DIFF_TRUNCATE_AT = 150;

    private const TIMEOUT_SECONDS = 600;

    /** @var null|callable(string,string): array{exit_code:int,output:string} */
    private readonly mixed $processRunner;

    public function __construct(
        private readonly EntityRepositoryInterface $codeTaskRepo,
        private readonly GitRepositoryManager $gitManager,
        ?callable $processRunner = null,
    ) {
        $this->processRunner = $processRunner;
    }

    public function run(CodeTask $task, string $repoPath): void
    {
        $task->set('status', 'running');
        $task->set('started_at', date('c'));
        $this->codeTaskRepo->save($task);

        try {
            $this->prepareWorkingBranch($repoPath, (string) $task->get('branch_name'));
            $result = $this->invokeClaudeCode($repoPath, (string) $task->get('prompt'));

            $exitCode = (int) ($result['exit_code'] ?? 1);
            $output = (string) ($result['output'] ?? '');

            $task->set('claude_output', mb_substr($output, 0, 50000));

            if ($exitCode !== 0) {
                $task->set('status', 'failed');
                $task->set('error', mb_substr($output, 0, 5000));
                $task->set('completed_at', date('c'));
                $this->codeTaskRepo->save($task);

                return;
            }

            $diff = $this->captureDiff($repoPath);
            if ($diff === '') {
                $task->set('status', 'completed');
                $task->set('summary', 'No changes were needed.');
                $task->set('diff_preview', '');
                $task->set('completed_at', date('c'));
                $this->codeTaskRepo->save($task);

                return;
            }

            $task->set('diff_preview', $this->truncateDiff($diff));
            $task->set('summary', $this->extractSummary($output));

            $this->pushAndCreatePr($repoPath, $task);

            $task->set('status', 'completed');
            $task->set('completed_at', date('c'));
            $this->codeTaskRepo->save($task);
        } catch (\Throwable $e) {
            $task->set('status', 'failed');
            $task->set('error', $e->getMessage());
            $task->set('completed_at', date('c'));
            $this->codeTaskRepo->save($task);
        }
    }

    public function generateBranchName(string $prompt): string
    {
        $slug = strtolower(trim($prompt));
        $slug = (string) preg_replace('/[^a-z0-9\s-]/', '', $slug);
        $slug = (string) preg_replace('/[\s-]+/', '-', $slug);
        $slug = trim($slug, '-');

        if (strlen($slug) > 49) {
            $slug = substr($slug, 0, 49);
            $slug = (string) preg_replace('/-[^-]*$/', '', $slug);
        }

        return 'claudriel/' . $slug;
    }

    private function prepareWorkingBranch(string $repoPath, string $branchName): void
    {
        $this->shellExec(sprintf(
            'git -C %s checkout -b %s',
            escapeshellarg($repoPath),
            escapeshellarg($branchName),
        ));
    }

    private function invokeClaudeCode(string $repoPath, string $prompt): array
    {
        if ($this->processRunner !== null) {
            return ($this->processRunner)($repoPath, $prompt);
        }

        $command = sprintf(
            'cd %s && timeout %d claude --print --output-format stream-json --allowedTools "Edit,Write,Read,Glob,Grep,Bash" --max-turns 30 -p %s 2>&1',
            escapeshellarg($repoPath),
            self::TIMEOUT_SECONDS,
            escapeshellarg($prompt),
        );

        $marker = '__CLAUDRIEL_EXIT__';
        $raw = shell_exec($command . '; printf "\n' . $marker . '%s" "$?"');

        if ($raw === null) {
            return ['exit_code' => 1, 'output' => 'shell_exec returned null'];
        }

        $pos = strrpos($raw, $marker);
        if ($pos === false) {
            return ['exit_code' => 1, 'output' => trim($raw)];
        }

        return [
            'exit_code' => (int) trim(substr($raw, $pos + strlen($marker))),
            'output' => trim(substr($raw, 0, $pos)),
        ];
    }

    private function captureDiff(string $repoPath): string
    {
        $result = $this->shellExecSafe(sprintf(
            'git -C %s diff HEAD~1 2>/dev/null || git -C %s diff --cached 2>/dev/null || echo ""',
            escapeshellarg($repoPath),
            escapeshellarg($repoPath),
        ));

        return trim($result);
    }

    private function truncateDiff(string $diff): string
    {
        $lines = explode("\n", $diff);
        $total = count($lines);

        if ($total <= self::MAX_DIFF_LINES) {
            return $diff;
        }

        $truncated = array_slice($lines, 0, self::DIFF_TRUNCATE_AT);
        $remaining = $total - self::DIFF_TRUNCATE_AT;

        return implode("\n", $truncated) . "\n\n... and {$remaining} more lines. See full diff on GitHub.";
    }

    private function extractSummary(string $output): string
    {
        $lines = explode("\n", $output);
        $lastLines = array_slice($lines, -20);

        return mb_substr(implode("\n", $lastLines), 0, 2000);
    }

    private function pushAndCreatePr(string $repoPath, CodeTask $task): void
    {
        $branchName = (string) $task->get('branch_name');
        $prompt = (string) $task->get('prompt');
        $summary = (string) ($task->get('summary') ?? $prompt);

        $this->shellExec(sprintf(
            'git -C %s push origin %s',
            escapeshellarg($repoPath),
            escapeshellarg($branchName),
        ));

        $prTitle = mb_substr($prompt, 0, 70);
        $prBody = "## Summary\n\n" . $summary . "\n\n---\nCreated by Claudriel Code Task";

        $prOutput = $this->shellExecSafe(sprintf(
            'cd %s && gh pr create --title %s --body %s 2>&1',
            escapeshellarg($repoPath),
            escapeshellarg($prTitle),
            escapeshellarg($prBody),
        ));

        $prUrl = trim($prOutput);
        if (str_starts_with($prUrl, 'https://')) {
            $task->set('pr_url', $prUrl);
        }
    }

    private function shellExec(string $command): void
    {
        $output = shell_exec($command . ' 2>&1');
        // Fire and forget for git operations — errors caught by caller
    }

    private function shellExecSafe(string $command): string
    {
        $output = shell_exec($command);

        return is_string($output) ? trim($output) : '';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Domain/CodeTask/CodeTaskRunner.php tests/Unit/Domain/CodeTask/CodeTaskRunnerTest.php
git commit -m "feat(#573): add CodeTaskRunner service with branch naming and CLI invocation"
```

---

## Task 4: CodeTaskRunCommand (#574)

**Files:**
- Create: `src/Command/CodeTaskRunCommand.php`
- Create: `tests/Unit/Command/CodeTaskRunCommandTest.php`

- [ ] **Step 1: Write the command test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\CodeTaskRunCommand;
use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\CodeTask;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CodeTaskRunCommandTest extends TestCase
{
    public function testRequiresUuidArgument(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $runner = $this->createMock(CodeTaskRunner::class);

        $command = new CodeTaskRunCommand($repo, $runner);
        $app = new Application;
        $app->add($command);

        $tester = new CommandTester($command);
        $this->expectException(\Symfony\Component\Console\Exception\RuntimeException::class);
        $tester->execute([]);
    }

    public function testFailsWhenTaskNotFound(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([]);

        $runner = $this->createMock(CodeTaskRunner::class);

        $command = new CodeTaskRunCommand($repo, $runner);
        $app = new Application;
        $app->add($command);

        $tester = new CommandTester($command);
        $tester->execute(['uuid' => 'nonexistent']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testRunsTaskSuccessfully(): void
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

        $runner = $this->createMock(CodeTaskRunner::class);
        $runner->expects($this->once())->method('run');

        $command = new CodeTaskRunCommand($repo, $runner);
        $app = new Application;
        $app->add($command);

        $tester = new CommandTester($command);
        $tester->execute(['uuid' => 'task-1']);

        $this->assertSame(0, $tester->getStatusCode());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Command/CodeTaskRunCommandTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\CodeTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:code-task:run', description: 'Execute a queued code task via Claude Code CLI')]
final class CodeTaskRunCommand extends Command
{
    public function __construct(
        private readonly EntityRepositoryInterface $codeTaskRepo,
        private readonly CodeTaskRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'Code task UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = (string) $input->getArgument('uuid');

        $tasks = $this->codeTaskRepo->findBy(['uuid' => $uuid]);
        if ($tasks === []) {
            $output->writeln('<error>Code task not found: ' . $uuid . '</error>');

            return Command::FAILURE;
        }

        $task = $tasks[0];
        if (! $task instanceof CodeTask) {
            $output->writeln('<error>Code task not found: ' . $uuid . '</error>');

            return Command::FAILURE;
        }

        $gitManager = new GitRepositoryManager;
        $workspaceUuid = (string) $task->get('workspace_uuid');
        $repoPath = $gitManager->buildWorkspaceRepoPath($workspaceUuid);

        $output->writeln(sprintf('Running code task %s against %s...', $uuid, $repoPath));

        $this->runner->run($task, $repoPath);

        $output->writeln(sprintf('Task %s finished with status: %s', $uuid, (string) $task->get('status')));

        return $task->get('status') === 'completed' ? Command::SUCCESS : Command::FAILURE;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Command/CodeTaskRunCommandTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Command/CodeTaskRunCommand.php tests/Unit/Command/CodeTaskRunCommandTest.php
git commit -m "feat(#574): add claudriel:code-task:run CLI command"
```

---

## Task 5: InternalCodeTaskController (#575)

**Files:**
- Create: `src/Controller/InternalCodeTaskController.php`
- Create: `tests/Unit/Controller/InternalCodeTaskControllerTest.php`

- [ ] **Step 1: Write the controller test**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalCodeTaskController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\CodeTask;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceRepo;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class InternalCodeTaskControllerTest extends TestCase
{
    private function makeController(
        array $codeTaskEntities = [],
        array $workspaceEntities = [],
        array $repoEntities = [],
        array $workspaceRepoEntities = [],
        bool $validToken = true,
    ): InternalCodeTaskController {
        $codeTaskRepo = $this->createMock(EntityRepositoryInterface::class);
        $codeTaskRepo->method('findBy')->willReturn($codeTaskEntities);

        $workspaceRepo = $this->createMock(EntityRepositoryInterface::class);
        $workspaceRepo->method('findBy')->willReturn($workspaceEntities);

        $repoRepo = $this->createMock(EntityRepositoryInterface::class);
        $repoRepo->method('findBy')->willReturn($repoEntities);

        $wsRepoRepo = $this->createMock(EntityRepositoryInterface::class);
        $wsRepoRepo->method('findBy')->willReturn($workspaceRepoEntities);

        $tokenGen = $this->createMock(InternalApiTokenGenerator::class);
        $tokenGen->method('validate')->willReturn($validToken ? 'account-1' : null);

        $runner = $this->createMock(CodeTaskRunner::class);
        $runner->method('generateBranchName')->willReturn('claudriel/test-branch');

        $gitManager = $this->createMock(GitRepositoryManager::class);

        return new InternalCodeTaskController(
            $codeTaskRepo,
            $workspaceRepo,
            $repoRepo,
            $wsRepoRepo,
            $tokenGen,
            $runner,
            $gitManager,
        );
    }

    public function testCreateRejectsUnauthenticated(): void
    {
        $controller = $this->makeController(validToken: false);
        $request = Request::create('/api/internal/code-tasks/create', 'POST', [], [], [], [], '{}');

        $response = $controller->create([], [], null, $request);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testCreateRequiresRepoAndPrompt(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/internal/code-tasks/create', 'POST', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ], json_encode([]));

        $response = $controller->create([], [], null, $request);
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testStatusReturnsTaskData(): void
    {
        $task = new CodeTask([
            'uuid' => 'task-1',
            'status' => 'completed',
            'summary' => 'Fixed the bug',
            'pr_url' => 'https://github.com/test/repo/pull/1',
            'diff_preview' => '--- a/file.php\n+++ b/file.php',
        ]);

        $controller = $this->makeController(codeTaskEntities: [$task]);
        $request = Request::create('/api/internal/code-tasks/task-1/status', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer valid-token',
        ]);

        $response = $controller->status(['uuid' => 'task-1'], [], null, $request);
        $this->assertSame(200, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertSame('completed', $data['status']);
        $this->assertSame('Fixed the bug', $data['summary']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Unit/Controller/InternalCodeTaskControllerTest.php`
Expected: FAIL — class not found

- [ ] **Step 3: Write the controller**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\CodeTask;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceRepo;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalCodeTaskController
{
    public function __construct(
        private readonly EntityRepositoryInterface $codeTaskRepo,
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly EntityRepositoryInterface $repoRepo,
        private readonly EntityRepositoryInterface $workspaceRepoRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly CodeTaskRunner $runner,
        private readonly GitRepositoryManager $gitManager,
    ) {}

    public function create(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $repoFullName = (string) ($body['repo'] ?? '');
        $prompt = (string) ($body['prompt'] ?? '');
        if ($repoFullName === '' || $prompt === '') {
            return $this->jsonError('repo and prompt are required', 400);
        }

        $tenantId = $this->resolveTenantId($httpRequest);

        // Find or create workspace + repo
        $workspaceUuid = $this->resolveOrCreateWorkspace($repoFullName, $tenantId);
        if ($workspaceUuid === null) {
            return $this->jsonError('Failed to set up workspace for repo', 500);
        }

        $repoUuid = $this->resolveRepoUuid($repoFullName, $tenantId);
        if ($repoUuid === null) {
            return $this->jsonError('Failed to resolve repo', 500);
        }

        $branchName = (string) ($body['branch_name'] ?? '');
        if ($branchName === '') {
            $branchName = $this->runner->generateBranchName($prompt);
        }

        $task = new CodeTask([
            'workspace_uuid' => $workspaceUuid,
            'repo_uuid' => $repoUuid,
            'prompt' => $prompt,
            'status' => 'queued',
            'branch_name' => $branchName,
            'tenant_id' => $tenantId,
        ]);
        $this->codeTaskRepo->save($task);

        $taskUuid = (string) $task->get('uuid');

        // Dispatch background command
        $consolePath = dirname(__DIR__, 2) . '/bin/console';
        $cmd = sprintf(
            'php %s claudriel:code-task:run %s > /dev/null 2>&1 &',
            escapeshellarg($consolePath),
            escapeshellarg($taskUuid),
        );
        exec($cmd);

        return $this->jsonResponse([
            'task_uuid' => $taskUuid,
            'status' => 'queued',
            'branch_name' => $branchName,
        ]);
    }

    public function status(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = (string) ($params['uuid'] ?? '');
        if ($uuid === '') {
            return $this->jsonError('Task UUID required', 400);
        }

        $tasks = $this->codeTaskRepo->findBy(['uuid' => $uuid]);
        if ($tasks === []) {
            return $this->jsonError('Code task not found', 404);
        }

        $task = $tasks[0];
        if (! $task instanceof CodeTask) {
            return $this->jsonError('Code task not found', 404);
        }

        return $this->jsonResponse([
            'uuid' => $task->get('uuid'),
            'status' => $task->get('status'),
            'branch_name' => $task->get('branch_name'),
            'pr_url' => $task->get('pr_url'),
            'summary' => $task->get('summary'),
            'diff_preview' => $task->get('diff_preview'),
            'error' => $task->get('error'),
            'started_at' => $task->get('started_at'),
            'completed_at' => $task->get('completed_at'),
        ]);
    }

    private function resolveOrCreateWorkspace(string $repoFullName, string $tenantId): ?string
    {
        // Check if we have a repo entity for this full name
        $repos = $this->repoRepo->findBy(['full_name' => $repoFullName, 'tenant_id' => $tenantId]);
        if ($repos !== []) {
            $repo = $repos[0];
            if ($repo instanceof Repo) {
                // Find linked workspace
                $links = $this->workspaceRepoRepo->findBy(['repo_uuid' => $repo->get('uuid')]);
                foreach ($links as $link) {
                    if ($link instanceof WorkspaceRepo) {
                        return (string) $link->get('workspace_uuid');
                    }
                }
            }
        }

        // Create workspace for this repo
        $workspace = new Workspace([
            'name' => $repoFullName,
            'description' => 'Auto-created for code tasks on ' . $repoFullName,
            'tenant_id' => $tenantId,
            'status' => 'active',
        ]);
        $this->workspaceRepo->save($workspace);
        $wsUuid = (string) $workspace->get('uuid');

        // Clone the repo
        $repoUrl = 'https://github.com/' . $repoFullName . '.git';
        $localPath = $this->gitManager->buildWorkspaceRepoPath($wsUuid);
        $this->gitManager->clone($repoUrl, $localPath);

        // Create repo entity
        $parts = explode('/', $repoFullName, 2);
        $repoEntity = new Repo([
            'owner' => $parts[0] ?? '',
            'name' => $parts[1] ?? '',
            'full_name' => $repoFullName,
            'default_branch' => 'main',
            'local_path' => $localPath,
            'tenant_id' => $tenantId,
        ]);
        $this->repoRepo->save($repoEntity);

        // Link workspace to repo
        $link = new WorkspaceRepo([
            'workspace_uuid' => $wsUuid,
            'repo_uuid' => (string) $repoEntity->get('uuid'),
            'is_active' => true,
        ]);
        $this->workspaceRepoRepo->save($link);

        return $wsUuid;
    }

    private function resolveRepoUuid(string $repoFullName, string $tenantId): ?string
    {
        $repos = $this->repoRepo->findBy(['full_name' => $repoFullName, 'tenant_id' => $tenantId]);
        if ($repos === []) {
            return null;
        }

        $repo = $repos[0];

        return $repo instanceof Repo ? (string) $repo->get('uuid') : null;
    }

    private function resolveTenantId(mixed $httpRequest): string
    {
        if ($httpRequest instanceof Request) {
            $headerTenant = $httpRequest->headers->get('X-Tenant-Id', '');
            if ($headerTenant !== '') {
                return $headerTenant;
            }
        }

        return 'default';
    }

    private function authenticate(mixed $httpRequest): ?string
    {
        $auth = '';
        if ($httpRequest instanceof Request) {
            $auth = $httpRequest->headers->get('Authorization', '');
        }

        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return $this->apiTokenGenerator->validate(substr($auth, 7));
    }

    private function getRequestBody(mixed $httpRequest): ?array
    {
        if (! $httpRequest instanceof Request) {
            return null;
        }
        $content = $httpRequest->getContent();
        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private function jsonResponse(array $data): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function jsonError(string $message, int $statusCode): SsrResponse
    {
        return new SsrResponse(
            content: json_encode(['error' => $message], JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Controller/InternalCodeTaskControllerTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add src/Controller/InternalCodeTaskController.php tests/Unit/Controller/InternalCodeTaskControllerTest.php
git commit -m "feat(#575): add InternalCodeTaskController with create and status endpoints"
```

---

## Task 6: Agent Tools (#576)

**Files:**
- Create: `agent/tools/code_task_create.py`
- Create: `agent/tools/code_task_status.py`
- Create: `agent/tests/test_code_task_tools.py`

- [ ] **Step 1: Write the agent tool tests**

```python
"""Tests for code_task_create and code_task_status tools."""

import pytest
from unittest.mock import MagicMock

from agent.tools.code_task_create import TOOL_DEF as CREATE_DEF, execute as create_execute
from agent.tools.code_task_status import TOOL_DEF as STATUS_DEF, execute as status_execute


class TestCodeTaskCreateDef:
    def test_has_required_fields(self):
        assert CREATE_DEF["name"] == "code_task_create"
        schema = CREATE_DEF["input_schema"]
        assert "repo" in schema["properties"]
        assert "prompt" in schema["properties"]
        assert "repo" in schema["required"]
        assert "prompt" in schema["required"]


class TestCodeTaskStatusDef:
    def test_has_required_fields(self):
        assert STATUS_DEF["name"] == "code_task_status"
        schema = STATUS_DEF["input_schema"]
        assert "task_uuid" in schema["properties"]
        assert "task_uuid" in schema["required"]


class TestCodeTaskCreateExecute:
    def test_rejects_invalid_repo_format(self):
        api = MagicMock()
        result = create_execute(api, {"repo": "invalid", "prompt": "fix bug"})
        assert "error" in result
        api.post.assert_not_called()

    def test_calls_api(self):
        api = MagicMock()
        api.post.return_value = {"task_uuid": "abc-123", "status": "queued"}

        result = create_execute(api, {"repo": "owner/name", "prompt": "fix the bug"})
        api.post.assert_called_once_with(
            "/api/internal/code-tasks/create",
            json_data={"repo": "owner/name", "prompt": "fix the bug"},
        )
        assert result["task_uuid"] == "abc-123"


class TestCodeTaskStatusExecute:
    def test_calls_api(self):
        api = MagicMock()
        api.get.return_value = {"status": "completed", "pr_url": "https://github.com/test/pull/1"}

        result = status_execute(api, {"task_uuid": "abc-123"})
        api.get.assert_called_once_with("/api/internal/code-tasks/abc-123/status")
        assert result["status"] == "completed"
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd agent && python -m pytest tests/test_code_task_tools.py -v`
Expected: FAIL — module not found

- [ ] **Step 3: Create code_task_create.py**

```python
"""Tool: Create a code task to make changes to a GitHub repository."""

import re

TOOL_DEF = {
    "name": "code_task_create",
    "description": (
        "Create a code task that spawns Claude Code to make changes to a GitHub repository. "
        "Claudriel will clone the repo (if not already in a workspace), create a branch, "
        "run Claude Code with your prompt, and create a pull request. "
        "Use code_task_status to check progress."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "repo": {
                "type": "string",
                "description": "Repository in owner/name format (e.g., 'jonesrussell/my-repo').",
                "pattern": "^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$",
            },
            "prompt": {
                "type": "string",
                "description": "Instructions for what changes to make (e.g., 'Fix the login bug in the auth handler').",
            },
            "branch_name": {
                "type": "string",
                "description": "Optional branch name override. Auto-generated from prompt if not provided.",
            },
        },
        "required": ["repo", "prompt"],
    },
}


def execute(api, args: dict) -> dict:
    repo = args["repo"]
    if not re.match(r"^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$", repo):
        return {"error": f"Invalid repo format: {repo!r}. Expected owner/name."}

    prompt = args.get("prompt", "")
    if not prompt.strip():
        return {"error": "prompt is required."}

    payload = {"repo": repo, "prompt": prompt}

    branch_name = args.get("branch_name")
    if branch_name:
        payload["branch_name"] = branch_name

    return api.post("/api/internal/code-tasks/create", json_data=payload)
```

- [ ] **Step 4: Create code_task_status.py**

```python
"""Tool: Check the status of a code task."""

TOOL_DEF = {
    "name": "code_task_status",
    "description": (
        "Check the status of a previously created code task. "
        "Returns the current status (queued, running, completed, failed), "
        "and when completed: a summary of changes, diff preview, and PR URL."
    ),
    "input_schema": {
        "type": "object",
        "properties": {
            "task_uuid": {
                "type": "string",
                "description": "UUID of the code task to check.",
            },
        },
        "required": ["task_uuid"],
    },
}


def execute(api, args: dict) -> dict:
    task_uuid = args["task_uuid"]
    return api.get(f"/api/internal/code-tasks/{task_uuid}/status")
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd agent && python -m pytest tests/test_code_task_tools.py -v`
Expected: PASS (5 tests)

- [ ] **Step 6: Commit**

```bash
git add agent/tools/code_task_create.py agent/tools/code_task_status.py agent/tests/test_code_task_tools.py
git commit -m "feat(#576): add code_task_create and code_task_status agent tools"
```

---

## Task 7: Ansible — Claude Code CLI Role (#577)

**Files:**
- Create: `~/dev/northcloud-ansible/roles/claude-code/tasks/main.yml`
- Create: `~/dev/northcloud-ansible/roles/claude-code/defaults/main.yml`

- [ ] **Step 1: Create role defaults**

```yaml
---
claude_code_version: "latest"
```

- [ ] **Step 2: Create role tasks**

```yaml
---
- name: Install Claude Code CLI globally via npm
  community.general.npm:
    name: "@anthropic-ai/claude-code"
    global: true
    state: present
  environment:
    PATH: "/usr/bin:{{ ansible_env.PATH }}"

- name: Verify Claude Code CLI installation
  ansible.builtin.command:
    cmd: claude --version
  register: claude_version_result
  changed_when: false

- name: Display Claude Code version
  ansible.builtin.debug:
    msg: "Claude Code CLI version: {{ claude_version_result.stdout }}"
```

- [ ] **Step 3: Commit in northcloud-ansible**

```bash
cd ~/dev/northcloud-ansible
git add roles/claude-code/
git commit -m "feat: add claude-code role for Claude Code CLI installation"
```

---

## Task 8: Ansible — GitHub CLI Role (#577)

**Files:**
- Create: `~/dev/northcloud-ansible/roles/github-cli/tasks/main.yml`
- Create: `~/dev/northcloud-ansible/roles/github-cli/defaults/main.yml`
- Modify: `~/dev/northcloud-ansible/playbooks/site.yml`

- [ ] **Step 1: Create role defaults**

```yaml
---
github_cli_token: "{{ vault_claudriel_github_token | default('') }}"
github_cli_user: "deployer"
```

- [ ] **Step 2: Create role tasks**

```yaml
---
- name: Download GitHub CLI GPG key
  ansible.builtin.get_url:
    url: https://cli.github.com/packages/githubcli-archive-keyring.gpg
    dest: /usr/share/keyrings/githubcli-archive-keyring.gpg
    mode: "0644"

- name: Add GitHub CLI repository
  ansible.builtin.apt_repository:
    repo: "deb [arch=amd64 signed-by=/usr/share/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main"
    filename: github-cli
    state: present

- name: Install GitHub CLI
  ansible.builtin.apt:
    name: gh
    state: present
    update_cache: true

- name: Verify GitHub CLI installation
  ansible.builtin.command:
    cmd: gh --version
  register: gh_version_result
  changed_when: false

- name: Display GitHub CLI version
  ansible.builtin.debug:
    msg: "GitHub CLI version: {{ gh_version_result.stdout_lines[0] }}"

- name: Authenticate GitHub CLI for deployer user
  ansible.builtin.shell:
    cmd: echo "{{ github_cli_token }}" | gh auth login --with-token
  become: true
  become_user: "{{ github_cli_user }}"
  when: github_cli_token != ''
  no_log: true
  changed_when: true
```

- [ ] **Step 3: Add roles to site.yml**

In `~/dev/northcloud-ansible/playbooks/site.yml`, in the webservers play, add after the `node` role line:

```yaml
    - { role: github-cli, tags: [github-cli] }
    - { role: claude-code, tags: [claude-code] }
```

So the webservers roles section becomes:

```yaml
  roles:
    - { role: common, tags: [common] }
    - { role: caddy, tags: [caddy, web] }
    - { role: php, tags: [php, web] }
    # redis is provided by Docker (north-cloud stack), not system package
    - { role: mariadb, tags: [mariadb, db] }
    - { role: node, tags: [node] }
    - { role: github-cli, tags: [github-cli] }
    - { role: claude-code, tags: [claude-code] }
    - { role: laravel-app, tags: [laravel-app, apps] }
    - { role: waaseyaa-app, tags: [waaseyaa-app, apps] }
    - { role: north-cloud, tags: [north-cloud, docker] }
```

- [ ] **Step 4: Commit in northcloud-ansible**

```bash
cd ~/dev/northcloud-ansible
git add roles/github-cli/ playbooks/site.yml
git commit -m "feat: add github-cli role and wire claude-code + github-cli into site.yml"
```

---

## Task 9: Integration Smoke Test

- [ ] **Step 1: Run the full Claudriel test suite**

Run: `vendor/bin/phpunit`
Expected: All existing tests still pass, plus new tests pass

- [ ] **Step 2: Run PHPStan**

Run: `vendor/bin/phpstan analyse`
Expected: No new errors (regenerate baseline if needed with `--generate-baseline`)

- [ ] **Step 3: Run agent tests**

Run: `cd agent && python -m pytest tests/ -v`
Expected: All tests pass including new code_task tool tests

- [ ] **Step 4: Verify Ansible syntax**

Run: `cd ~/dev/northcloud-ansible && ansible-playbook playbooks/site.yml --syntax-check`
Expected: Playbook syntax is OK

- [ ] **Step 5: Final commit (if any fixes needed)**

```bash
git add -A
git commit -m "chore: fix integration issues from code task feature"
```
