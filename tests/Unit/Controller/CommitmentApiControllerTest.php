<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\CommitmentApiController;
use Claudriel\Entity\Commitment;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class CommitmentApiControllerTest extends TestCase
{
    public function test_commitment_crud_flow(): void
    {
        $controller = new CommitmentApiController($this->buildEntityTypeManager());

        $create = $controller->create(
            httpRequest: Request::create('/api/commitments', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'title' => 'Follow up with Bob',
                'status' => 'pending',
                'confidence' => 0.8,
                'due_date' => '2026-03-15',
            ], JSON_THROW_ON_ERROR)),
        );
        self::assertSame(201, $create->statusCode);
        $payload = json_decode($create->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Follow up with Bob', $payload['commitment']['title']);

        $list = $controller->list(query: ['status' => 'pending']);
        self::assertCount(1, json_decode($list->content, true, 512, JSON_THROW_ON_ERROR)['commitments']);

        $update = $controller->update(
            params: ['uuid' => $payload['commitment']['uuid']],
            httpRequest: Request::create('/api/commitments/'.$payload['commitment']['uuid'], 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'status' => 'done',
                'confidence' => 0.95,
            ], JSON_THROW_ON_ERROR)),
        );
        $updated = json_decode($update->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('done', $updated['commitment']['status']);
        self::assertSame(0.95, $updated['commitment']['confidence']);

        $show = $controller->show(params: ['uuid' => $payload['commitment']['uuid']]);
        self::assertSame('done', json_decode($show->content, true, 512, JSON_THROW_ON_ERROR)['commitment']['status']);

        $delete = $controller->delete(params: ['uuid' => $payload['commitment']['uuid']]);
        self::assertSame(200, $delete->statusCode);
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;

        $etm = new EntityTypeManager($dispatcher, function ($definition) use ($db, $dispatcher): SqlEntityStorage {
            (new SqlSchemaHandler($definition, $db))->ensureTable();

            return new SqlEntityStorage($definition, $db, $dispatcher);
        });

        $etm->registerEntityType(new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        ));

        return $etm;
    }
}
