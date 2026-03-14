<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\Account;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function test_entity_type_id(): void
    {
        $account = new Account(['email' => 'test@example.com', 'name' => 'Test User']);
        self::assertSame('account', $account->getEntityTypeId());
    }

    public function test_get_email(): void
    {
        $account = new Account(['email' => 'test@example.com', 'name' => 'Test User']);
        self::assertSame('test@example.com', $account->get('email'));
    }

    public function test_pending_accounts_are_not_authenticated_until_verified(): void
    {
        $pending = new Account(['email' => 'pending@example.com', 'status' => 'pending_verification']);
        self::assertFalse($pending->isAuthenticated());

        $verified = new Account([
            'aid' => 42,
            'email' => 'verified@example.com',
            'status' => 'active',
            'email_verified_at' => '2026-03-14T15:00:00+00:00',
        ]);
        self::assertTrue($verified->isAuthenticated());
    }
}
