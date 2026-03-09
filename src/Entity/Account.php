<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Account extends ContentEntityBase
{
    protected string $entityTypeId = 'account';

    protected array $entityKeys = [
        'id'    => 'aid',
        'uuid'  => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'account', $this->entityKeys);
    }
}
