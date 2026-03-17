<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\Integration;
use PHPUnit\Framework\TestCase;

final class IntegrationTest extends TestCase
{
    public function test_stores_google_oauth_fields(): void
    {
        $integration = new Integration([
            'account_id' => 'acc-123',
            'provider' => 'google',
            'access_token' => 'ya29.token',
            'refresh_token' => '1//refresh',
            'token_expires_at' => '2026-03-16T22:00:00Z',
            'scopes' => json_encode(['https://www.googleapis.com/auth/gmail.readonly', 'https://www.googleapis.com/auth/gmail.send', 'https://www.googleapis.com/auth/calendar.readonly', 'https://www.googleapis.com/auth/calendar.events']),
            'status' => 'active',
            'provider_email' => 'user@gmail.com',
            'metadata' => json_encode(['token_type' => 'Bearer']),
        ]);

        self::assertSame('acc-123', $integration->get('account_id'));
        self::assertSame('google', $integration->get('provider'));
        self::assertSame('ya29.token', $integration->get('access_token'));
        self::assertSame('1//refresh', $integration->get('refresh_token'));
        self::assertSame('active', $integration->get('status'));
        self::assertSame('user@gmail.com', $integration->get('provider_email'));
    }

    public function test_defaults_status_to_pending(): void
    {
        $integration = new Integration([
            'account_id' => 'acc-123',
            'provider' => 'google',
        ]);

        self::assertSame('pending', $integration->get('status'));
    }
}
