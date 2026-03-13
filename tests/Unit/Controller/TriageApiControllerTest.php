<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\TriageApiController;
use Claudriel\Entity\TriageEntry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class TriageApiControllerTest extends TestCase
{
    public function test_triage_crud_flow(): void
    {
        $controller = new TriageApiController($this->buildEntityTypeManager());

        $create = $controller->create(
            httpRequest: Request::create('/api/triage', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'sender_name' => 'Unknown Sender',
                'sender_email' => 'unknown@example.com',
                'summary' => 'Partnership opportunity',
            ], JSON_THROW_ON_ERROR)),
        );
        self::assertSame(201, $create->statusCode);
        $payload = json_decode($create->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('open', $payload['triage']['status']);

        $list = $controller->list(query: ['status' => 'open']);
        self::assertCount(1, json_decode($list->content, true, 512, JSON_THROW_ON_ERROR)['triage']);

        $update = $controller->update(
            params: ['uuid' => $payload['triage']['uuid']],
            httpRequest: Request::create('/api/triage/'.$payload['triage']['uuid'], 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'status' => 'resolved',
            ], JSON_THROW_ON_ERROR)),
        );
        self::assertSame('resolved', json_decode($update->content, true, 512, JSON_THROW_ON_ERROR)['triage']['status']);

        $show = $controller->show(params: ['uuid' => $payload['triage']['uuid']]);
        self::assertSame('Unknown Sender', json_decode($show->content, true, 512, JSON_THROW_ON_ERROR)['triage']['sender_name']);

        $delete = $controller->delete(params: ['uuid' => $payload['triage']['uuid']]);
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
            id: 'triage_entry',
            label: 'Triage Entry',
            class: TriageEntry::class,
            keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name'],
        ));

        return $etm;
    }
}
