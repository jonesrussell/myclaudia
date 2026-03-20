<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\InternalBriefController;
use Claudriel\Controller\InternalEventController;
use Claudriel\Controller\InternalSearchController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Support\DriftDetector;
use Claudriel\Support\StorageRepositoryAdapter;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class SearchToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(InternalBriefController::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManager::class);
            $eventRepo = new StorageRepositoryAdapter($entityTypeManager->getStorage('mc_event'));
            $commitmentRepo = new StorageRepositoryAdapter($entityTypeManager->getStorage('commitment'));
            $personRepo = new StorageRepositoryAdapter($entityTypeManager->getStorage('person'));

            $assembler = new DayBriefAssembler(
                $eventRepo,
                $commitmentRepo,
                new DriftDetector($commitmentRepo),
                $personRepo,
            );

            return new InternalBriefController(
                $assembler,
                $this->resolve(InternalApiTokenGenerator::class),
                $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
            );
        });

        $this->singleton(InternalEventController::class, function () {
            return new InternalEventController(
                new StorageRepositoryAdapter($this->resolve(EntityTypeManager::class)->getStorage('mc_event')),
                $this->resolve(InternalApiTokenGenerator::class),
                $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
            );
        });

        $this->singleton(InternalSearchController::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManager::class);

            return new InternalSearchController(
                new StorageRepositoryAdapter($entityTypeManager->getStorage('person')),
                new StorageRepositoryAdapter($entityTypeManager->getStorage('commitment')),
                new StorageRepositoryAdapter($entityTypeManager->getStorage('mc_event')),
                $this->resolve(InternalApiTokenGenerator::class),
                $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
            );
        });
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $briefRoute = RouteBuilder::create('/api/internal/brief/generate')
            ->controller(InternalBriefController::class.'::generate')
            ->allowAll()
            ->methods('POST')
            ->build();
        $briefRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.brief.generate', $briefRoute);

        $eventSearchRoute = RouteBuilder::create('/api/internal/events/search')
            ->controller(InternalEventController::class.'::search')
            ->allowAll()
            ->methods('GET')
            ->build();
        $eventSearchRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.events.search', $eventSearchRoute);

        $globalSearchRoute = RouteBuilder::create('/api/internal/search/global')
            ->controller(InternalSearchController::class.'::searchGlobal')
            ->allowAll()
            ->methods('GET')
            ->build();
        $globalSearchRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.search.global', $globalSearchRoute);
    }
}
