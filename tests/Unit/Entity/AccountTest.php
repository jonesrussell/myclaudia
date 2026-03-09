<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\Account;
use PHPUnit\Framework\TestCase;

final class AccountTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $account = new Account(['email' => 'test@example.com', 'name' => 'Test User']);
        self::assertSame('account', $account->getEntityTypeId());
    }

    public function testGetEmail(): void
    {
        $account = new Account(['email' => 'test@example.com', 'name' => 'Test User']);
        self::assertSame('test@example.com', $account->get('email'));
    }
}
