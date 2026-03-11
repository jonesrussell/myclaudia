<?php

declare(strict_types=1);

namespace Claudriel\Ingestion\Handler;

use Claudriel\Entity\McEvent;
use Claudriel\Ingestion\EventCategorizer;
use Claudriel\Ingestion\IngestHandlerInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Stores any ingestion event as a McEvent entity.
 *
 * Used as the fallback handler when no specific handler matches the event type.
 */
final class GenericEventHandler implements IngestHandlerInterface
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EventCategorizer $categorizer = new EventCategorizer,
    ) {}

    public function supports(string $type): bool
    {
        return true;
    }

    public function handle(array $data): array
    {
        $payload = $data['payload'];
        $category = $this->categorizer->categorize($data['source'], $data['type'], $payload);

        $event = new McEvent([
            'source' => $data['source'],
            'type' => $data['type'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred' => date('Y-m-d H:i:s'),
            'category' => $category,
        ]);

        $storage = $this->entityTypeManager->getStorage('mc_event');
        $storage->save($event);

        return [
            'status' => 'created',
            'entity_type' => 'mc_event',
            'uuid' => $event->uuid(),
        ];
    }
}
