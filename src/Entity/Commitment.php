<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Claudriel\Workflow\CommitmentWorkflowPreset;
use Waaseyaa\Entity\ContentEntityBase;

final class Commitment extends ContentEntityBase
{
    protected string $entityTypeId = 'commitment';

    protected array $entityKeys = [
        'id' => 'cid',
        'uuid' => 'uuid',
        'label' => 'title',
    ];

    public function __construct(array $values = [])
    {
        // Sync workflow_state and status: workflow_state is canonical,
        // status is kept for backward compatibility.
        if (array_key_exists('workflow_state', $values) && ! array_key_exists('status', $values)) {
            $values['status'] = $values['workflow_state'];
        } elseif (! array_key_exists('workflow_state', $values) && array_key_exists('status', $values)) {
            $values['workflow_state'] = $values['status'];
        } elseif (! array_key_exists('workflow_state', $values) && ! array_key_exists('status', $values)) {
            $values['workflow_state'] = CommitmentWorkflowPreset::STATE_PENDING;
            $values['status'] = CommitmentWorkflowPreset::STATE_PENDING;
        }
        if (! array_key_exists('confidence', $values)) {
            $values['confidence'] = 1.0;
        }
        if (! array_key_exists('direction', $values)) {
            $values['direction'] = 'outbound';
        }
        if (! array_key_exists('workspace_uuid', $values)) {
            $values['workspace_uuid'] = null;
        }
        if (! array_key_exists('importance_score', $values)) {
            $values['importance_score'] = 1.0;
        }
        if (! array_key_exists('access_count', $values)) {
            $values['access_count'] = 0;
        }
        if (! array_key_exists('last_accessed_at', $values)) {
            $values['last_accessed_at'] = null;
        }
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
