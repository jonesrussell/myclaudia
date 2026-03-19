<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class JudgmentRule extends ContentEntityBase
{
    protected string $entityTypeId = 'judgment_rule';

    protected array $entityKeys = [
        'id' => 'jrid',
        'uuid' => 'uuid',
        'label' => 'rule_text',
    ];

    public function __construct(array $values = [])
    {
        if (! array_key_exists('status', $values)) {
            $values['status'] = 'active';
        }
        if (! array_key_exists('source', $values)) {
            $values['source'] = 'user_created';
        }
        if (! array_key_exists('confidence', $values)) {
            $values['confidence'] = 1.0;
        }
        if (! array_key_exists('application_count', $values)) {
            $values['application_count'] = 0;
        }
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
