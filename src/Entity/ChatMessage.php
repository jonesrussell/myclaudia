<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class ChatMessage extends ContentEntityBase
{
    protected string $entityTypeId = 'chat_message';

    protected array $entityKeys = [
        'id'   => 'cmid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'chat_message', $this->entityKeys);
    }
}
