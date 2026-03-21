<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalWorkspaceController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
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

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function controller(): InternalWorkspaceController
    {
        return new InternalWorkspaceController($this->repo, $this->tokenGenerator, self::TENANT);
    }

    private function authenticatedRequest(string $uri): Request
    {
        $token = $this->tokenGenerator->generate('acct-123');
        $request = Request::create($uri);
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
