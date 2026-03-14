<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\PublicHomepageController;
use Claudriel\Entity\Account;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class PublicHomepageControllerTest extends TestCase
{
    public function test_show_returns_json_payload_without_twig(): void
    {
        $response = (new PublicHomepageController)->show();

        self::assertSame(200, $response->statusCode);
        $data = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('/signup', $data['primary_cta_href']);
        self::assertSame('/login', $data['secondary_cta_href']);
        self::assertStringContainsString('Run your day', $data['headline']);
    }

    public function test_show_renders_marketing_homepage_with_signup_and_login_ctas(): void
    {
        $controller = new PublicHomepageController(
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
        );

        $response = $controller->show();

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Create your account', $response->content);
        self::assertStringContainsString('href="/signup"', $response->content);
        self::assertStringContainsString('href="/login"', $response->content);
        self::assertStringContainsString('Schedule Intelligence For Real Work', $response->content);
        self::assertStringContainsString('Public Entry Surface', $response->content);
    }

    public function test_show_redirects_authenticated_accounts_into_app_shell(): void
    {
        $account = new Account([
            'name' => 'Signed In User',
            'email' => 'signed-in@example.com',
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
            'tenant_id' => 'tenant-123',
        ]);

        $response = (new PublicHomepageController)->show(account: new AuthenticatedAccount($account));

        self::assertSame(302, $response->getStatusCode());
        self::assertSame('/app?tenant_id=tenant-123', $response->headers->get('Location'));
    }
}
