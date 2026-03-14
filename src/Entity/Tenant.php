<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Tenant extends ContentEntityBase
{
    protected string $entityTypeId = 'tenant';

    protected array $entityKeys = [
        'id' => 'tid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'tenant', $this->entityKeys);

        if ($this->get('metadata') === null) {
            $this->set('metadata', []);
        }
        if ($this->get('slug') === null) {
            $this->set('slug', null);
        }
    }
}
