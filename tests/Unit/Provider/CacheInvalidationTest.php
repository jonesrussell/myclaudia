<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Provider;

use Claudriel\Provider\CacheServiceProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\Event\EntityEvents;

final class CacheInvalidationTest extends TestCase
{
    public function test_wire_invalidator_registers_post_save_listener(): void
    {
        $provider = new CacheServiceProvider;
        $dispatcher = new EventDispatcher;

        $provider->wireInvalidator($dispatcher);

        self::assertTrue($dispatcher->hasListeners(EntityEvents::POST_SAVE->value));
    }

    public function test_wire_invalidator_registers_post_delete_listener(): void
    {
        $provider = new CacheServiceProvider;
        $dispatcher = new EventDispatcher;

        $provider->wireInvalidator($dispatcher);

        self::assertTrue($dispatcher->hasListeners(EntityEvents::POST_DELETE->value));
    }
}
