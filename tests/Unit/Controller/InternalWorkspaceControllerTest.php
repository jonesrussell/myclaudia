<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalWorkspaceController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalWorkspaceControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private const TENANT = 'test-tenant';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);
        $this->repo = new EntityRepository(
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    public function test_rejects_unauthenticated(): void
    {
        $controller = $this->controller();
        $request = Request::create('/api/internal/workspaces/list');

        $response = $controller->listWorkspaces(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Unauthorized', $response->content);
    }

    public function test_list_returns_tenant_scoped(): void
    {
        $this->seedWorkspace('ws-1', 'Project A', self::TENANT);
        $this->seedWorkspace('ws-2', 'Project B', 'other-tenant');

        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/workspaces/list');

        $response = $controller->listWorkspaces(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(1, $data['workspaces']);
        self::assertSame('ws-1', $data['workspaces'][0]['uuid']);
    }

    public function test_context_returns_workspace_details(): void
    {
        $this->seedWorkspace('ws-1', 'Project A', self::TENANT);

        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/workspaces/ws-1');

        $response = $controller->workspaceContext(params: ['uuid' => 'ws-1'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame('ws-1', $data['uuid']);
        self::assertSame('Project A', $data['name']);
        self::assertArrayHasKey('mode', $data);
        self::assertArrayHasKey('status', $data);
    }

    public function test_context_returns_404_for_missing(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/workspaces/nonexistent');

        $response = $controller->workspaceContext(params: ['uuid' => 'nonexistent'], httpRequest: $request);

        self::assertSame(404, $response->statusCode);
    }

    public function test_create_workspace(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/workspaces/create', ['name' => 'My Project', 'description' => 'A test workspace']);

        $response = $controller->create(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame('My Project', $data['name']);
        self::assertSame('active', $data['status']);
        self::assertSame('persistent', $data['mode']);
        self::assertArrayHasKey('uuid', $data);
        self::assertArrayHasKey('created_at', $data);
    }

    public function test_create_rejects_missing_name(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/workspaces/create', []);

        $response = $controller->create(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('name is required', $response->content);
    }

    public function test_create_rejects_unauthenticated(): void
    {
        $controller = $this->controller();
        $request = Request::create('/api/internal/workspaces/create', 'POST', content: json_encode(['name' => 'Test']));

        $response = $controller->create(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
    }

    public function test_delete_workspace(): void
    {
        $this->seedWorkspace('ws-del', 'To Delete', self::TENANT);

        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/workspaces/ws-del/delete', []);

        $response = $controller->delete(params: ['uuid' => 'ws-del'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertTrue($data['success']);

        // Verify it's gone
        $listResponse = $controller->listWorkspaces(httpRequest: $this->authenticatedRequest('/api/internal/workspaces/list'));
        $listData = json_decode($listResponse->content, true);
        self::assertCount(0, $listData['workspaces']);
    }

    public function test_delete_returns_404_for_missing(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/workspaces/nonexistent/delete', []);

        $response = $controller->delete(params: ['uuid' => 'nonexistent'], httpRequest: $request);

        self::assertSame(404, $response->statusCode);
    }

    public function test_clone_repo(): void
    {
        $this->seedWorkspace('ws-clone', 'Clone Target', self::TENANT);

        $clonedArgs = [];
        $mockRunner = function (string $command) use (&$clonedArgs): array {
            $clonedArgs[] = $command;

            return ['exit_code' => 0, 'output' => ''];
        };
        $gitManager = new GitRepositoryManager('/tmp/workspaces', $mockRunner);

        $controller = $this->controllerWithGit($gitManager);
        $request = $this->authenticatedPostRequest('/api/internal/workspaces/ws-clone/clone-repo', ['repo' => 'owner/repo-name', 'branch' => 'develop']);

        $response = $controller->cloneRepo(params: ['uuid' => 'ws-clone'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertTrue($data['success']);
        self::assertSame('develop', $data['branch']);
        self::assertSame('https://github.com/owner/repo-name.git', $data['repo_url']);
        self::assertStringContainsString('ws-clone', $data['local_path']);
    }

    public function test_clone_rejects_invalid_repo_format(): void
    {
        $this->seedWorkspace('ws-bad', 'Bad Clone', self::TENANT);

        $gitManager = new GitRepositoryManager('/tmp/workspaces', fn (string $cmd): array => ['exit_code' => 0, 'output' => '']);

        $controller = $this->controllerWithGit($gitManager);
        $request = $this->authenticatedPostRequest('/api/internal/workspaces/ws-bad/clone-repo', ['repo' => '../../../etc/passwd']);

        $response = $controller->cloneRepo(params: ['uuid' => 'ws-bad'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('Invalid repo format', $response->content);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function controller(): InternalWorkspaceController
    {
        return new InternalWorkspaceController($this->repo, $this->tokenGenerator, self::TENANT);
    }

    private function controllerWithGit(?GitRepositoryManager $gitManager = null): InternalWorkspaceController
    {
        return new InternalWorkspaceController($this->repo, $this->tokenGenerator, self::TENANT, $gitManager);
    }

    private function authenticatedRequest(string $uri): Request
    {
        $token = $this->tokenGenerator->generate('acct-123');
        $request = Request::create($uri);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private function authenticatedPostRequest(string $uri, array $body): Request
    {
        $token = $this->tokenGenerator->generate('acct-123');
        $request = Request::create($uri, 'POST', content: json_encode($body));
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private int $nextId = 1;

    private function seedWorkspace(string $uuid, string $name, string $tenantId): void
    {
        $workspace = new Workspace(['wid' => $this->nextId++, 'uuid' => $uuid, 'name' => $name, 'tenant_id' => $tenantId]);
        $this->repo->save($workspace);
    }
}
