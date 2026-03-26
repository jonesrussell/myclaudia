<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Entity\MemoryAccessEvent;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class MemoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'memory_access_event',
            label: 'Memory Access Event',
            class: MemoryAccessEvent::class,
            keys: ['id' => 'maeid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'maeid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'entity_type' => ['type' => 'string'],
                'entity_uuid' => ['type' => 'string'],
                'tool_name' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'accessed_at' => ['type' => 'timestamp'],
                'metadata' => ['type' => 'text_long'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}
