<?php

declare(strict_types=1);

namespace Claudriel\Support\Mcp;

use Waaseyaa\Access\AccountInterface;

/**
 * Service account used for MCP bearer token authentication.
 *
 * External Claude Code sessions authenticating via bearer token
 * receive this account identity with full admin permissions.
 */
final readonly class McpServiceAccount implements AccountInterface
{
    public function id(): int|string
    {
        return 'mcp-service';
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }

    /** @return string[] */
    public function getRoles(): array
    {
        return ['admin', 'mcp_client'];
    }

    public function isAuthenticated(): bool
    {
        return true;
    }

    public function getTenantId(): ?string
    {
        return $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default';
    }

    public function getUuid(): ?string
    {
        return null;
    }

    public function getEmail(): string
    {
        return 'mcp@claudriel.ai';
    }
}
