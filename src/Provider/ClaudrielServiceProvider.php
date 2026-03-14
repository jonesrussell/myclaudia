<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\AI\PromptBuilder;
use Claudriel\CLI\WorkspaceIterateCommand;
use Claudriel\CLI\WorkspaceLinkRepoCommand;
use Claudriel\CLI\WorkspaceOpsCommand;
use Claudriel\CLI\WorkspaceRunLoopCommand;
use Claudriel\CLI\WorkspaceStatusCommand;
use Claudriel\CLI\WorkspaceVerifyCommand;
use Claudriel\Command\BriefCommand;
use Claudriel\Command\CommitmentsCommand;
use Claudriel\Command\CommitmentUpdateCommand;
use Claudriel\Command\RecategorizeEventsCommand;
use Claudriel\Command\SkillsCommand;
use Claudriel\Command\WorkspaceCloneCommand;
use Claudriel\Command\WorkspaceCreateCommand;
use Claudriel\Command\WorkspacePullCommand;
use Claudriel\Command\WorkspacesCommand;
use Claudriel\Controller\Ai\ExtractionImprovementSuggestionController;
use Claudriel\Controller\Ai\ExtractionSelfAssessmentController;
use Claudriel\Controller\Ai\ModelUpdateBatchController;
use Claudriel\Controller\Ai\TrainingExportController;
use Claudriel\Controller\Audit\CommitmentExtractionAuditController;
use Claudriel\Controller\BriefStreamController;
use Claudriel\Controller\ChatController;
use Claudriel\Controller\ChatStreamController;
use Claudriel\Controller\CommitmentApiController;
use Claudriel\Controller\CommitmentUpdateController;
use Claudriel\Controller\ContextController;
use Claudriel\Controller\DashboardController;
use Claudriel\Controller\DayBriefController;
use Claudriel\Controller\Governance\CodifiedContextIntegrityController;
use Claudriel\Controller\IngestController;
use Claudriel\Controller\NotFoundController;
use Claudriel\Controller\PeopleApiController;
use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Controller\PublicAccountController;
use Claudriel\Controller\ScheduleApiController;
use Claudriel\Controller\TemporalNotificationApiController;
use Claudriel\Controller\TriageApiController;
use Claudriel\Controller\WorkspaceApiController;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Entity\Account;
use Claudriel\Entity\AccountVerificationToken;
use Claudriel\Entity\Artifact;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\Integration;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Operation;
use Claudriel\Entity\Person;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\Skill;
use Claudriel\Entity\TemporalNotification;
use Claudriel\Entity\TriageEntry;
use Claudriel\Entity\Workspace;
use Claudriel\Ingestion\EventCategorizer;
use Claudriel\Layer2\GitRepositoryManager;
use Claudriel\Service\GitOperator;
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
            id: 'account_verification_token',
            label: 'Account Verification Token',
            class: AccountVerificationToken::class,
            keys: ['id' => 'avtid', 'uuid' => 'uuid'],
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
            id: 'triage_entry',
            label: 'Triage Entry',
            class: TriageEntry::class,
            keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name'],
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

        $this->entityType(new EntityType(
            id: 'schedule_entry',
            label: 'Schedule Entry',
            class: ScheduleEntry::class,
            keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title'],
        ));

        $this->entityType(new EntityType(
            id: 'artifact',
            label: 'Artifact',
            class: Artifact::class,
            keys: ['id' => 'artid', 'uuid' => 'uuid', 'label' => 'name'],
        ));

        $this->entityType(new EntityType(
            id: 'operation',
            label: 'Operation',
            class: Operation::class,
            keys: ['id' => 'opid', 'uuid' => 'uuid'],
        ));

        $this->entityType(new EntityType(
            id: 'temporal_notification',
            label: 'Temporal Notification',
            class: TemporalNotification::class,
            keys: ['id' => 'tnid', 'uuid' => 'uuid'],
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

        $router->addRoute(
            'claudriel.public.signup_form',
            RouteBuilder::create('/signup')
                ->controller(PublicAccountController::class.'::signupForm')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.signup_check_email',
            RouteBuilder::create('/signup/check-email')
                ->controller(PublicAccountController::class.'::checkEmail')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $signupRoute = RouteBuilder::create('/signup')
            ->controller(PublicAccountController::class.'::signup')
            ->allowAll()
            ->methods('POST')
            ->render()
            ->build();
        $router->addRoute('claudriel.public.signup_submit', $signupRoute);

        $router->addRoute(
            'claudriel.public.verify_email',
            RouteBuilder::create('/verify-email/{token}')
                ->controller(PublicAccountController::class.'::verifyEmail')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.verification_result',
            RouteBuilder::create('/signup/verification-result')
                ->controller(PublicAccountController::class.'::verificationResult')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.onboarding_bootstrap',
            RouteBuilder::create('/onboarding/bootstrap')
                ->controller(PublicAccountController::class.'::onboardingBootstrap')
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

        $router->addRoute(
            'claudriel.api.commitments',
            RouteBuilder::create('/api/commitments')
                ->controller(CommitmentApiController::class.'::list')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $createCommitmentRoute = RouteBuilder::create('/api/commitments')
            ->controller(CommitmentApiController::class.'::create')
            ->allowAll()
            ->methods('POST')
            ->build();
        $createCommitmentRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.commitments.create', $createCommitmentRoute);

        $router->addRoute(
            'claudriel.api.commitments.show',
            RouteBuilder::create('/api/commitments/{uuid}')
                ->controller(CommitmentApiController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $updateCommitmentRoute = RouteBuilder::create('/api/commitments/{uuid}')
            ->controller(CommitmentApiController::class.'::update')
            ->allowAll()
            ->methods('PATCH')
            ->build();
        $updateCommitmentRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.commitments.update', $updateCommitmentRoute);

        $deleteCommitmentRoute = RouteBuilder::create('/api/commitments/{uuid}')
            ->controller(CommitmentApiController::class.'::delete')
            ->allowAll()
            ->methods('DELETE')
            ->build();
        $deleteCommitmentRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.commitments.delete', $deleteCommitmentRoute);

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

        $dismissTemporalNotificationRoute = RouteBuilder::create('/api/temporal-notifications/{uuid}/dismiss')
            ->controller(TemporalNotificationApiController::class.'::dismiss')
            ->allowAll()
            ->methods('POST')
            ->build();
        $dismissTemporalNotificationRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.temporal-notifications.dismiss', $dismissTemporalNotificationRoute);

        $snoozeTemporalNotificationRoute = RouteBuilder::create('/api/temporal-notifications/{uuid}/snooze')
            ->controller(TemporalNotificationApiController::class.'::snooze')
            ->allowAll()
            ->methods('POST')
            ->build();
        $snoozeTemporalNotificationRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.temporal-notifications.snooze', $snoozeTemporalNotificationRoute);

        $updateTemporalNotificationActionRoute = RouteBuilder::create('/api/temporal-notifications/{uuid}/actions/{action}')
            ->controller(TemporalNotificationApiController::class.'::updateAction')
            ->allowAll()
            ->methods('POST')
            ->build();
        $updateTemporalNotificationActionRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.temporal-notifications.actions', $updateTemporalNotificationActionRoute);

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
            'claudriel.api.schedule',
            RouteBuilder::create('/api/schedule')
                ->controller(ScheduleApiController::class.'::list')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $createScheduleRoute = RouteBuilder::create('/api/schedule')
            ->controller(ScheduleApiController::class.'::create')
            ->allowAll()
            ->methods('POST')
            ->build();
        $createScheduleRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.schedule.create', $createScheduleRoute);

        $router->addRoute(
            'claudriel.api.schedule.show',
            RouteBuilder::create('/api/schedule/{uuid}')
                ->controller(ScheduleApiController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $updateScheduleRoute = RouteBuilder::create('/api/schedule/{uuid}')
            ->controller(ScheduleApiController::class.'::update')
            ->allowAll()
            ->methods('PATCH')
            ->build();
        $updateScheduleRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.schedule.update', $updateScheduleRoute);

        $deleteScheduleRoute = RouteBuilder::create('/api/schedule/{uuid}')
            ->controller(ScheduleApiController::class.'::delete')
            ->allowAll()
            ->methods('DELETE')
            ->build();
        $deleteScheduleRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.schedule.delete', $deleteScheduleRoute);

        $router->addRoute(
            'claudriel.api.people',
            RouteBuilder::create('/api/people')
                ->controller(PeopleApiController::class.'::list')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $createPeopleRoute = RouteBuilder::create('/api/people')
            ->controller(PeopleApiController::class.'::create')
            ->allowAll()
            ->methods('POST')
            ->build();
        $createPeopleRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.people.create', $createPeopleRoute);

        $router->addRoute(
            'claudriel.api.people.show',
            RouteBuilder::create('/api/people/{uuid}')
                ->controller(PeopleApiController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $updatePeopleRoute = RouteBuilder::create('/api/people/{uuid}')
            ->controller(PeopleApiController::class.'::update')
            ->allowAll()
            ->methods('PATCH')
            ->build();
        $updatePeopleRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.people.update', $updatePeopleRoute);

        $deletePeopleRoute = RouteBuilder::create('/api/people/{uuid}')
            ->controller(PeopleApiController::class.'::delete')
            ->allowAll()
            ->methods('DELETE')
            ->build();
        $deletePeopleRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.people.delete', $deletePeopleRoute);

        $router->addRoute(
            'claudriel.api.triage',
            RouteBuilder::create('/api/triage')
                ->controller(TriageApiController::class.'::list')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $createTriageRoute = RouteBuilder::create('/api/triage')
            ->controller(TriageApiController::class.'::create')
            ->allowAll()
            ->methods('POST')
            ->build();
        $createTriageRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.triage.create', $createTriageRoute);

        $router->addRoute(
            'claudriel.api.triage.show',
            RouteBuilder::create('/api/triage/{uuid}')
                ->controller(TriageApiController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $updateTriageRoute = RouteBuilder::create('/api/triage/{uuid}')
            ->controller(TriageApiController::class.'::update')
            ->allowAll()
            ->methods('PATCH')
            ->build();
        $updateTriageRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.triage.update', $updateTriageRoute);

        $deleteTriageRoute = RouteBuilder::create('/api/triage/{uuid}')
            ->controller(TriageApiController::class.'::delete')
            ->allowAll()
            ->methods('DELETE')
            ->build();
        $deleteTriageRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.triage.delete', $deleteTriageRoute);

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
        foreach (['mc_event', 'commitment', 'commitment_extraction_log', 'person', 'account', 'account_verification_token', 'integration', 'skill', 'chat_session', 'chat_message', 'workspace', 'schedule_entry', 'artifact', 'operation'] as $typeId) {
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

        $scheduleType = new EntityType(
            id: 'schedule_entry',
            label: 'Schedule Entry',
            class: ScheduleEntry::class,
            keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title'],
        );
        $scheduleRepo = new EntityRepository(
            $scheduleType,
            new SqlStorageDriver($resolver, 'seid'),
            $dispatcher,
        );

        $triageType = new EntityType(
            id: 'triage_entry',
            label: 'Triage Entry',
            class: TriageEntry::class,
            keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name'],
        );
        $triageRepo = new EntityRepository(
            $triageType,
            new SqlStorageDriver($resolver, 'teid'),
            $dispatcher,
        );

        $artifactType = new EntityType(
            id: 'artifact',
            label: 'Artifact',
            class: Artifact::class,
            keys: ['id' => 'artid', 'uuid' => 'uuid', 'label' => 'name'],
        );
        $artifactRepo = new EntityRepository(
            $artifactType,
            new SqlStorageDriver($resolver, 'artid'),
            $dispatcher,
        );

        $operationType = new EntityType(
            id: 'operation',
            label: 'Operation',
            class: Operation::class,
            keys: ['id' => 'opid', 'uuid' => 'uuid'],
        );
        $operationRepo = new EntityRepository(
            $operationType,
            new SqlStorageDriver($resolver, 'opid'),
            $dispatcher,
        );

        $gitRepositoryManager = new GitRepositoryManager;
        $promptBuilder = new PromptBuilder;
        $gitOperator = new GitOperator;
        $this->ensureClaudrielSystemWorkspace($workspaceRepo, $artifactRepo, $gitRepositoryManager);

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, new DriftDetector($commitmentRepo), $personRepo, $skillRepo, $scheduleRepo, $workspaceRepo, $triageRepo);
        $sessionStore = new BriefSessionStore($this->projectRoot.'/storage/brief-session.txt');

        return [
            new BriefCommand($assembler, $sessionStore),
            new CommitmentsCommand($commitmentRepo),
            new CommitmentUpdateCommand($commitmentRepo),
            new SkillsCommand($skillRepo),
            new WorkspacesCommand($workspaceRepo),
            new WorkspaceCreateCommand($workspaceRepo),
            new WorkspaceCloneCommand($workspaceRepo, $artifactRepo, $gitRepositoryManager),
            new WorkspacePullCommand($workspaceRepo, $artifactRepo, $gitRepositoryManager),
            new WorkspaceIterateCommand($workspaceRepo, $operationRepo, $promptBuilder, $gitOperator),
            new WorkspaceStatusCommand($workspaceRepo),
            new WorkspaceLinkRepoCommand($workspaceRepo),
            new WorkspaceRunLoopCommand($workspaceRepo, $operationRepo, $promptBuilder, $gitOperator),
            new WorkspaceOpsCommand($workspaceRepo, $operationRepo),
            new WorkspaceVerifyCommand($workspaceRepo),
            new RecategorizeEventsCommand($entityTypeManager, new EventCategorizer(new AutomatedSenderDetector, $personRepo)),
        ];
    }

    private function ensureClaudrielSystemWorkspace(
        EntityRepository $workspaceRepo,
        EntityRepository $artifactRepo,
        GitRepositoryManager $gitRepositoryManager,
    ): void {
        $workspace = $this->findWorkspaceByName($workspaceRepo, 'Claudriel System');

        if (! $workspace instanceof Workspace) {
            $workspace = new Workspace([
                'name' => 'Claudriel System',
                'description' => 'System workspace for the Claudriel repository.',
            ]);
            $workspaceRepo->save($workspace);
        }

        $workspaceUuid = (string) $workspace->get('uuid');
        $artifact = $this->findRepoArtifact($artifactRepo, $workspaceUuid);

        if (! $artifact instanceof Artifact) {
            $artifact = new Artifact([
                'name' => 'Claudriel Repository',
                'workspace_uuid' => $workspaceUuid,
                'type' => 'repo',
            ]);
        }

        $artifact->set('workspace_uuid', $workspaceUuid);
        $artifact->set('type', 'repo');
        $artifact->set('repo_url', $this->detectClaudrielRepositoryUrl());
        $artifact->set('branch', 'main');
        $artifact->set('local_path', $gitRepositoryManager->buildWorkspaceRepoPath($workspaceUuid));

        $artifactRepo->save($artifact);
    }

    private function findWorkspaceByName(EntityRepository $workspaceRepo, string $name): ?Workspace
    {
        $results = $workspaceRepo->findBy(['name' => $name]);
        $workspace = $results[0] ?? null;

        return $workspace instanceof Workspace ? $workspace : null;
    }

    private function findRepoArtifact(EntityRepository $artifactRepo, string $workspaceUuid): ?Artifact
    {
        $results = $artifactRepo->findBy(['workspace_uuid' => $workspaceUuid, 'type' => 'repo']);
        $artifact = $results[0] ?? null;

        return $artifact instanceof Artifact ? $artifact : null;
    }

    private function detectClaudrielRepositoryUrl(): string
    {
        $output = shell_exec(sprintf(
            'git -C %s remote get-url origin 2>/dev/null',
            escapeshellarg($this->projectRoot),
        ));

        $repoUrl = trim((string) $output);

        return $repoUrl !== '' ? $repoUrl : 'git@github.com:jonesrussell/claudriel.git';
    }
}
