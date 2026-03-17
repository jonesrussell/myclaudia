<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Entity\Integration;
use Claudriel\Support\GoogleTokenManager;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

final class GoogleTokenManagerTest extends TestCase
{
    public function test_returns_valid_token_when_not_expired(): void
    {
        $integration = new Integration([
            'account_id' => 'acc-123',
            'provider' => 'google',
            'access_token' => 'ya29.valid',
            'refresh_token' => '1//refresh',
            'token_expires_at' => (new \DateTimeImmutable('+1 hour'))->format('c'),
            'status' => 'active',
        ]);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn(
            $this->buildQueryReturning(['1'])
        );
        $storage->method('load')->willReturn($integration);

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getStorage')->with('integration')->willReturn($storage);

        $manager = new GoogleTokenManager($etm);
        $token = $manager->getValidAccessToken('acc-123');

        self::assertSame('ya29.valid', $token);
    }

    public function test_throws_when_no_active_integration(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn(
            $this->buildQueryReturning([])
        );

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getStorage')->with('integration')->willReturn($storage);

        $manager = new GoogleTokenManager($etm);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active Google integration');
        $manager->getValidAccessToken('acc-999');
    }

    public function test_has_active_integration_returns_true(): void
    {
        $integration = new Integration([
            'account_id' => 'acc-123',
            'provider' => 'google',
            'status' => 'active',
        ]);

        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn(
            $this->buildQueryReturning(['1'])
        );
        $storage->method('load')->willReturn($integration);

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getStorage')->with('integration')->willReturn($storage);

        $manager = new GoogleTokenManager($etm);
        self::assertTrue($manager->hasActiveIntegration('acc-123'));
    }

    public function test_has_active_integration_returns_false(): void
    {
        $storage = $this->createMock(EntityStorageInterface::class);
        $storage->method('getQuery')->willReturn(
            $this->buildQueryReturning([])
        );

        $etm = $this->createMock(EntityTypeManager::class);
        $etm->method('getStorage')->with('integration')->willReturn($storage);

        $manager = new GoogleTokenManager($etm);
        self::assertFalse($manager->hasActiveIntegration('acc-999'));
    }

    private function buildQueryReturning(array $ids): EntityQueryInterface
    {
        $query = $this->createMock(EntityQueryInterface::class);
        $query->method('condition')->willReturnSelf();
        $query->method('range')->willReturnSelf();
        $query->method('execute')->willReturn($ids);

        return $query;
    }
}
