<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Workspace extends ContentEntityBase
{
    protected string $entityTypeId = 'workspace';

    protected array $entityKeys = [
        'id' => 'wid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'workspace', $this->entityKeys);

        if ($this->get('account_id') === null) {
            $this->set('account_id', null);
        }
        if ($this->get('description') === null) {
            $this->set('description', '');
        }
        if ($this->get('metadata') === null) {
            $this->set('metadata', '{}');
        }
    }
}
