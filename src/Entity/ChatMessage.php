<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ChatMessage extends ContentEntityBase
{
    protected string $entityTypeId = 'chat_message';

    protected array $entityKeys = [
        'id' => 'cmid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default');
        }
        if ($this->get('workspace_id') === null) {
            $this->set('workspace_id', null);
        }
    }
}
