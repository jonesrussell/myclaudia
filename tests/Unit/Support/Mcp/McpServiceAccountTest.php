<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support\Mcp;

use Claudriel\Support\Mcp\McpServiceAccount;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpServiceAccount::class)]
final class McpServiceAccountTest extends TestCase
{
    #[Test]
    public function id_returns_mcp_service(): void
    {
        $account = new McpServiceAccount;

        $this->assertSame('mcp-service', $account->id());
    }

    #[Test]
    public function is_always_authenticated(): void
    {
        $account = new McpServiceAccount;

        $this->assertTrue($account->isAuthenticated());
    }

    #[Test]
    public function has_admin_permissions(): void
    {
        $account = new McpServiceAccount;

        $this->assertTrue($account->hasPermission('any.permission'));
    }

    #[Test]
    public function roles_include_admin_and_mcp_client(): void
    {
        $account = new McpServiceAccount;

        $this->assertContains('admin', $account->getRoles());
        $this->assertContains('mcp_client', $account->getRoles());
    }

    #[Test]
    public function email_returns_mcp_address(): void
    {
        $account = new McpServiceAccount;

        $this->assertSame('mcp@claudriel.ai', $account->getEmail());
    }
}
