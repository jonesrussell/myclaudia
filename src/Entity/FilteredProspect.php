<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class FilteredProspect extends ContentEntityBase
{
    protected string $entityTypeId = 'filtered_prospect';

    protected array $entityKeys = [
        'id' => 'fpid',
        'uuid' => 'uuid',
        'label' => 'title',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
