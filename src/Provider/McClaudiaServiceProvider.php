<?php

declare(strict_types=1);

namespace MyClaudia\Provider;

use MyClaudia\Command\BriefCommand;
use MyClaudia\Command\CommitmentUpdateCommand;
use MyClaudia\Command\CommitmentsCommand;
use MyClaudia\Command\SkillsCommand;
use MyClaudia\Controller\CommitmentUpdateController;
use MyClaudia\Controller\DayBriefController;
use MyClaudia\Domain\DayBrief\Assembler\DayBriefAssembler;
use MyClaudia\Domain\DayBrief\Service\BriefSessionStore;
use MyClaudia\Support\DriftDetector;
use MyClaudia\Entity\Account;
use MyClaudia\Entity\Commitment;
use MyClaudia\Entity\Integration;
use MyClaudia\Entity\McEvent;
use MyClaudia\Entity\Person;
use MyClaudia\Entity\Skill;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class McClaudiaServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'account',
            label: 'Account',
            class: Account::class,
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->entityType(new EntityType(
            id: 'mc_event',
            label: 'Event',
            class: McEvent::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid'],
        ));

        $this->entityType(new EntityType(
            id: 'person',
            label: 'Person',
            class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->entityType(new EntityType(
            id: 'integration',
            label: 'Integration',
            class: Integration::class,
            keys: ['id' => 'iid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->entityType(new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        ));

        $this->entityType(new EntityType(
            id: 'skill',
            label: 'Skill',
            class: Skill::class,
            keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
    }

    public function routes(WaaseyaaRouter $router): void
    {
        $router->addRoute(
            'myclaudia.home',
            RouteBuilder::create('/')
                ->controller(DayBriefController::class . '::show')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'myclaudia.brief',
            RouteBuilder::create('/brief')
                ->controller(DayBriefController::class . '::show')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'myclaudia.commitment.update',
            RouteBuilder::create('/commitments/{uuid}')
                ->controller(CommitmentUpdateController::class . '::update')
                ->allowAll()
                ->methods('PATCH')
                ->build(),
        );
    }

    public function commands(
        EntityTypeManager $entityTypeManager,
        PdoDatabase $database,
        EventDispatcherInterface $dispatcher,
    ): array {
        // Trigger getStorage() for each entity type so SqlSchemaHandler::ensureTable() runs.
        foreach (['mc_event', 'commitment', 'person', 'account', 'integration', 'skill'] as $typeId) {
            try {
                $entityTypeManager->getStorage($typeId);
            } catch (\Throwable) {
                // Ignore — table may already exist or type may be unavailable.
            }
        }

        $resolver = new SingleConnectionResolver($database);

        $eventType = new EntityType(
            id: 'mc_event',
            label: 'Event',
            class: McEvent::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid'],
        );
        $eventRepo = new EntityRepository(
            $eventType,
            new SqlStorageDriver($resolver, 'eid'),
            $dispatcher,
        );

        $commitmentType = new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        );
        $commitmentRepo = new EntityRepository(
            $commitmentType,
            new SqlStorageDriver($resolver, 'cid'),
            $dispatcher,
        );

        $skillType = new EntityType(
            id: 'skill',
            label: 'Skill',
            class: Skill::class,
            keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        $skillRepo = new EntityRepository(
            $skillType,
            new SqlStorageDriver($resolver, 'sid'),
            $dispatcher,
        );

        $assembler    = new DayBriefAssembler($eventRepo, $commitmentRepo, new DriftDetector($commitmentRepo), $skillRepo);
        $sessionStore = new BriefSessionStore($this->projectRoot . '/storage/brief-session.txt');

        return [
            new BriefCommand($assembler, $sessionStore),
            new CommitmentsCommand($commitmentRepo),
            new CommitmentUpdateCommand($commitmentRepo),
            new SkillsCommand($skillRepo),
        ];
    }
}
