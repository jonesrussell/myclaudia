<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class WorkspaceRepo extends ContentEntityBase
{
    protected string $entityTypeId = 'workspace_repo';

    protected array $entityKeys = [
        'id' => 'id',
        'uuid' => 'uuid',
        'label' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('is_active') === null) {
            $this->set('is_active', true);
        }
    }
}
