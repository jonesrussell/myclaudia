<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Entity\CodeTask;
use Claudriel\Support\StorageRepositoryAdapter;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\WaaseyaaRouter;

final class CodeTaskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(CodeTaskRunner::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManagerInterface::class);
            $database = $this->resolve(DatabaseInterface::class);
            $dispatcher = $this->resolve(EventDispatcherInterface::class);
            $entityType = $entityTypeManager->getDefinition('code_task');
            $storage = new SqlEntityStorage($entityType, $database, $dispatcher);
            $repo = new StorageRepositoryAdapter($storage);

            return new CodeTaskRunner($repo);
        });

        $this->entityType(new EntityType(
            id: 'code_task',
            label: 'Code Task',
            class: CodeTask::class,
            keys: ['id' => 'ctid', 'uuid' => 'uuid', 'label' => 'prompt'],
            fieldDefinitions: [
                'ctid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_uuid' => ['type' => 'string', 'required' => true],
                'repo_uuid' => ['type' => 'string', 'required' => true],
                'prompt' => ['type' => 'text_long', 'required' => true],
                'status' => ['type' => 'string'],
                'branch_name' => ['type' => 'string'],
                'pr_url' => ['type' => 'string'],
                'summary' => ['type' => 'text_long'],
                'diff_preview' => ['type' => 'text_long'],
                'error' => ['type' => 'text_long'],
                'claude_output' => ['type' => 'text_long'],
                'started_at' => ['type' => 'string'],
                'completed_at' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }

    public function commands(
        EntityTypeManager $entityTypeManager,
        DatabaseInterface $database,
        EventDispatcherInterface $dispatcher,
    ): array {
        // CodeTaskRunCommand will be added in a later task (#574)
        return [];
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        // InternalCodeTaskController routes will be added in a later task (#575)
    }
}
