<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\InternalPersonController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class PersonToolServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(InternalPersonController::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManager::class);

            return new InternalPersonController(
                $entityTypeManager->getRepository('person'),
                $entityTypeManager->getRepository('commitment'),
                $this->resolve(InternalApiTokenGenerator::class),
                $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
            );
        });
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $searchRoute = RouteBuilder::create('/api/internal/persons/search')
            ->controller(InternalPersonController::class.'::searchPersons')
            ->allowAll()
            ->methods('GET')
            ->build();
        $searchRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.persons.search', $searchRoute);

        $detailRoute = RouteBuilder::create('/api/internal/persons/{uuid}')
            ->controller(InternalPersonController::class.'::personDetail')
            ->allowAll()
            ->methods('GET')
            ->build();
        $detailRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.persons.detail', $detailRoute);
    }
}
