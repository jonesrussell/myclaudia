<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class MemoryAccessEvent extends ContentEntityBase
{
    protected string $entityTypeId = 'memory_access_event';

    protected array $entityKeys = [
        'id' => 'maeid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('entity_type') === null) {
            $this->set('entity_type', null);
        }
        if ($this->get('entity_uuid') === null) {
            $this->set('entity_uuid', null);
        }
        if ($this->get('metadata') === null) {
            $this->set('metadata', null);
        }
    }
}
