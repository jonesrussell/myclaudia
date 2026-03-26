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
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('account_id') === null) {
            $this->set('account_id', null);
        }
        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default');
        }
        if ($this->get('description') === null) {
            $this->set('description', '');
        }
        if ($this->get('saved_context') === null) {
            $this->set('saved_context', null);
        }
        if ($this->get('anthropic_model') === null) {
            $this->set('anthropic_model', null);
        }
        if ($this->get('mode') === null) {
            $this->set('mode', 'persistent');
        }
        if ($this->get('status') === null) {
            $this->set('status', 'active');
        }
    }
}
