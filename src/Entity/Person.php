<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Person extends ContentEntityBase
{
    protected string $entityTypeId = 'person';

    protected array $entityKeys = [
        'id' => 'pid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('tier') === null) {
            $this->set('tier', 'contact');
        }
        if ($this->get('last_interaction_at') === null) {
            $this->set('last_interaction_at', null);
        }
        if ($this->get('source') === null) {
            $this->set('source', 'gmail');
        }
        if ($this->get('metadata') === null) {
            $this->set('metadata', '{}');
        }
    }
}
