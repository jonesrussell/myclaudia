<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\Person;
use PHPUnit\Framework\TestCase;

final class PersonTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $person = new Person(['email' => 'jane@example.com', 'name' => 'Jane']);
        self::assertSame('person', $person->getEntityTypeId());
    }
}
