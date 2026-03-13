<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Command\BriefCommand;
use Claudriel\Command\CommitmentsCommand;
use Claudriel\Command\CommitmentUpdateCommand;
use Claudriel\Command\RecategorizeEventsCommand;
use Claudriel\Command\SkillsCommand;
use Claudriel\Command\WorkspaceCreateCommand;
use Claudriel\Command\WorkspacesCommand;
use Claudriel\Controller\Ai\ExtractionImprovementSuggestionController;
use Claudriel\Controller\Ai\ExtractionSelfAssessmentController;
use Claudriel\Controller\Ai\ModelUpdateBatchController;
use Claudriel\Controller\Ai\TrainingExportController;
use Claudriel\Controller\Audit\CommitmentExtractionAuditController;
use Claudriel\Controller\BriefStreamController;
use Claudriel\Controller\ChatController;
use Claudriel\Controller\ChatStreamController;
use Claudriel\Controller\CommitmentUpdateController;
use Claudriel\Controller\ContextController;
use Claudriel\Controller\DashboardController;
use Claudriel\Controller\DayBriefController;
use Claudriel\Controller\Governance\CodifiedContextIntegrityController;
use Claudriel\Controller\IngestController;
use Claudriel\Controller\NotFoundController;
use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Controller\WorkspaceApiController;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Entity\Account;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\Integration;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\Skill;
use Claudriel\Entity\Workspace;
use Claudriel\Ingestion\EventCategorizer;
use Claudriel\Support\AutomatedSenderDetector;
use Claudriel\Support\DriftDetector;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ClaudrielServiceProvider extends ServiceProvider
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
            id: 'commitment_extraction_log',
            label: 'Commitment Extraction Log',
            class: CommitmentExtractionLog::class,
            keys: ['id' => 'celid', 'uuid' => 'uuid'],
        ));

        $this->entityType(new EntityType(
            id: 'skill',
            label: 'Skill',
            class: Skill::class,
            keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->entityType(new EntityType(
            id: 'chat_session',
            label: 'Chat Session',
            class: ChatSession::class,
            keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title'],
        ));

        $this->entityType(new EntityType(
            id: 'chat_message',
            label: 'Chat Message',
            class: ChatMessage::class,
            keys: ['id' => 'cmid', 'uuid' => 'uuid'],
        ));

        $this->entityType(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
    }

    public function routes(WaaseyaaRouter $router): void
    {
        // Dashboard (replaces separate brief and chat pages)
        $router->addRoute(
            'claudriel.dashboard',
            RouteBuilder::create('/')
                ->controller(DashboardController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        // Legacy: /brief still serves JSON for API consumers
        $router->addRoute(
            'claudriel.brief',
            RouteBuilder::create('/brief')
                ->controller(DayBriefController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        // Legacy: /chat redirects to dashboard
        $router->addRoute(
            'claudriel.chat',
            RouteBuilder::create('/chat')
                ->controller(DashboardController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        // SSE streams
        $router->addRoute(
            'claudriel.stream.brief',
            RouteBuilder::create('/stream/brief')
                ->controller(BriefStreamController::class.'::stream')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.stream.chat',
            RouteBuilder::create('/stream/chat/{messageId}')
                ->controller(ChatStreamController::class.'::stream')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        // Existing API routes
        $router->addRoute(
            'claudriel.commitment.update',
            RouteBuilder::create('/commitments/{uuid}')
                ->controller(CommitmentUpdateController::class.'::update')
                ->allowAll()
                ->methods('PATCH')
                ->build(),
        );

        $ingestRoute = RouteBuilder::create('/api/ingest')
            ->controller(IngestController::class.'::handle')
            ->allowAll()
            ->methods('POST')
            ->build();
        $ingestRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.ingest', $ingestRoute);

        $router->addRoute(
            'claudriel.api.context',
            RouteBuilder::create('/api/context')
                ->controller(ContextController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.api.chat.sessions.messages',
            RouteBuilder::create('/api/chat/sessions/{uuid}/messages')
                ->controller(ChatController::class.'::messages')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.api.chat.send',
            RouteBuilder::create('/api/chat/send')
                ->controller(ChatController::class.'::send')
                ->allowAll()
                ->methods('POST')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.api.workspaces',
            RouteBuilder::create('/api/workspaces')
                ->controller(WorkspaceApiController::class.'::list')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $createRoute = RouteBuilder::create('/api/workspaces')
            ->controller(WorkspaceApiController::class.'::create')
            ->allowAll()
            ->methods('POST')
            ->build();
        $createRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.workspaces.create', $createRoute);

        $router->addRoute(
            'claudriel.api.workspaces.show',
            RouteBuilder::create('/api/workspaces/{uuid}')
                ->controller(WorkspaceApiController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $updateRoute = RouteBuilder::create('/api/workspaces/{uuid}')
            ->controller(WorkspaceApiController::class.'::update')
            ->allowAll()
            ->methods('PATCH')
            ->build();
        $updateRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.workspaces.update', $updateRoute);

        $deleteRoute = RouteBuilder::create('/api/workspaces/{uuid}')
            ->controller(WorkspaceApiController::class.'::delete')
            ->allowAll()
            ->methods('DELETE')
            ->build();
        $deleteRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.workspaces.delete', $deleteRoute);

        $router->addRoute(
            'claudriel.ai.export.daily',
            RouteBuilder::create('/ai/export/daily.json')
                ->controller(TrainingExportController::class.'::daily')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.ai.export.sender',
            RouteBuilder::create('/ai/export/sender/{email}.json')
                ->controller(TrainingExportController::class.'::sender')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.ai.export.failures',
            RouteBuilder::create('/ai/export/failures.json')
                ->controller(TrainingExportController::class.'::failures')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.ai.self_assessment',
            RouteBuilder::create('/ai/self-assessment')
                ->controller(ExtractionSelfAssessmentController::class.'::index')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.ai.self_assessment_json',
            RouteBuilder::create('/ai/self-assessment.json')
                ->controller(ExtractionSelfAssessmentController::class.'::jsonView')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.ai.improvement_suggestions',
            RouteBuilder::create('/ai/improvement-suggestions')
                ->controller(ExtractionImprovementSuggestionController::class.'::index')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.ai.improvement_suggestions_json',
            RouteBuilder::create('/ai/improvement-suggestions.json')
                ->controller(ExtractionImprovementSuggestionController::class.'::jsonView')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $modelUpdateBatchRoute = RouteBuilder::create('/ai/model-update-batch')
            ->controller(ModelUpdateBatchController::class.'::create')
            ->allowAll()
            ->methods('POST')
            ->build();
        $modelUpdateBatchRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.ai.model_update_batch', $modelUpdateBatchRoute);

        $router->addRoute(
            'claudriel.ai.model_update_batch_show',
            RouteBuilder::create('/ai/model-update-batch/{batchId}.json')
                ->controller(ModelUpdateBatchController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.governance.integrity',
            RouteBuilder::create('/governance/integrity')
                ->controller(CodifiedContextIntegrityController::class.'::index')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.governance.integrity_json',
            RouteBuilder::create('/governance/integrity.json')
                ->controller(CodifiedContextIntegrityController::class.'::jsonView')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.platform.observability',
            RouteBuilder::create('/platform/observability')
                ->controller(ObservabilityDashboardController::class.'::index')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.platform.observability_json',
            RouteBuilder::create('/platform/observability.json')
                ->controller(ObservabilityDashboardController::class.'::jsonView')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.audit.commitment_extraction',
            RouteBuilder::create('/audit/commitment-extraction')
                ->controller(CommitmentExtractionAuditController::class.'::index')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.audit.commitment_extraction.show',
            RouteBuilder::create('/audit/commitment-extraction/log/{id}')
                ->controller(CommitmentExtractionAuditController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.audit.commitment_extraction.trends',
            RouteBuilder::create('/audit/commitment-extraction/trends')
                ->controller(CommitmentExtractionAuditController::class.'::trends')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.audit.commitment_extraction.trends_json',
            RouteBuilder::create('/audit/commitment-extraction/trends.json')
                ->controller(CommitmentExtractionAuditController::class.'::trendsJson')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.audit.commitment_extraction.sender',
            RouteBuilder::create('/audit/commitment-extraction/sender/{email}')
                ->controller(CommitmentExtractionAuditController::class.'::sender')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.audit.commitment_extraction.drift',
            RouteBuilder::create('/audit/commitment-extraction/drift')
                ->controller(CommitmentExtractionAuditController::class.'::drift')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.audit.commitment_extraction.drift_json',
            RouteBuilder::create('/audit/commitment-extraction/drift.json')
                ->controller(CommitmentExtractionAuditController::class.'::driftJson')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.audit.commitment_extraction.sender_drift',
            RouteBuilder::create('/audit/commitment-extraction/drift/sender/{email}')
                ->controller(CommitmentExtractionAuditController::class.'::senderDrift')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        // Catch-all: renders 404 for any unmatched path, preventing the
        // foundation render pipeline from failing on PathAliasResolver.
        // @see https://github.com/jonesrussell/claudriel/issues/21
        $router->addRoute(
            'claudriel.not_found',
            RouteBuilder::create('/{path}')
                ->controller(NotFoundController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->requirement('path', '.+')
                ->build(),
        );
    }

    public function commands(
        EntityTypeManager $entityTypeManager,
        PdoDatabase $database,
        EventDispatcherInterface $dispatcher,
    ): array {
        // Trigger getStorage() for each entity type so SqlSchemaHandler::ensureTable() runs.
        foreach (['mc_event', 'commitment', 'commitment_extraction_log', 'person', 'account', 'integration', 'skill', 'chat_session', 'chat_message', 'workspace'] as $typeId) {
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

        $personType = new EntityType(
            id: 'person',
            label: 'Person',
            class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        $personRepo = new EntityRepository(
            $personType,
            new SqlStorageDriver($resolver, 'pid'),
            $dispatcher,
        );

        $workspaceType = new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        $workspaceRepo = new EntityRepository(
            $workspaceType,
            new SqlStorageDriver($resolver, 'wid'),
            $dispatcher,
        );

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, new DriftDetector($commitmentRepo), $personRepo, $skillRepo, $workspaceRepo);
        $sessionStore = new BriefSessionStore($this->projectRoot.'/storage/brief-session.txt');

        return [
            new BriefCommand($assembler, $sessionStore),
            new CommitmentsCommand($commitmentRepo),
            new CommitmentUpdateCommand($commitmentRepo),
            new SkillsCommand($skillRepo),
            new WorkspacesCommand($workspaceRepo),
            new WorkspaceCreateCommand($workspaceRepo),
            new RecategorizeEventsCommand($entityTypeManager, new EventCategorizer(new AutomatedSenderDetector, $personRepo)),
        ];
    }
}
