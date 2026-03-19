<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalCommitmentController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\Commitment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalCommitmentControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);
        $this->repo = new EntityRepository(
            new EntityType(
                id: 'commitment',
                label: 'Commitment',
                class: Commitment::class,
                keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_rejects_unauthenticated(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/internal/commitments/list');

        $response = $controller->listCommitments(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Unauthorized', $response->content);
    }

    // -----------------------------------------------------------------------
    // listCommitments
    // -----------------------------------------------------------------------

    public function test_list_returns_tenant_scoped(): void
    {
        $c1 = new Commitment(['cid' => 1, 'title' => 'Tenant 1 task', 'tenant_id' => 't1', 'status' => 'active']);
        $c2 = new Commitment(['cid' => 2, 'title' => 'Tenant 2 task', 'tenant_id' => 't2', 'status' => 'active']);
        $this->repo->save($c1);
        $this->repo->save($c2);

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/commitments/list', 'acct-1');

        $response = $controller->listCommitments(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame(1, $data['count']);
        self::assertSame('Tenant 1 task', $data['commitments'][0]['title']);
    }

    public function test_list_filters_by_status(): void
    {
        $c1 = new Commitment(['cid' => 1, 'title' => 'Active task', 'tenant_id' => 't1', 'status' => 'active']);
        $c2 = new Commitment(['cid' => 2, 'title' => 'Pending task', 'tenant_id' => 't1', 'status' => 'pending']);
        $c3 = new Commitment(['cid' => 3, 'title' => 'Completed task', 'tenant_id' => 't1', 'status' => 'completed']);
        $this->repo->save($c1);
        $this->repo->save($c2);
        $this->repo->save($c3);

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/commitments/list', 'acct-1');

        $response = $controller->listCommitments(query: ['status' => 'active'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame(1, $data['count']);
        self::assertSame('Active task', $data['commitments'][0]['title']);
    }

    public function test_list_respects_limit(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repo->save(new Commitment([
                'cid' => $i,
                'title' => "Task {$i}",
                'tenant_id' => 't1',
                'status' => 'active',
            ]));
        }

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/commitments/list', 'acct-1');

        $response = $controller->listCommitments(query: ['limit' => '2'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame(2, $data['count']);
    }

    // -----------------------------------------------------------------------
    // updateCommitment
    // -----------------------------------------------------------------------

    public function test_update_changes_status(): void
    {
        $commitment = new Commitment(['cid' => 1, 'title' => 'My task', 'tenant_id' => 't1', 'status' => 'active']);
        $this->repo->save($commitment);
        $uuid = $commitment->get('uuid');

        $controller = $this->makeController('t1');
        $request = $this->authenticatedPostRequest(
            "/api/internal/commitments/{$uuid}/update",
            'acct-1',
            ['status' => 'completed'],
        );

        $response = $controller->updateCommitment(params: ['uuid' => $uuid], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame('completed', $data['status']);
    }

    public function test_update_returns_404_for_missing(): void
    {
        $controller = $this->makeController('t1');
        $request = $this->authenticatedPostRequest(
            '/api/internal/commitments/nonexistent-uuid/update',
            'acct-1',
            ['status' => 'completed'],
        );

        $response = $controller->updateCommitment(params: ['uuid' => 'nonexistent-uuid'], httpRequest: $request);

        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('not found', $response->content);
    }

    public function test_update_rejects_wrong_tenant(): void
    {
        $commitment = new Commitment(['cid' => 1, 'title' => 'Other tenant task', 'tenant_id' => 't2', 'status' => 'active']);
        $this->repo->save($commitment);
        $uuid = $commitment->get('uuid');

        $controller = $this->makeController('t1');
        $request = $this->authenticatedPostRequest(
            "/api/internal/commitments/{$uuid}/update",
            'acct-1',
            ['status' => 'completed'],
        );

        $response = $controller->updateCommitment(params: ['uuid' => $uuid], httpRequest: $request);

        self::assertSame(404, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function makeController(string $tenantId = 'default'): InternalCommitmentController
    {
        return new InternalCommitmentController(
            $this->repo,
            $this->tokenGenerator,
            $tenantId,
        );
    }

    private function authenticatedRequest(string $uri, string $accountId): Request
    {
        $token = $this->tokenGenerator->generate($accountId);
        $request = Request::create($uri);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private function authenticatedPostRequest(string $uri, string $accountId, array $body): Request
    {
        $token = $this->tokenGenerator->generate($accountId);
        $request = Request::create($uri, 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }
}
