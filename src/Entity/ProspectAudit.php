<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ProspectAudit extends ContentEntityBase
{
    protected string $entityTypeId = 'prospect_audit';

    protected array $entityKeys = [
        'id' => 'paud',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
