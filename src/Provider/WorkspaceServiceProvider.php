<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Entity\Artifact;
use Claudriel\Entity\Skill;
use Claudriel\Entity\Workspace;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class WorkspaceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'wid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'metadata' => ['type' => 'string'],
                'repo_path' => ['type' => 'string'],
                'repo_url' => ['type' => 'string'],
                'branch' => ['type' => 'string'],
                'codex_model' => ['type' => 'string'],
                'last_commit_hash' => ['type' => 'string'],
                'ci_status' => ['type' => 'string'],
                'project_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'skill',
            label: 'Skill',
            class: Skill::class,
            keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'sid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'config' => ['type' => 'text_long'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'artifact',
            label: 'Artifact',
            class: Artifact::class,
            keys: ['id' => 'artid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'artid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'type' => ['type' => 'string'],
                'workspace_uuid' => ['type' => 'string'],
                'repo_url' => ['type' => 'string'],
                'branch' => ['type' => 'string'],
                'local_path' => ['type' => 'string'],
                'last_commit' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}
