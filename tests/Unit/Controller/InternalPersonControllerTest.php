<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalPersonController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InternalPersonControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private const TENANT = 'test-tenant';

    private InternalApiTokenGenerator $tokenGenerator;

    private EntityRepository $personRepo;

    private EntityRepository $commitmentRepo;

    private int $personIdCounter = 0;

    private int $commitmentIdCounter = 0;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);

        $this->personRepo = new EntityRepository(
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );

        $this->commitmentRepo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_rejects_unauthenticated(): void
    {
        $controller = $this->controller();
        $request = Request::create('/api/internal/persons/search');

        $response = $controller->searchPersons(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Unauthorized', $response->content);
    }

    // -----------------------------------------------------------------------
    // Search
    // -----------------------------------------------------------------------

    public function test_search_by_name(): void
    {
        $this->savePerson(['name' => 'Alice Smith', 'email' => 'alice@example.com', 'tenant_id' => self::TENANT]);
        $this->savePerson(['name' => 'Bob Jones', 'email' => 'bob@example.com', 'tenant_id' => self::TENANT]);

        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/persons/search?name=alice');

        $response = $controller->searchPersons(query: ['name' => 'alice'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(1, $data['persons']);
        self::assertSame('Alice Smith', $data['persons'][0]['name']);
    }

    public function test_search_by_email(): void
    {
        $this->savePerson(['name' => 'Alice Smith', 'email' => 'alice@example.com', 'tenant_id' => self::TENANT]);
        $this->savePerson(['name' => 'Bob Jones', 'email' => 'bob@other.com', 'tenant_id' => self::TENANT]);

        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/persons/search?email=other.com');

        $response = $controller->searchPersons(query: ['email' => 'other.com'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(1, $data['persons']);
        self::assertSame('Bob Jones', $data['persons'][0]['name']);
    }

    public function test_search_tenant_scoped(): void
    {
        $this->savePerson(['name' => 'Alice Smith', 'email' => 'alice@example.com', 'tenant_id' => self::TENANT]);
        $this->savePerson(['name' => 'Eve Other', 'email' => 'eve@example.com', 'tenant_id' => 'other-tenant']);

        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/persons/search');

        $response = $controller->searchPersons(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(1, $data['persons']);
        self::assertSame('Alice Smith', $data['persons'][0]['name']);
    }

    // -----------------------------------------------------------------------
    // Detail
    // -----------------------------------------------------------------------

    public function test_detail_returns_person(): void
    {
        $person = $this->savePerson(['name' => 'Alice Smith', 'email' => 'alice@example.com', 'tenant_id' => self::TENANT]);
        $uuid = $person->get('uuid');

        $controller = $this->controller();
        $request = $this->authenticatedRequest("/api/internal/persons/{$uuid}");

        $response = $controller->personDetail(params: ['uuid' => $uuid], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertSame('Alice Smith', $data['person']['name']);
        self::assertSame('alice@example.com', $data['person']['email']);
    }

    public function test_detail_includes_related_commitments(): void
    {
        $person = $this->savePerson(['name' => 'Alice Smith', 'email' => 'alice@example.com', 'tenant_id' => self::TENANT]);
        $uuid = $person->get('uuid');

        $commitment = new Commitment([
            'cid' => ++$this->commitmentIdCounter,
            'title' => 'Send proposal',
            'person_uuid' => $uuid,
            'tenant_id' => self::TENANT,
            'status' => 'active',
        ]);
        $this->commitmentRepo->save($commitment);

        $controller = $this->controller();
        $request = $this->authenticatedRequest("/api/internal/persons/{$uuid}");

        $response = $controller->personDetail(params: ['uuid' => $uuid], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true);
        self::assertCount(1, $data['commitments']);
        self::assertSame('Send proposal', $data['commitments'][0]['title']);
    }

    public function test_detail_returns_404_for_missing(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/persons/nonexistent-uuid');

        $response = $controller->personDetail(params: ['uuid' => 'nonexistent-uuid'], httpRequest: $request);

        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('not found', $response->content);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function controller(): InternalPersonController
    {
        return new InternalPersonController(
            $this->personRepo,
            $this->commitmentRepo,
            $this->tokenGenerator,
            self::TENANT,
        );
    }

    private function authenticatedRequest(string $uri): Request
    {
        $request = Request::create($uri);
        $token = $this->tokenGenerator->generate('test-account');
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private function savePerson(array $values): Person
    {
        $values['pid'] = ++$this->personIdCounter;
        $person = new Person($values);
        $this->personRepo->save($person);

        return $person;
    }
}
