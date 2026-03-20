<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\InternalJudgmentRuleController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Entity\JudgmentRule;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Claudriel\Support\StorageRepositoryAdapter;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class JudgmentRuleServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'judgment_rule',
            label: 'Judgment Rule',
            class: JudgmentRule::class,
            keys: ['id' => 'jrid', 'uuid' => 'uuid', 'label' => 'rule_text'],
            fieldDefinitions: [
                'jrid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'rule_text' => ['type' => 'string', 'required' => true],
                'context' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'confidence' => ['type' => 'float'],
                'application_count' => ['type' => 'integer'],
                'last_applied_at' => ['type' => 'datetime'],
                'status' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->singleton(InternalJudgmentRuleController::class, function () {
            return new InternalJudgmentRuleController(
                new StorageRepositoryAdapter($this->resolve(EntityTypeManager::class)->getStorage('judgment_rule')),
                $this->resolve(InternalApiTokenGenerator::class),
                $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
            );
        });
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $listRoute = RouteBuilder::create('/api/internal/rules/active')
            ->controller(InternalJudgmentRuleController::class.'::listActive')
            ->allowAll()
            ->methods('GET')
            ->build();
        $listRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.rules.active', $listRoute);

        $suggestRoute = RouteBuilder::create('/api/internal/rules/suggest')
            ->controller(InternalJudgmentRuleController::class.'::suggest')
            ->allowAll()
            ->methods('POST')
            ->build();
        $suggestRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.rules.suggest', $suggestRoute);
    }
}
