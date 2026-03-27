<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class CodeTask extends ContentEntityBase
{
    protected string $entityTypeId = 'code_task';

    protected array $entityKeys = [
        'id' => 'ctid',
        'uuid' => 'uuid',
        'label' => 'prompt',
    ];

    public function __construct(array $values = [])
    {
        if (! array_key_exists('status', $values)) {
            $values['status'] = 'queued';
        }
        if (! array_key_exists('pr_url', $values)) {
            $values['pr_url'] = null;
        }
        if (! array_key_exists('summary', $values)) {
            $values['summary'] = null;
        }
        if (! array_key_exists('diff_preview', $values)) {
            $values['diff_preview'] = null;
        }
        if (! array_key_exists('error', $values)) {
            $values['error'] = null;
        }
        if (! array_key_exists('branch_name', $values)) {
            $values['branch_name'] = null;
        }
        if (! array_key_exists('claude_output', $values)) {
            $values['claude_output'] = null;
        }
        if (! array_key_exists('started_at', $values)) {
            $values['started_at'] = null;
        }
        if (! array_key_exists('completed_at', $values)) {
            $values['completed_at'] = null;
        }
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
