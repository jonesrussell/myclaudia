<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Provider;

use Claudriel\Provider\QueueServiceProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Waaseyaa\Queue\Handler\HandlerInterface;
use Waaseyaa\Queue\QueueInterface;

final class QueueServiceProviderTest extends TestCase
{
    public function test_get_queue_returns_queue_interface(): void
    {
        $provider = new QueueServiceProvider;
        $queue = $provider->getQueue();

        self::assertInstanceOf(QueueInterface::class, $queue);
    }

    public function test_dispatch_invokes_handler_synchronously(): void
    {
        $handled = false;
        $handler = new class($handled) implements HandlerInterface
        {
            private bool $called;

            public function __construct(bool &$called)
            {
                $this->called = &$called;
            }

            public function supports(object $message): bool
            {
                return $message instanceof stdClass;
            }

            public function handle(object $message): void
            {
                $this->called = true;
            }
        };

        $provider = new QueueServiceProvider([$handler]);
        $provider->getQueue()->dispatch(new stdClass);

        self::assertTrue($handled);
    }

    public function test_singleton_returns_same_instance(): void
    {
        $provider = new QueueServiceProvider;
        self::assertSame($provider->getQueue(), $provider->getQueue());
    }
}
