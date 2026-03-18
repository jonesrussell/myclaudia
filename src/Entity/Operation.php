<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Work unit tracking for AI code-generation operations within a workspace.
 *
 * Internal-only: no CRUD surfaces (admin UI, REST, or GraphQL) are needed.
 * Operations are created and managed by the CodexExecutionPipeline and workspace commands.
 */
final class Operation extends ContentEntityBase
{
    protected string $entityTypeId = 'operation';

    protected array $entityKeys = [
        'id' => 'opid',
        'uuid' => 'uuid',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('workspace_id') === null) {
            $this->set('workspace_id', null);
        }
        if ($this->get('input_instruction') === null) {
            $this->set('input_instruction', '');
        }
        if ($this->get('generated_prompt') === null) {
            $this->set('generated_prompt', '');
        }
        if ($this->get('model_response') === null) {
            $this->set('model_response', '');
        }
        if ($this->get('applied_patch') === null) {
            $this->set('applied_patch', '');
        }
        if ($this->get('commit_hash') === null) {
            $this->set('commit_hash', '');
        }
        if ($this->get('status') === null) {
            $this->set('status', 'pending');
        }
    }
}
