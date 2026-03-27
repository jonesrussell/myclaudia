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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalCodeTaskControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $codeTaskRepo;

    private EntityRepository $workspaceRepo;

    private EntityRepository $repoRepo;

    private EntityRepository $workspaceRepoRepo;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);

        $this->codeTaskRepo = new EntityRepository(
            new EntityType(
                id: 'code_task',
                label: 'Code Task',
                class: CodeTask::class,
                keys: ['id' => 'ctid', 'uuid' => 'uuid', 'label' => 'prompt'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );

        $this->workspaceRepo = new EntityRepository(
            new EntityType(
                id: 'workspace',
                label: 'Workspace',
                class: Workspace::class,
                keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );

        $this->repoRepo = new EntityRepository(
            new EntityType(
                id: 'repo',
                label: 'Repo',
                class: Repo::class,
                keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'name'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );

        $this->workspaceRepoRepo = new EntityRepository(
            new EntityType(
                id: 'workspace_repo',
                label: 'Workspace Repo',
                class: WorkspaceRepo::class,
                keys: ['id' => 'wrid', 'uuid' => 'uuid'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    public function test_create_rejects_unauthenticated(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/internal/code-tasks/create', 'POST', content: '{}');

        $response = $controller->create([], [], null, $request);
        self::assertSame(401, $response->statusCode);
    }

    public function test_create_requires_repo_and_prompt(): void
    {
        $controller = $this->makeController();
        $request = $this->authenticatedPostRequest(
            '/api/internal/code-tasks/create',
            'acct-1',
            [],
        );

        $response = $controller->create([], [], null, $request);
        self::assertSame(400, $response->statusCode);

        $data = json_decode($response->content, true);
        self::assertStringContainsString('repo and prompt are required', $data['error']);
    }

    public function test_status_rejects_unauthenticated(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/internal/code-tasks/task-1/status', 'GET');

        $response = $controller->status(['uuid' => 'task-1'], [], null, $request);
        self::assertSame(401, $response->statusCode);
    }

    public function test_status_returns_not_found_for_missing_task(): void
    {
        $controller = $this->makeController();
        $token = $this->tokenGenerator->generate('acct-1');
        $request = Request::create('/api/internal/code-tasks/nonexistent/status', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$token);

        $response = $controller->status(['uuid' => 'nonexistent'], [], null, $request);
        self::assertSame(404, $response->statusCode);
    }

    public function test_status_returns_task_data(): void
    {
        $task = new CodeTask([
            'ctid' => 1,
            'uuid' => 'task-1',
            'workspace_uuid' => 'ws-1',
            'repo_uuid' => 'repo-1',
            'prompt' => 'Fix the bug',
            'status' => 'completed',
            'summary' => 'Fixed the bug',
            'pr_url' => 'https://github.com/test/repo/pull/1',
            'diff_preview' => '--- a/file.php',
            'tenant_id' => 'default',
        ]);
        $this->codeTaskRepo->save($task);

        $controller = $this->makeController();
        $token = $this->tokenGenerator->generate('acct-1');
        $request = Request::create('/api/internal/code-tasks/task-1/status', 'GET');
        $request->headers->set('Authorization', 'Bearer '.$token);

        $response = $controller->status(['uuid' => 'task-1'], [], null, $request);
        self::assertSame(200, $response->statusCode);

        $data = json_decode($response->content, true);
        self::assertSame('completed', $data['status']);
        self::assertSame('Fixed the bug', $data['summary']);
        self::assertSame('https://github.com/test/repo/pull/1', $data['pr_url']);
    }

    private function makeController(): InternalCodeTaskController
    {
        $runner = new CodeTaskRunner(
            $this->codeTaskRepo,
            fn () => ['exit_code' => 0, 'output' => ''],
            fn () => ['exit_code' => 0, 'output' => ''],
        );

        $gitManager = new GitRepositoryManager(
            '/tmp/claudriel-test-workspaces',
            fn () => ['exit_code' => 0, 'output' => ''],
        );

        return new InternalCodeTaskController(
            $this->codeTaskRepo,
            $this->workspaceRepo,
            $this->repoRepo,
            $this->workspaceRepoRepo,
            $this->tokenGenerator,
            $runner,
            $gitManager,
        );
    }

    private function authenticatedPostRequest(string $uri, string $accountId, array $body = []): Request
    {
        $token = $this->tokenGenerator->generate($accountId);
        $content = $body !== [] ? json_encode($body, JSON_THROW_ON_ERROR) : '{}';
        $request = Request::create($uri, 'POST', content: $content);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }
}
