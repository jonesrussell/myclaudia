<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\Commitment;
use PHPUnit\Framework\TestCase;

final class CommitmentTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $c = new Commitment(['title' => 'Send report', 'status' => 'pending', 'confidence' => 0.9]);
        self::assertSame('commitment', $c->getEntityTypeId());
    }

    public function testDefaultStatus(): void
    {
        $c = new Commitment(['title' => 'Follow up']);
        self::assertSame('pending', $c->get('status'));
    }

    public function testConfidence(): void
    {
        $c = new Commitment(['title' => 'Review PR', 'confidence' => 0.75]);
        self::assertSame(0.75, $c->get('confidence'));
    }
}
