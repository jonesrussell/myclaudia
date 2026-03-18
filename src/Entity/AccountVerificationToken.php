<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class AccountVerificationToken extends ContentEntityBase
{
    protected string $entityTypeId = 'account_verification_token';

    protected array $entityKeys = [
        'id' => 'avtid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('used_at') === null) {
            $this->set('used_at', null);
        }
        if ($this->get('metadata') === null) {
            $this->set('metadata', []);
        }
    }
}
