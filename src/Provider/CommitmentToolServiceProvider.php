<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\InternalCommitmentController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Waaseyaa\Entity\EntityTypeManager;
use Claudriel\Support\StorageRepositoryAdapter;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class CommitmentToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(InternalCommitmentController::class, function () {
            return new InternalCommitmentController(
                new StorageRepositoryAdapter($this->resolve(EntityTypeManager::class)->getStorage('commitment')),
                $this->resolve(InternalApiTokenGenerator::class),
                $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
            );
        });
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $listRoute = RouteBuilder::create('/api/internal/commitments/list')
            ->controller(InternalCommitmentController::class.'::listCommitments')
            ->allowAll()
            ->methods('GET')
            ->build();
        $listRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.commitments.list', $listRoute);

        $updateRoute = RouteBuilder::create('/api/internal/commitments/{uuid}/update')
            ->controller(InternalCommitmentController::class.'::updateCommitment')
            ->allowAll()
            ->methods('POST')
            ->build();
        $updateRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.commitments.update', $updateRoute);
    }
}
