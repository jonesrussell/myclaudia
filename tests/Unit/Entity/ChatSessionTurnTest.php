<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\ChatSession;
use PHPUnit\Framework\TestCase;

final class ChatSessionTurnTest extends TestCase
{
    public function test_default_turns_consumed(): void
    {
        $session = new ChatSession(['title' => 'Test']);
        self::assertSame(0, $session->get('turns_consumed'));
    }

    public function test_default_continued_count(): void
    {
        $session = new ChatSession(['title' => 'Test']);
        self::assertSame(0, $session->get('continued_count'));
    }
}
