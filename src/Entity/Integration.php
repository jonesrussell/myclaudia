<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Integration extends ContentEntityBase
{
    protected string $entityTypeId = 'integration';

    protected array $entityKeys = [
        'id' => 'iid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        if (! isset($values['status'])) {
            $values['status'] = 'pending';
        }

        parent::__construct($values, 'integration', $this->entityKeys);
    }
}
