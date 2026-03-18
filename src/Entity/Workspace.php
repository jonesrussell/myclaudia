<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

final class Workspace extends ContentEntityBase
{
    protected string $entityTypeId = 'workspace';

    protected array $entityKeys = [
        'id' => 'wid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('account_id') === null) {
            $this->set('account_id', null);
        }
        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default');
        }
        if ($this->get('description') === null) {
            $this->set('description', '');
        }
        if ($this->get('metadata') === null) {
            $this->set('metadata', '{}');
        }
        if ($this->get('repo_path') === null) {
            $this->set('repo_path', null);
        }
        if ($this->get('repo_url') === null) {
            $this->set('repo_url', null);
        }
        if ($this->get('branch') === null) {
            $this->set('branch', 'main');
        }
        if ($this->get('codex_model') === null) {
            $this->set('codex_model', 'gpt-4o-codex');
        }
        if ($this->get('last_commit_hash') === null) {
            $this->set('last_commit_hash', null);
        }
        if ($this->get('ci_status') === null) {
            $this->set('ci_status', null);
        }
    }
}
