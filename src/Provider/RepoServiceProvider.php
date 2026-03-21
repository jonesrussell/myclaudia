<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Entity\Repo;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class RepoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'repo',
            label: 'Repo',
            class: Repo::class,
            keys: ['id' => 'rid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'rid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'owner' => ['type' => 'string', 'required' => true],
                'name' => ['type' => 'string', 'required' => true],
                'full_name' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'default_branch' => ['type' => 'string'],
                'local_path' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}
