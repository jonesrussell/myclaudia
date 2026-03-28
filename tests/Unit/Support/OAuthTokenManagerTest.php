<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Entity\Integration;
use Claudriel\Support\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\ProviderRegistry;

final class OAuthTokenManagerTest extends TestCase
{
    public function test_returns_valid_token_when_not_expired(): void
    {
        $integration = new Integration([
            'uuid' => 'int-1',
            'account_id' => 'acc-1',
            'provider' => 'google',
            'access_token' => 'valid-token',
            'token_expires_at' => (new \DateTimeImmutable('+1 hour'))->format('c'),
            'status' => 'active',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturnCallback(
            function (array $criteria) use ($integration): array {
                if (($criteria['status'] ?? '') === 'revoked') {
                    return [];
                }

                return [$integration];
            }
        );

        $registry = new ProviderRegistry;
        $manager = new OAuthTokenManager($repo, $registry);

        self::assertSame('valid-token', $manager->getValidAccessToken('acc-1', 'google'));
    }

    public function test_refreshes_expired_google_token(): void
    {
        $integration = new Integration([
            'uuid' => 'int-2',
            'account_id' => 'acc-1',
            'provider' => 'google',
            'access_token' => 'expired-token',
            'refresh_token' => 'refresh-123',
            'token_expires_at' => (new \DateTimeImmutable('-1 hour'))->format('c'),
            'status' => 'active',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturnCallback(
            function (array $criteria) use ($integration): array {
                if (($criteria['status'] ?? '') === 'revoked') {
                    return [];
                }

                return [$integration];
            }
        );
        $repo->expects(self::once())->method('save');

        $newToken = new OAuthToken(
            accessToken: 'refreshed-token',
            refreshToken: 'refresh-456',
            expiresAt: new \DateTimeImmutable('+1 hour'),
            scopes: ['email'],
        );

        $provider = $this->createMock(OAuthProviderInterface::class);
        $provider->method('refreshToken')->with('refresh-123')->willReturn($newToken);

        $registry = new ProviderRegistry;
        $registry->register('google', $provider);

        $manager = new OAuthTokenManager($repo, $registry);

        self::assertSame('refreshed-token', $manager->getValidAccessToken('acc-1', 'google'));
    }

    public function test_returns_github_token_without_refresh(): void
    {
        $integration = new Integration([
            'uuid' => 'int-3',
            'account_id' => 'acc-1',
            'provider' => 'github',
            'access_token' => 'ghp_abc123',
            'token_expires_at' => null,
            'status' => 'active',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturnCallback(
            function (array $criteria) use ($integration): array {
                if (($criteria['status'] ?? '') === 'revoked') {
                    return [];
                }

                return [$integration];
            }
        );

        $registry = new ProviderRegistry;
        $manager = new OAuthTokenManager($repo, $registry);

        self::assertSame('ghp_abc123', $manager->getValidAccessToken('acc-1', 'github'));
    }

    public function test_throws_when_no_active_integration(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([]);

        $registry = new ProviderRegistry;
        $manager = new OAuthTokenManager($repo, $registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active google integration found');
        $manager->getValidAccessToken('acc-1', 'google');
    }

    public function test_throws_for_revoked_integration(): void
    {
        $revokedIntegration = new Integration([
            'uuid' => 'int-4',
            'account_id' => 'acc-1',
            'provider' => 'google',
            'status' => 'revoked',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturnCallback(
            function (array $criteria) use ($revokedIntegration): array {
                if (($criteria['status'] ?? '') === 'revoked') {
                    return [$revokedIntegration];
                }

                return [];
            }
        );

        $registry = new ProviderRegistry;
        $manager = new OAuthTokenManager($repo, $registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('revoked');
        $manager->getValidAccessToken('acc-1', 'google');
    }

    public function test_has_active_integration_returns_true(): void
    {
        $integration = new Integration([
            'uuid' => 'int-5',
            'account_id' => 'acc-1',
            'provider' => 'google',
            'status' => 'active',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$integration]);

        $registry = new ProviderRegistry;
        $manager = new OAuthTokenManager($repo, $registry);

        self::assertTrue($manager->hasActiveIntegration('acc-1', 'google'));
    }

    public function test_has_active_integration_returns_false(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([]);

        $registry = new ProviderRegistry;
        $manager = new OAuthTokenManager($repo, $registry);

        self::assertFalse($manager->hasActiveIntegration('acc-1', 'google'));
    }

    public function test_mark_revoked_sets_status(): void
    {
        $integration1 = new Integration([
            'uuid' => 'int-6',
            'account_id' => 'acc-1',
            'provider' => 'google',
            'status' => 'active',
        ]);

        $integration2 = new Integration([
            'uuid' => 'int-7',
            'account_id' => 'acc-1',
            'provider' => 'google',
            'status' => 'active',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$integration1, $integration2]);
        $repo->expects(self::exactly(2))->method('save');

        $registry = new ProviderRegistry;
        $manager = new OAuthTokenManager($repo, $registry);

        $manager->markRevoked('acc-1', 'google');

        self::assertSame('revoked', $integration1->get('status'));
        self::assertSame('revoked', $integration2->get('status'));
    }
}
