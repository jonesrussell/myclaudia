<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\PeopleApiController;
use Claudriel\Entity\Person;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class PeopleApiControllerTest extends TestCase
{
    public function test_people_crud_flow(): void
    {
        $controller = new PeopleApiController($this->buildEntityTypeManager());

        $create = $controller->create(
            httpRequest: Request::create('/api/people', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'jane@example.com',
                'name' => 'Jane',
                'latest_summary' => 'Lunch?',
                'last_interaction_at' => '2026-03-13T09:00:00-04:00',
            ], JSON_THROW_ON_ERROR)),
        );
        self::assertSame(201, $create->statusCode);
        $payload = json_decode($create->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('Jane', $payload['person']['name']);

        $list = $controller->list(query: ['email' => 'jane@example.com']);
        self::assertCount(1, json_decode($list->content, true, 512, JSON_THROW_ON_ERROR)['people']);

        $update = $controller->update(
            params: ['uuid' => $payload['person']['uuid']],
            httpRequest: Request::create('/api/people/'.$payload['person']['uuid'], 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'latest_summary' => 'Dinner instead?',
            ], JSON_THROW_ON_ERROR)),
        );
        self::assertSame('Dinner instead?', json_decode($update->content, true, 512, JSON_THROW_ON_ERROR)['person']['latest_summary']);

        $show = $controller->show(params: ['uuid' => $payload['person']['uuid']]);
        self::assertSame('jane@example.com', json_decode($show->content, true, 512, JSON_THROW_ON_ERROR)['person']['email']);

        $delete = $controller->delete(params: ['uuid' => $payload['person']['uuid']]);
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
            id: 'person',
            label: 'Person',
            class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        return $etm;
    }
}
