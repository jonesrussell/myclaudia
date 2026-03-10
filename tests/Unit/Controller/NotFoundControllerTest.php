<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\NotFoundController;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class NotFoundControllerTest extends TestCase
{
    public function testReturns404WithTwigTemplate(): void
    {
        $twig = new Environment(new ArrayLoader([
            '404.html.twig' => '<h1>Not Found</h1><p>{{ path }}</p>',
        ]));
        $controller = new NotFoundController($twig);

        $response = $controller->show(['path' => 'nonexistent-page']);

        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('Not Found', $response->content);
        self::assertStringContainsString('/nonexistent-page', $response->content);
    }

    public function testReturns404WithoutTwig(): void
    {
        $controller = new NotFoundController();

        $response = $controller->show(['path' => 'missing']);

        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('Not Found', $response->content);
        self::assertStringContainsString('/missing', $response->content);
    }

    public function testFallsBackToPlainHtmlOnTwigError(): void
    {
        // Twig environment with no templates loaded, so rendering will fail.
        $twig = new Environment(new ArrayLoader([]));
        $controller = new NotFoundController($twig);

        $response = $controller->show(['path' => 'broken']);

        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('Not Found', $response->content);
        self::assertStringContainsString('/broken', $response->content);
    }

    public function testNormalizesPathWithLeadingSlash(): void
    {
        $controller = new NotFoundController();

        $response = $controller->show(['path' => '/already-slashed']);

        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('/already-slashed', $response->content);
        // Should not double-slash.
        self::assertStringNotContainsString('//already-slashed', $response->content);
    }

    public function testHandlesEmptyPath(): void
    {
        $controller = new NotFoundController();

        $response = $controller->show([]);

        self::assertSame(404, $response->statusCode);
        self::assertStringContainsString('/', $response->content);
    }

    public function testEscapesHtmlInPath(): void
    {
        $controller = new NotFoundController();

        $response = $controller->show(['path' => '<script>alert(1)</script>']);

        self::assertSame(404, $response->statusCode);
        self::assertStringNotContainsString('<script>', $response->content);
        self::assertStringContainsString('&lt;script&gt;', $response->content);
    }
}
