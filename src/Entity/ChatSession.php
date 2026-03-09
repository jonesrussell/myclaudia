<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ChatSession extends ContentEntityBase
{
    protected string $entityTypeId = 'chat_session';

    protected array $entityKeys = [
        'id'    => 'csid',
        'uuid'  => 'uuid',
        'label' => 'title',
    ];

    public function __construct(array $values = [])
    {
        if (!array_key_exists('title', $values)) {
            $values['title'] = 'New Chat';
        }
        parent::__construct($values, 'chat_session', $this->entityKeys);
    }
}
