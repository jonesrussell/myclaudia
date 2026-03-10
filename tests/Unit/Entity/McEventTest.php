<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\McEvent;
use PHPUnit\Framework\TestCase;

final class McEventTest extends TestCase
{
    public function testEntityTypeId(): void
    {
        $event = new McEvent(['source' => 'gmail', 'type' => 'message.received', 'payload' => '{}']);
        self::assertSame('mc_event', $event->getEntityTypeId());
    }

    public function testSourceAndType(): void
    {
        $event = new McEvent(['source' => 'gmail', 'type' => 'message.received', 'payload' => '{}']);
        self::assertSame('gmail', $event->get('source'));
        self::assertSame('message.received', $event->get('type'));
    }

    public function test_entity_keys_include_content_hash(): void
    {
        $event = new McEvent();
        $keys = $event->getEntityKeys();
        $this->assertArrayHasKey('content_hash', $keys);
        $this->assertSame('content_hash', $keys['content_hash']);
    }

    public function test_category_defaults_to_notification(): void
    {
        $event = new McEvent();
        $this->assertSame('notification', $event->get('category'));
    }

    public function test_category_can_be_set(): void
    {
        $event = new McEvent();
        $event->set('category', 'job_hunt');
        $this->assertSame('job_hunt', $event->get('category'));
    }
}
