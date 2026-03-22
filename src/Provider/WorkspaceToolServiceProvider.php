<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\InternalScheduleController;
use Claudriel\Controller\InternalTriageController;
use Claudriel\Controller\InternalWorkspaceController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Support\StorageRepositoryAdapter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class WorkspaceToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(InternalWorkspaceController::class, function () {
            return new InternalWorkspaceController(
                new StorageRepositoryAdapter($this->resolve(EntityTypeManager::class)->getStorage('workspace')),
                $this->resolve(InternalApiTokenGenerator::class),
                $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
                $this->resolve(GitRepositoryManager::class),
            );
        });

        $this->singleton(InternalScheduleController::class, function () {
            return new InternalScheduleController(
                new StorageRepositoryAdapter($this->resolve(EntityTypeManager::class)->getStorage('schedule_entry')),
                $this->resolve(InternalApiTokenGenerator::class),
                $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
            );
        });

        $this->singleton(InternalTriageController::class, function () {
            return new InternalTriageController(
                new StorageRepositoryAdapter($this->resolve(EntityTypeManager::class)->getStorage('triage_entry')),
                $this->resolve(InternalApiTokenGenerator::class),
                $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
            );
        });
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $workspaceListRoute = RouteBuilder::create('/api/internal/workspaces/list')
            ->controller(InternalWorkspaceController::class.'::listWorkspaces')
            ->allowAll()
            ->methods('GET')
            ->build();
        $workspaceListRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.workspaces.list', $workspaceListRoute);

        $workspaceCreateRoute = RouteBuilder::create('/api/internal/workspaces/create')
            ->controller(InternalWorkspaceController::class.'::create')
            ->allowAll()
            ->methods('POST')
            ->build();
        $workspaceCreateRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.workspaces.create', $workspaceCreateRoute);

        $workspaceContextRoute = RouteBuilder::create('/api/internal/workspaces/{uuid}')
            ->controller(InternalWorkspaceController::class.'::workspaceContext')
            ->allowAll()
            ->methods('GET')
            ->build();
        $workspaceContextRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.workspaces.context', $workspaceContextRoute);

        $workspaceDeleteRoute = RouteBuilder::create('/api/internal/workspaces/{uuid}/delete')
            ->controller(InternalWorkspaceController::class.'::delete')
            ->allowAll()
            ->methods('POST')
            ->build();
        $workspaceDeleteRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.workspaces.delete', $workspaceDeleteRoute);

        $workspaceCloneRoute = RouteBuilder::create('/api/internal/workspaces/{uuid}/clone-repo')
            ->controller(InternalWorkspaceController::class.'::cloneRepo')
            ->allowAll()
            ->methods('POST')
            ->build();
        $workspaceCloneRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.workspaces.clone', $workspaceCloneRoute);

        $scheduleQueryRoute = RouteBuilder::create('/api/internal/schedule/query')
            ->controller(InternalScheduleController::class.'::query')
            ->allowAll()
            ->methods('GET')
            ->build();
        $scheduleQueryRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.schedule.query', $scheduleQueryRoute);

        $triageListRoute = RouteBuilder::create('/api/internal/triage/list')
            ->controller(InternalTriageController::class.'::listUntriaged')
            ->allowAll()
            ->methods('GET')
            ->build();
        $triageListRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.triage.list', $triageListRoute);

        $triageResolveRoute = RouteBuilder::create('/api/internal/triage/{uuid}/resolve')
            ->controller(InternalTriageController::class.'::resolve')
            ->allowAll()
            ->methods('POST')
            ->build();
        $triageResolveRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.triage.resolve', $triageResolveRoute);
    }
}
