<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Person extends ContentEntityBase
{
    protected string $entityTypeId = 'person';

    protected array $entityKeys = [
        'id'    => 'pid',
        'uuid'  => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'person', $this->entityKeys);
    }
}
