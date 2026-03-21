<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Entity\Project;
use Claudriel\Entity\ProjectRepo;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class ProjectServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'project',
            label: 'Project',
            class: Project::class,
            keys: ['id' => 'prid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'prid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true, 'maxLength' => 255],
                'description' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'metadata' => ['type' => 'text_long'],
                'settings' => ['type' => 'text_long'],
                'context' => ['type' => 'text_long'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'project_repo',
            label: 'Project Repo',
            class: ProjectRepo::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'project_uuid' => ['type' => 'string', 'required' => true],
                'repo_uuid' => ['type' => 'string', 'required' => true],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}
