<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Admin;

use Claudriel\Admin\Host\ClaudrielAdminHost;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;

#[CoversClass(ClaudrielAdminHost::class)]
final class ClaudrielAdminHostTest extends TestCase
{
    private function host(): ClaudrielAdminHost
    {
        $etm = $this->createMock(EntityTypeManager::class);

        return new ClaudrielAdminHost($etm);
    }

    #[Test]
    public function sanitize_redirect_allows_localhost_absolute_url(): void
    {
        $h = $this->host();
        $url = 'http://127.0.0.1:3000/admin/';

        self::assertSame($url, $h->sanitizeRedirectTarget($url));
    }

    #[Test]
    public function sanitize_redirect_allows_localhost_hostname_absolute_url(): void
    {
        $h = $this->host();

        self::assertSame('http://localhost:3000/admin/', $h->sanitizeRedirectTarget('http://localhost:3000/admin/'));
    }

    #[Test]
    public function sanitize_redirect_rejects_foreign_absolute_url(): void
    {
        $h = $this->host();

        self::assertNull($h->sanitizeRedirectTarget('https://evil.example/phish'));
    }

    #[Test]
    public function sanitize_redirect_still_allows_relative_paths(): void
    {
        $h = $this->host();

        self::assertSame('/admin/workspace', $h->sanitizeRedirectTarget('/admin/workspace'));
    }
}
