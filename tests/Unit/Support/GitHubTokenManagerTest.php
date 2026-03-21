<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Entity\Integration;
use Claudriel\Support\GitHubTokenManager;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class GitHubTokenManagerTest extends TestCase
{
    private EntityRepositoryInterface $integrationRepo;

    private GitHubTokenManager $tokenManager;

    protected function setUp(): void
    {
        $this->integrationRepo = $this->createMock(EntityRepositoryInterface::class);
        $this->tokenManager = new GitHubTokenManager($this->integrationRepo);
    }

    public function test_get_valid_token_returns_stored_token(): void
    {
        $integration = new Integration([
            'iid' => 1,
            'uuid' => 'int-uuid-1',
            'name' => 'github',
            'account_id' => 'acc-123',
            'provider' => 'github',
            'access_token' => 'ghp_test_token_abc123',
            'status' => 'active',
        ]);

        $this->integrationRepo
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use ($integration): array {
                if (($criteria['status'] ?? null) === 'revoked') {
                    return [];
                }
                if (($criteria['status'] ?? null) === 'active') {
                    return [$integration];
                }

                return [];
            });

        $token = $this->tokenManager->getValidAccessToken('acc-123');

        $this->assertSame('ghp_test_token_abc123', $token);
    }

    public function test_get_valid_token_throws_when_no_integration(): void
    {
        $this->integrationRepo
            ->method('findBy')
            ->willReturn([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active GitHub integration found for this account');

        $this->tokenManager->getValidAccessToken('acc-123');
    }

    public function test_get_valid_token_throws_when_revoked(): void
    {
        $revokedIntegration = new Integration([
            'iid' => 1,
            'uuid' => 'int-uuid-1',
            'name' => 'github',
            'account_id' => 'acc-123',
            'provider' => 'github',
            'access_token' => 'ghp_old_token',
            'status' => 'revoked',
        ]);

        $this->integrationRepo
            ->method('findBy')
            ->willReturnCallback(function (array $criteria) use ($revokedIntegration): array {
                if (($criteria['status'] ?? null) === 'revoked') {
                    return [$revokedIntegration];
                }

                return [];
            });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('GitHub integration has been revoked');

        $this->tokenManager->getValidAccessToken('acc-123');
    }

    public function test_has_active_integration_returns_true(): void
    {
        $integration = new Integration([
            'iid' => 1,
            'uuid' => 'int-uuid-1',
            'name' => 'github',
            'account_id' => 'acc-123',
            'provider' => 'github',
            'access_token' => 'ghp_test_token',
            'status' => 'active',
        ]);

        $this->integrationRepo
            ->method('findBy')
            ->with(['account_id' => 'acc-123', 'provider' => 'github', 'status' => 'active'], null, 1)
            ->willReturn([$integration]);

        $this->assertTrue($this->tokenManager->hasActiveIntegration('acc-123'));
    }

    public function test_has_active_integration_returns_false(): void
    {
        $this->integrationRepo
            ->method('findBy')
            ->willReturn([]);

        $this->assertFalse($this->tokenManager->hasActiveIntegration('acc-456'));
    }

    public function test_mark_revoked_updates_status(): void
    {
        $integration1 = new Integration([
            'iid' => 1,
            'uuid' => 'int-uuid-1',
            'name' => 'github',
            'account_id' => 'acc-123',
            'provider' => 'github',
            'access_token' => 'ghp_token_1',
            'status' => 'active',
        ]);

        $integration2 = new Integration([
            'iid' => 2,
            'uuid' => 'int-uuid-2',
            'name' => 'github',
            'account_id' => 'acc-123',
            'provider' => 'github',
            'access_token' => 'ghp_token_2',
            'status' => 'active',
        ]);

        $this->integrationRepo
            ->method('findBy')
            ->with(['account_id' => 'acc-123', 'provider' => 'github'])
            ->willReturn([$integration1, $integration2]);

        $this->integrationRepo
            ->expects($this->exactly(2))
            ->method('save');

        $this->tokenManager->markRevoked('acc-123');

        $this->assertSame('revoked', $integration1->get('status'));
        $this->assertSame('revoked', $integration2->get('status'));
    }
}
