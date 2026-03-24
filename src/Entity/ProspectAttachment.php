<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ProspectAttachment extends ContentEntityBase
{
    protected string $entityTypeId = 'prospect_attachment';

    protected array $entityKeys = [
        'id' => 'paid',
        'uuid' => 'uuid',
        'label' => 'filename',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
