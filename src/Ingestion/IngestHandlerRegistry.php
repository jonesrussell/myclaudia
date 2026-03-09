<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

/**
 * Routes ingestion payloads to the appropriate handler based on event type.
 *
 * Falls back to GenericEventHandler for unknown types.
 */
final class IngestHandlerRegistry
{
    /** @var list<IngestHandlerInterface> */
    private array $handlers = [];

    private IngestHandlerInterface $fallback;

    public function __construct(IngestHandlerInterface $fallback)
    {
        $this->fallback = $fallback;
    }

    public function addHandler(IngestHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * @param array{source: string, type: string, payload: array<string, mixed>} $data
     * @return array<string, mixed>
     */
    public function handle(array $data): array
    {
        $type = $data['type'];

        foreach ($this->handlers as $handler) {
            if ($handler->supports($type)) {
                return $handler->handle($data);
            }
        }

        return $this->fallback->handle($data);
    }
}
