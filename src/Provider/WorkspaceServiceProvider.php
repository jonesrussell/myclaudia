<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Entity\Artifact;
use Claudriel\Entity\Skill;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceProject;
use Claudriel\Entity\WorkspaceRepo;
use Claudriel\Subscriber\JunctionCascadeSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyDispatcher;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
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
                'saved_context' => ['type' => 'text_long'],
                'anthropic_model' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'mode' => ['type' => 'string'],
                'status' => ['type' => 'string'],
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

        // Internal repo/workspace metadata — not duplicated in OperationsServiceProvider (#652).
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

        $this->entityType(new EntityType(
            id: 'workspace_project',
            label: 'Workspace Project',
            class: WorkspaceProject::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'project_uuid' => ['type' => 'string', 'required' => true],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'workspace_repo',
            label: 'Workspace Repo',
            class: WorkspaceRepo::class,
            keys: ['id' => 'id', 'uuid' => 'uuid', 'label' => 'uuid'],
            fieldDefinitions: [
                'id' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'repo_uuid' => ['type' => 'string', 'required' => true],
                'is_active' => ['type' => 'boolean'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }

    public function boot(): void
    {
        $dispatcher = $this->resolve(EventDispatcherInterface::class);
        if ($dispatcher instanceof SymfonyDispatcher) {
            $dispatcher->addSubscriber(new JunctionCascadeSubscriber(
                $this->resolve(EntityTypeManager::class),
            ));
        }
    }
}
