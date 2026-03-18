<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class AccountPasswordResetToken extends ContentEntityBase
{
    protected string $entityTypeId = 'account_password_reset_token';

    protected array $entityKeys = [
        'id' => 'aprtid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('used_at') === null) {
            $this->set('used_at', null);
        }
    }
}
