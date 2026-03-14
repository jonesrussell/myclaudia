<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Route;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\User\Middleware\CsrfMiddleware;

final class PublicSessionCsrfTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        $_SESSION = [];
        session_id('claudriel-csrf-'.bin2hex(random_bytes(4)));
        session_start();
    }

    protected function tearDown(): void
    {
        if (session_status() === \PHP_SESSION_ACTIVE) {
            session_unset();
            session_destroy();
        }

        $_SESSION = [];
    }

    public function test_login_route_requires_valid_csrf_token(): void
    {
        $middleware = new CsrfMiddleware;
        $route = (new Route('/login'))->setOption('_render', true);

        $invalid = Request::create('/login', 'POST', ['email' => 'test@example.com']);
        $invalid->attributes->set('_route_object', $route);

        $invalidResponse = $middleware->process($invalid, new class implements HttpHandlerInterface
        {
            public function handle(Request $request): Response
            {
                return new Response('ok', 200);
            }
        });
        self::assertSame(403, $invalidResponse->getStatusCode());

        $valid = Request::create('/login', 'POST', [
            'email' => 'test@example.com',
            '_csrf_token' => CsrfMiddleware::token(),
        ]);
        $valid->attributes->set('_route_object', $route);

        $validResponse = $middleware->process($valid, new class implements HttpHandlerInterface
        {
            public function handle(Request $request): Response
            {
                return new Response('ok', 200);
            }
        });
        self::assertSame(200, $validResponse->getStatusCode());
    }
}
