<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class TemporalNotification extends ContentEntityBase
{
    protected string $entityTypeId = 'temporal_notification';

    protected array $entityKeys = [
        'id' => 'tnid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'temporal_notification', $this->entityKeys);

        if ($this->get('workspace_uuid') === null) {
            $this->set('workspace_uuid', null);
        }
        if ($this->get('state') === null) {
            $this->set('state', 'active');
        }
        if ($this->get('actions') === null) {
            $this->set('actions', []);
        }
        if ($this->get('action_states') === null) {
            $this->set('action_states', []);
        }
        if ($this->get('metadata') === null) {
            $this->set('metadata', []);
        }
        if ($this->get('snoozed_until') === null) {
            $this->set('snoozed_until', null);
        }
    }

    /**
     * @return array<string, string>
     */
    public function getEntityKeys(): array
    {
        return $this->entityKeys;
    }
}
