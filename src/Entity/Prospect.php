<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Claudriel\Workflow\ProspectWorkflowPreset;
use Waaseyaa\Entity\ContentEntityBase;

final class Prospect extends ContentEntityBase
{
    protected string $entityTypeId = 'prospect';

    protected array $entityKeys = [
        'id' => 'prid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        if (! array_key_exists('stage', $values)) {
            $values['stage'] = ProspectWorkflowPreset::STATE_LEAD;
        }
        if (! array_key_exists('deleted_at', $values)) {
            $values['deleted_at'] = null;
        }
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
