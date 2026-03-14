<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\WorkspaceApiController;
use Claudriel\Entity\Account;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class WorkspaceApiControllerTest extends TestCase
{
    public function test_workspace_crud_is_scoped_to_tenant(): void
    {
        $controller = new WorkspaceApiController($this->buildEntityTypeManager());
        $createRequest = Request::create(
            '/api/workspaces',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TENANT_ID' => 'tenant-a'],
            content: json_encode(['name' => 'Workspace A'], JSON_THROW_ON_ERROR),
        );

        $create = $controller->create(httpRequest: $createRequest);
        self::assertSame(201, $create->statusCode);
        $payload = json_decode($create->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('tenant-a', $payload['workspace']['tenant_id']);

        $list = $controller->list(query: [], account: 'tenant-a');
        self::assertSame(1, count(json_decode($list->content, true, 512, JSON_THROW_ON_ERROR)['workspaces']));

        $show = $controller->show(params: ['uuid' => $payload['workspace']['uuid']], query: ['tenant_id' => 'tenant-a']);
        self::assertSame(200, $show->statusCode);
    }

    public function test_workspace_show_fails_closed_for_cross_tenant_access(): void
    {
        $controller = new WorkspaceApiController($this->buildEntityTypeManager());
        $create = $controller->create(httpRequest: Request::create(
            '/api/workspaces',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TENANT_ID' => 'tenant-a'],
            content: json_encode(['name' => 'Workspace A'], JSON_THROW_ON_ERROR),
        ));
        $payload = json_decode($create->content, true, 512, JSON_THROW_ON_ERROR);

        $show = $controller->show(params: ['uuid' => $payload['workspace']['uuid']], query: ['tenant_id' => 'tenant-b']);
        self::assertSame(404, $show->statusCode);
    }

    public function test_workspace_read_routes_return_403_for_authenticated_tenant_mismatch(): void
    {
        $controller = new WorkspaceApiController($this->buildEntityTypeManager());
        $create = $controller->create(httpRequest: Request::create(
            '/api/workspaces',
            'POST',
            server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TENANT_ID' => 'tenant-a'],
            content: json_encode(['name' => 'Workspace A'], JSON_THROW_ON_ERROR),
        ));
        $payload = json_decode($create->content, true, 512, JSON_THROW_ON_ERROR);
        $account = new AuthenticatedAccount(new Account([
            'aid' => 99,
            'uuid' => 'account-auth-1',
            'email' => 'auth@example.com',
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
            'tenant_id' => 'tenant-a',
        ]));

        $list = $controller->list(query: ['tenant_id' => 'tenant-b'], account: $account);
        self::assertSame(403, $list->statusCode);

        $show = $controller->show(params: ['uuid' => $payload['workspace']['uuid']], query: ['tenant_id' => 'tenant-b'], account: $account);
        self::assertSame(403, $show->statusCode);
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
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        return $etm;
    }
}
