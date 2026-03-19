<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Admin\Host\ClaudrielSurfaceHost;
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
use Claudriel\Command\IssueListCommand;
use Claudriel\Command\IssueRunCommand;
use Claudriel\Command\IssueStatusCommand;
use Claudriel\Command\RecategorizeEventsCommand;
use Claudriel\Command\SkillsCommand;
use Claudriel\Command\WorkspaceCloneCommand;
use Claudriel\Command\WorkspaceCreateCommand;
use Claudriel\Command\WorkspacePullCommand;
use Claudriel\Command\WorkspacesCommand;
use Claudriel\Controller\AdminUiController;
use Claudriel\Controller\Ai\ExtractionImprovementSuggestionController;
use Claudriel\Controller\Ai\ExtractionSelfAssessmentController;
use Claudriel\Controller\Ai\ModelUpdateBatchController;
use Claudriel\Controller\Ai\TrainingExportController;
use Claudriel\Controller\AppShellController;
use Claudriel\Controller\Audit\CommitmentExtractionAuditController;
use Claudriel\Controller\BriefStreamController;
use Claudriel\Controller\DashboardController;
use Claudriel\Controller\DayBriefController;
use Claudriel\Controller\GoogleSettingsController;
use Claudriel\Controller\Governance\CodifiedContextIntegrityController;
use Claudriel\Controller\NotFoundController;
use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Controller\PublicHomepageController;
use Claudriel\Controller\WorkspaceDriftController;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Domain\Git\DriftDetector as GitDriftDetector;
use Claudriel\Domain\Git\GitOperator;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Domain\IssueInstructionBuilder;
use Claudriel\Domain\IssueOrchestrator;
use Claudriel\Domain\Schedule\ScheduleSeriesResolver;
use Claudriel\Entity\Artifact;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Operation;
use Claudriel\Entity\Person;
use Claudriel\Entity\Project;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\Skill;
use Claudriel\Entity\TriageEntry;
use Claudriel\Entity\Workspace;
use Claudriel\Ingestion\EventCategorizer;
use Claudriel\Routing\AccountSessionMiddleware;
use Claudriel\Support\AutomatedSenderDetector;
use Claudriel\Support\DriftDetector;
use GraphQL\Type\Definition\Type;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\Connection\SingleConnectionResolver;
use Waaseyaa\EntityStorage\Driver\SqlStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\GitHub\GitHubClient;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ClaudrielServiceProvider extends ServiceProvider
{
    /**
     * Validate that critical environment variables are present at boot time.
     * Produces clear error messages so operators know exactly what to set.
     */
    public function boot(): void
    {
        $required = [
            'ANTHROPIC_API_KEY' => 'Anthropic API key for chat and AI pipelines',
            'AGENT_INTERNAL_SECRET' => 'HMAC secret for agent subprocess internal API auth (min 32 bytes)',
            'GOOGLE_CLIENT_ID' => 'Google OAuth client ID',
            'GOOGLE_CLIENT_SECRET' => 'Google OAuth client secret',
            'GOOGLE_REDIRECT_URI' => 'Google OAuth redirect URI',
        ];

        $missing = [];
        foreach ($required as $var => $description) {
            $value = $_ENV[$var] ?? getenv($var) ?: '';
            if ($value === '') {
                $missing[] = sprintf('  - %s (%s)', $var, $description);
            }
        }

        if ($missing !== []) {
            $env = $_ENV['CLAUDRIEL_ENV'] ?? getenv('CLAUDRIEL_ENV') ?: 'development';
            $message = sprintf(
                "Claudriel: %d required environment variable(s) missing:\n%s\nSee .env.example for reference.",
                count($missing),
                implode("\n", $missing),
            );

            if ($env === 'production') {
                throw new \RuntimeException($message);
            }

            // In development, log a warning but do not crash
            error_log($message);
        }
    }

    public function register(): void
    {
        // Entity types and domain-specific service wiring are handled by
        // per-domain providers registered in composer.json extra.waaseyaa.providers:
        //   AccountServiceProvider, IngestionServiceProvider, CommitmentServiceProvider,
        //   ChatServiceProvider, DayBriefServiceProvider, WorkspaceServiceProvider,
        //   OperationsServiceProvider, TemporalServiceProvider
    }

    public function graphqlMutationOverrides(EntityTypeManager $entityTypeManager): array
    {
        $resolver = new ScheduleSeriesResolver($entityTypeManager);

        return [
            'updateScheduleEntry' => [
                'args' => ['scope' => Type::string()],
                'resolve' => fn (mixed $root, array $args): array => $resolver->resolveUpdate(
                    $args['id'],
                    $args['input'],
                    $args['scope'] ?? 'occurrence',
                ),
            ],
            'deleteScheduleEntry' => [
                'args' => ['scope' => Type::string()],
                'resolve' => fn (mixed $root, array $args): array => $resolver->resolveDelete(
                    $args['id'],
                    $args['scope'] ?? 'occurrence',
                ),
            ],
            'deleteProject' => [
                'resolve' => function (mixed $root, array $args) use ($entityTypeManager): array {
                    $projectId = $args['id'];

                    // Nullify project_id on all associated workspaces
                    $workspaceStorage = $entityTypeManager->getStorage('workspace');
                    $allWorkspaces = $workspaceStorage->loadMultiple();
                    foreach ($allWorkspaces as $workspace) {
                        assert($workspace instanceof Workspace);
                        if ($workspace->get('project_id') === $projectId) {
                            $workspace->set('project_id', null);
                            $workspaceStorage->save($workspace);
                        }
                    }

                    // Delete the project
                    $projectStorage = $entityTypeManager->getStorage('project');
                    $project = $projectStorage->load($projectId);
                    if ($project !== null) {
                        $projectStorage->delete([$project]);
                    }

                    return ['deleted' => true];
                },
            ],
        ];
    }

    public function middleware(EntityTypeManager $entityTypeManager): array
    {
        return [
            new AccountSessionMiddleware($entityTypeManager),
        ];
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        // Public homepage
        $router->addRoute(
            'claudriel.homepage',
            RouteBuilder::create('/')
                ->controller(PublicHomepageController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.app',
            RouteBuilder::create('/app')
                ->controller(AppShellController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.admin',
            RouteBuilder::create('/admin')
                ->controller(AdminUiController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        // Admin surface routes (session, catalog, entity CRUD)
        $surfaceHost = new ClaudrielSurfaceHost(fn () => $entityTypeManager);
        AdminSurfaceServiceProvider::registerRoutes($router, $surfaceHost);

        // Schema endpoint for admin entity forms
        $router->addRoute(
            'claudriel.api.schema',
            RouteBuilder::create('/api/schema/{type}')
                ->controller(fn (array $params) => $surfaceHost->handleSchema($params['type'] ?? ''))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        // Legacy endpoints consumed by the frontend SPA
        $router->addRoute(
            'claudriel.admin.session',
            RouteBuilder::create('/admin/session')
                ->controller(fn () => $surfaceHost->handleLegacySession())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $adminLogoutRoute = RouteBuilder::create('/admin/logout')
            ->controller(fn () => $surfaceHost->handleLegacyLogout())
            ->allowAll()
            ->methods('POST')
            ->build();
        $adminLogoutRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.admin.logout', $adminLogoutRoute);

        $router->addRoute(
            'claudriel.admin.catchall',
            RouteBuilder::create('/admin/{path}')
                ->controller(AdminUiController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->requirement('path', '.+')
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

        // Workspace CRUD routes removed — now served by /api/graphql (v1.4 admin migration)
        // Schedule CRUD routes removed — now served by /api/graphql (v1.4 admin migration)
        // People CRUD routes removed — now served by /api/graphql (#180)
        // Triage CRUD routes removed — now served by /api/graphql (v1.4 admin migration)

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

        // Google settings API
        $router->addRoute(
            'claudriel.api.google.status',
            RouteBuilder::create('/api/google/status')
                ->controller(GoogleSettingsController::class.'::status')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $googleDisconnectRoute = RouteBuilder::create('/api/google/disconnect')
            ->controller(GoogleSettingsController::class.'::disconnect')
            ->allowAll()
            ->methods('POST')
            ->build();
        $googleDisconnectRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.google.disconnect', $googleDisconnectRoute);

        // Settings page
        $router->addRoute(
            'claudriel.settings',
            RouteBuilder::create('/settings')
                ->controller(GoogleSettingsController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        // Workspace API: repo connection and drift detection
        $driftControllerFactory = fn (): WorkspaceDriftController => new WorkspaceDriftController(
            $entityTypeManager,
            new GitRepositoryManager,
            new GitDriftDetector,
        );

        $connectRepoRoute = RouteBuilder::create('/api/workspaces/{uuid}/connect-repo')
            ->controller(fn (array $params) => $driftControllerFactory()->connectRepo($params))
            ->allowAll()
            ->methods('POST')
            ->build();
        $connectRepoRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.workspace.connect_repo', $connectRepoRoute);

        $router->addRoute(
            'claudriel.api.workspace.drift',
            RouteBuilder::create('/api/workspaces/{uuid}/drift')
                ->controller(fn (array $params) => $driftControllerFactory()->drift($params))
                ->allowAll()
                ->methods('GET')
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
        DatabaseInterface $database,
        EventDispatcherInterface $dispatcher,
    ): array {
        // Trigger getStorage() for each entity type so SqlSchemaHandler::ensureTable() runs.
        foreach (['mc_event', 'commitment', 'commitment_extraction_log', 'person', 'account', 'account_verification_token', 'account_password_reset_token', 'tenant', 'integration', 'skill', 'chat_session', 'chat_message', 'workspace', 'schedule_entry', 'artifact', 'operation', 'issue_run', 'project'] as $typeId) {
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

        // Issue orchestrator (optional — requires GITHUB_TOKEN)
        $orchestrator = $this->buildIssueOrchestrator($entityTypeManager, $gitOperator);

        $commands = [
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

        if ($orchestrator !== null) {
            $commands[] = new IssueRunCommand($orchestrator);
            $commands[] = new IssueListCommand($orchestrator);
            $commands[] = new IssueStatusCommand($orchestrator);
        }

        return $commands;
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

    private function buildIssueOrchestrator(
        EntityTypeManager $entityTypeManager,
        GitOperator $gitOperator,
    ): ?IssueOrchestrator {
        $githubToken = $_ENV['GITHUB_TOKEN'] ?? getenv('GITHUB_TOKEN') ?: null;
        if (! is_string($githubToken)) {
            return null;
        }

        $githubOwner = $_ENV['GITHUB_OWNER'] ?? getenv('GITHUB_OWNER') ?: '';
        $githubRepo = $_ENV['GITHUB_REPO'] ?? getenv('GITHUB_REPO') ?: '';

        return new IssueOrchestrator(
            entityTypeManager: $entityTypeManager,
            gitHubClient: new GitHubClient($githubToken, $githubOwner, $githubRepo),
            pipeline: null,
            instructionBuilder: new IssueInstructionBuilder,
            gitOperator: $gitOperator,
        );
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
