<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalSessionController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\ChatSession;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalSessionControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);
        $this->repo = new EntityRepository(
            new EntityType(
                id: 'chat_session',
                label: 'Chat Session',
                class: ChatSession::class,
                keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title'],
            ),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    public function test_rejects_unauthenticated(): void
    {
        $controller = $this->makeController();
        $request = Request::create('/api/internal/session/limits');

        $response = $controller->getLimits(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
    }

    public function test_limits_returns_default_turn_limits(): void
    {
        $controller = $this->makeController();
        $request = $this->authenticatedRequest('/api/internal/session/limits', 'acct-1');

        $response = $controller->getLimits(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertArrayHasKey('turn_limits', $data);
        self::assertSame(5, $data['turn_limits']['quick_lookup']);
        self::assertSame(15, $data['turn_limits']['email_compose']);
        self::assertSame(10, $data['turn_limits']['brief_generation']);
        self::assertSame(40, $data['turn_limits']['research']);
        self::assertSame(25, $data['turn_limits']['general']);
        self::assertSame(30, $data['turn_limits']['onboarding']);
        self::assertSame(500, $data['daily_ceiling']);
    }

    public function test_continue_grants_new_budget(): void
    {
        $session = new ChatSession([
            'title' => 'Test Session',
            'tenant_id' => 't1',
            'task_type' => 'general',
            'turns_consumed' => 20,
        ]);
        $this->repo->save($session);
        $uuid = $session->get('uuid');

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/session/'.$uuid.'/continue', 'acct-1');

        $response = $controller->continueSession(params: ['id' => $uuid], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame(1, $data['continued_count']);
        self::assertArrayHasKey('new_turn_budget', $data);
        self::assertGreaterThan(0, $data['new_turn_budget']);
    }

    public function test_continue_denied_at_daily_ceiling(): void
    {
        // Create a session with turns_consumed at the ceiling
        $session = new ChatSession([
            'title' => 'Heavy Session',
            'tenant_id' => 't1',
            'turns_consumed' => 500,
        ]);
        $this->repo->save($session);
        $uuid = $session->get('uuid');

        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/session/'.$uuid.'/continue', 'acct-1');

        $response = $controller->continueSession(params: ['id' => $uuid], httpRequest: $request);

        self::assertSame(429, $response->statusCode);
        self::assertStringContainsString('500', $response->content);
    }

    public function test_continue_not_found(): void
    {
        $controller = $this->makeController('t1');
        $request = $this->authenticatedRequest('/api/internal/session/nonexistent-uuid/continue', 'acct-1');

        $response = $controller->continueSession(params: ['id' => 'nonexistent-uuid'], httpRequest: $request);

        self::assertSame(404, $response->statusCode);
    }

    private function makeController(string $tenantId = 'default'): InternalSessionController
    {
        return new InternalSessionController(
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
}
