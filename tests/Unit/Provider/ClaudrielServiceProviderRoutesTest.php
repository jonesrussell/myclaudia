<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Provider;

use Claudriel\Provider\ClaudrielServiceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Route;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ClaudrielServiceProviderRoutesTest extends TestCase
{
    public function test_routes_register_public_homepage_and_authenticated_app_shell(): void
    {
        $provider = new ClaudrielServiceProvider;
        $provider->setKernelContext(dirname(__DIR__, 3), []);
        $provider->register();

        $router = new WaaseyaaRouter;
        $provider->routes($router);
        $routes = $router->getRouteCollection();

        $homepage = $routes->get('claudriel.homepage');
        $appShell = $routes->get('claudriel.app');

        self::assertInstanceOf(Route::class, $homepage);
        self::assertSame('/', $homepage->getPath());
        self::assertSame('Claudriel\\Controller\\PublicHomepageController::show', $homepage->getDefault('_controller'));

        self::assertInstanceOf(Route::class, $appShell);
        self::assertSame('/app', $appShell->getPath());
        self::assertSame('Claudriel\\Controller\\DashboardController::show', $appShell->getDefault('_controller'));
    }
}
