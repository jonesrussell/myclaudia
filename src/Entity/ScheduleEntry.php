<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ScheduleEntry extends ContentEntityBase
{
    protected string $entityTypeId = 'schedule_entry';

    protected array $entityKeys = [
        'id' => 'seid',
        'uuid' => 'uuid',
        'label' => 'title',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('source') === null) {
            $this->set('source', 'manual');
        }
        if ($this->get('status') === null) {
            $this->set('status', 'active');
        }
        if ($this->get('notes') === null) {
            $this->set('notes', '');
        }
        if ($this->get('calendar_id') === null) {
            $this->set('calendar_id', null);
        }
        if ($this->get('external_id') === null) {
            $this->set('external_id', null);
        }
        if ($this->get('recurring_series_id') === null) {
            $this->set('recurring_series_id', null);
        }
        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', null);
        }
        if ($this->get('raw_payload') === null) {
            $this->set('raw_payload', '{}');
        }
    }
}
