<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ChatSession extends ContentEntityBase
{
    protected string $entityTypeId = 'chat_session';

    protected array $entityKeys = [
        'id' => 'csid',
        'uuid' => 'uuid',
        'label' => 'title',
    ];

    public function __construct(array $values = [])
    {
        if (! array_key_exists('title', $values)) {
            $values['title'] = 'New Chat';
        }
        if (! array_key_exists('turns_consumed', $values)) {
            $values['turns_consumed'] = 0;
        }
        if (! array_key_exists('continued_count', $values)) {
            $values['continued_count'] = 0;
        }
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default');
        }
        if ($this->get('workspace_id') === null) {
            $this->set('workspace_id', null);
        }
    }
}
