<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\CommitmentUpdateController;
use Claudriel\Entity\Commitment;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class CommitmentUpdateControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;
    private CommitmentUpdateController $controller;

    protected function setUp(): void
    {
        $db         = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $type       = new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        );

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($db, $dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $db))->ensureTable();
                return new SqlEntityStorage($definition, $db, $dispatcher);
            },
        );
        $this->entityTypeManager->registerEntityType($type);

        $this->controller = new CommitmentUpdateController($this->entityTypeManager);
    }

    private function saveCommitment(string $uuid): void
    {
        $c = new Commitment(['title' => 'Test', 'status' => 'pending', 'uuid' => $uuid]);
        $this->entityTypeManager->getStorage('commitment')->save($c);
    }

    private function call(string $uuid, string $body): \Waaseyaa\SSR\SsrResponse
    {
        $httpRequest = Request::create('/commitments/' . $uuid, 'PATCH', [], [], [], [], $body);
        return $this->controller->update(
            params: ['uuid' => $uuid],
            query: [],
            account: null,
            httpRequest: $httpRequest,
        );
    }

    public function testUpdateStatusToDone(): void
    {
        $uuid = 'bbbbbbbb-0001-0001-0001-bbbbbbbbbbbb';
        $this->saveCommitment($uuid);

        $response = $this->call($uuid, json_encode(['status' => 'done']));

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->content, true);
        self::assertSame('done', $body['status']);
        self::assertSame($uuid, $body['uuid']);
    }

    public function testReturns404ForUnknownUuid(): void
    {
        $response = $this->call('no-such-uuid', json_encode(['status' => 'done']));
        self::assertSame(404, $response->statusCode);
    }

    public function testReturns422ForInvalidStatus(): void
    {
        $uuid = 'bbbbbbbb-0002-0002-0002-bbbbbbbbbbbb';
        $this->saveCommitment($uuid);

        $response = $this->call($uuid, json_encode(['status' => 'exploded']));
        self::assertSame(422, $response->statusCode);
    }
}
