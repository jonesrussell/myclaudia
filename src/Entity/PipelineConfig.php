<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class PipelineConfig extends ContentEntityBase
{
    protected string $entityTypeId = 'pipeline_config';

    protected array $entityKeys = [
        'id' => 'pcid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        if (! array_key_exists('source_type', $values)) {
            $values['source_type'] = 'northcloud';
        }
        if (! array_key_exists('auto_qualify', $values)) {
            $values['auto_qualify'] = true;
        }
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
