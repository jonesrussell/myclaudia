<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Repo extends ContentEntityBase
{
    protected string $entityTypeId = 'repo';

    protected array $entityKeys = [
        'id' => 'rid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        // Compute full_name from owner + name before parent constructor
        if (isset($values['owner'], $values['name']) && !isset($values['full_name'])) {
            $values['full_name'] = $values['owner'] . '/' . $values['name'];
        }

        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('default_branch') === null) {
            $this->set('default_branch', 'main');
        }
        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default');
        }
    }
}
