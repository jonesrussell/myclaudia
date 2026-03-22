<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Queue\QueueInterface;
use Waaseyaa\Queue\SyncQueue;

final class QueueServiceProvider extends ServiceProvider
{
    private ?QueueInterface $queue = null;

    /** @param array<object> $handlers */
    public function __construct(
        private readonly array $handlers = [],
    ) {}

    public function register(): void
    {
        // DI registration when resolver supports set()
    }

    public function getQueue(): QueueInterface
    {
        if ($this->queue === null) {
            $this->queue = new SyncQueue($this->handlers);
        }

        return $this->queue;
    }
}
