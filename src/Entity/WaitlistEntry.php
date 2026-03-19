<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class WaitlistEntry extends ContentEntityBase
{
    protected string $entityTypeId = 'waitlist_entry';

    protected array $entityKeys = [
        'id' => 'weid',
        'uuid' => 'uuid',
        'label' => 'email',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
