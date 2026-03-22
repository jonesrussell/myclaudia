<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Middleware;

use Claudriel\Middleware\TelescopeRequestMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;
use Waaseyaa\Telescope\TelescopeServiceProvider as WaaseyaaTelescopeServiceProvider;

final class TelescopeRequestMiddlewareTest extends TestCase
{
    public function test_middleware_records_request(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new WaaseyaaTelescopeServiceProvider(
            config: [
                'enabled' => true,
                'record' => ['requests' => true],
                'ignore_paths' => [],
            ],
            store: $store,
        );

        $middleware = new TelescopeRequestMiddleware($telescope);
        $middleware->recordRequest('GET', '/brief', 200, 42.5, 'DayBriefController');

        $entries = $store->query('request', 10);
        self::assertNotEmpty($entries);
        self::assertSame('request', $entries[0]->type);

        $data = $entries[0]->data;
        self::assertSame('GET', $data['method']);
        self::assertSame('/brief', $data['uri']);
        self::assertSame(200, $data['status_code']);
        self::assertSame(42.5, $data['duration']);
        self::assertSame('DayBriefController', $data['controller']);
    }

    public function test_middleware_wraps_request_lifecycle(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new WaaseyaaTelescopeServiceProvider(
            config: [
                'enabled' => true,
                'record' => ['requests' => true],
                'ignore_paths' => [],
            ],
            store: $store,
        );

        $middleware = new TelescopeRequestMiddleware($telescope);
        $request = Request::create('/brief', 'GET');

        $handler = new class implements HttpHandlerInterface
        {
            public function handle(Request $request): Response
            {
                return new Response('OK', 200);
            }
        };

        $response = $middleware->process($request, $handler);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('OK', $response->getContent());

        $entries = $store->query('request', 10);
        self::assertNotEmpty($entries);
        self::assertSame('GET', $entries[0]->data['method']);
        self::assertSame('/brief', $entries[0]->data['uri']);
        self::assertSame(200, $entries[0]->data['status_code']);
        self::assertGreaterThan(0.0, $entries[0]->data['duration']);
    }

    public function test_middleware_skips_when_telescope_disabled(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new WaaseyaaTelescopeServiceProvider(
            config: [
                'enabled' => false,
                'record' => ['requests' => true],
            ],
            store: $store,
        );

        $middleware = new TelescopeRequestMiddleware($telescope);
        $middleware->recordRequest('GET', '/brief', 200, 10.0);

        $entries = $store->query('request', 10);
        self::assertEmpty($entries);
    }

    public function test_middleware_respects_ignore_paths(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $telescope = new WaaseyaaTelescopeServiceProvider(
            config: [
                'enabled' => true,
                'record' => ['requests' => true],
                'ignore_paths' => ['/health'],
            ],
            store: $store,
        );

        $middleware = new TelescopeRequestMiddleware($telescope);
        $middleware->recordRequest('GET', '/health', 200, 1.0);

        $entries = $store->query('request', 10);
        self::assertEmpty($entries);
    }
}
