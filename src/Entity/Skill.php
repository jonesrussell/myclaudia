<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Skill extends ContentEntityBase
{
    protected string $entityTypeId = 'skill';

    protected array $entityKeys = [
        'id'    => 'sid',
        'uuid'  => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'skill', $this->entityKeys);
    }
}
