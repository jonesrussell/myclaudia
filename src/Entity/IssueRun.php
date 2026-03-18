<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class IssueRun extends ContentEntityBase
{
    protected string $entityTypeId = 'issue_run';

    protected array $entityKeys = [
        'id' => 'irid',
        'uuid' => 'uuid',
        'label' => 'issue_title',
    ];

    public function __construct(array $values = [])
    {
        $values += [
            'status' => 'pending',
            'event_log' => '[]',
        ];
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
