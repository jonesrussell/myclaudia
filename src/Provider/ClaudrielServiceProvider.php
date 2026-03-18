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
use Claudriel\Controller\ChatController;
use Claudriel\Controller\ChatStreamController;
use Claudriel\Controller\CommitmentUpdateController;
use Claudriel\Controller\ContextController;
use Claudriel\Controller\DashboardController;
use Claudriel\Controller\DayBriefController;
use Claudriel\Controller\GoogleOAuthController;
use Claudriel\Controller\GoogleSettingsController;
use Claudriel\Controller\Governance\CodifiedContextIntegrityController;
use Claudriel\Controller\IngestController;
use Claudriel\Controller\InternalGoogleController;
use Claudriel\Controller\NotFoundController;
use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Controller\PublicAccountController;
use Claudriel\Controller\PublicHomepageController;
use Claudriel\Controller\PublicPasswordResetController;
use Claudriel\Controller\PublicSessionController;
use Claudriel\Controller\TemporalNotificationApiController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Domain\Git\GitOperator;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Domain\IssueInstructionBuilder;
use Claudriel\Domain\IssueOrchestrator;
use Claudriel\Domain\Schedule\ScheduleSeriesResolver;
use Claudriel\Entity\Account;
use Claudriel\Entity\AccountPasswordResetToken;
use Claudriel\Entity\AccountVerificationToken;
use Claudriel\Entity\Artifact;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\Integration;
use Claudriel\Entity\IssueRun;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Operation;
use Claudriel\Entity\Person;
use Claudriel\Entity\ScheduleEntry;
use Claudriel\Entity\Skill;
use Claudriel\Entity\TemporalNotification;
use Claudriel\Entity\Tenant;
use Claudriel\Entity\TriageEntry;
use Claudriel\Entity\Workspace;
use Claudriel\Ingestion\EventCategorizer;
use Claudriel\Routing\AccountSessionMiddleware;
use Claudriel\Support\AutomatedSenderDetector;
use Claudriel\Support\DriftDetector;
use Claudriel\Support\GoogleTokenManager;
use Claudriel\Support\GoogleTokenManagerInterface;
use GraphQL\Type\Definition\Type;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Waaseyaa\AdminSurface\AdminSurfaceServiceProvider;
use Waaseyaa\Database\PdoDatabase;
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
        $this->entityType(new EntityType(
            id: 'account',
            label: 'Account',
            class: Account::class,
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'aid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'email' => ['type' => 'email', 'required' => true],
                'password' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'roles' => ['type' => 'string'],
                'permissions' => ['type' => 'string'],
                'email_verified_at' => ['type' => 'datetime'],
                'settings' => ['type' => 'text_long'],
                'metadata' => ['type' => 'text_long'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'account_verification_token',
            label: 'Account Verification Token',
            class: AccountVerificationToken::class,
            keys: ['id' => 'avtid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'avtid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'account_uuid' => ['type' => 'string', 'required' => true],
                'token' => ['type' => 'string', 'required' => true],
                'expires_at' => ['type' => 'datetime'],
                'used_at' => ['type' => 'datetime'],
                'metadata' => ['type' => 'text_long'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'account_password_reset_token',
            label: 'Account Password Reset Token',
            class: AccountPasswordResetToken::class,
            keys: ['id' => 'aprtid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'aprtid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'account_uuid' => ['type' => 'string', 'required' => true],
                'token' => ['type' => 'string', 'required' => true],
                'expires_at' => ['type' => 'datetime'],
                'used_at' => ['type' => 'datetime'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'tenant',
            label: 'Tenant',
            class: Tenant::class,
            keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'tid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'slug' => ['type' => 'string'],
                'metadata' => ['type' => 'text_long'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'mc_event',
            label: 'Event',
            class: McEvent::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'eid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'source' => ['type' => 'string'],
                'category' => ['type' => 'string'],
                'subject' => ['type' => 'string'],
                'body' => ['type' => 'text_long'],
                'sender_name' => ['type' => 'string'],
                'sender_email' => ['type' => 'string'],
                'external_id' => ['type' => 'string'],
                'content_hash' => ['type' => 'string'],
                'raw_payload' => ['type' => 'text_long'],
                'occurred_at' => ['type' => 'datetime'],
                'tenant_id' => ['type' => 'string'],
                'workspace_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'person',
            label: 'Person',
            class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'pid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'email' => ['type' => 'email', 'required' => true],
                'tier' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'latest_summary' => ['type' => 'string'],
                'last_interaction_at' => ['type' => 'datetime'],
                'last_inbox_category' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'triage_entry',
            label: 'Triage Entry',
            class: TriageEntry::class,
            keys: ['id' => 'teid', 'uuid' => 'uuid', 'label' => 'sender_name'],
            fieldDefinitions: [
                'teid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'sender_name' => ['type' => 'string', 'required' => true],
                'sender_email' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'occurred_at' => ['type' => 'datetime'],
                'external_id' => ['type' => 'string'],
                'content_hash' => ['type' => 'string'],
                'raw_payload' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'integration',
            label: 'Integration',
            class: Integration::class,
            keys: ['id' => 'iid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'iid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'type' => ['type' => 'string'],
                'config' => ['type' => 'text_long'],
                'status' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'cid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'title' => ['type' => 'string', 'required' => true],
                'status' => ['type' => 'string'],
                'confidence' => ['type' => 'float'],
                'due_date' => ['type' => 'datetime'],
                'person_uuid' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'commitment_extraction_log',
            label: 'Commitment Extraction Log',
            class: CommitmentExtractionLog::class,
            keys: ['id' => 'celid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'celid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'event_uuid' => ['type' => 'string'],
                'model' => ['type' => 'string'],
                'prompt_tokens' => ['type' => 'integer'],
                'completion_tokens' => ['type' => 'integer'],
                'candidates_count' => ['type' => 'integer'],
                'saved_count' => ['type' => 'integer'],
                'raw_response' => ['type' => 'text_long'],
                'failure_category' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'skill',
            label: 'Skill',
            class: Skill::class,
            keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'sid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'type' => ['type' => 'string'],
                'config' => ['type' => 'text_long'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'chat_session',
            label: 'Chat Session',
            class: ChatSession::class,
            keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'csid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'title' => ['type' => 'string'],
                'model' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'account_uuid' => ['type' => 'string'],
                'workspace_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'chat_message',
            label: 'Chat Message',
            class: ChatMessage::class,
            keys: ['id' => 'cmid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'cmid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'session_uuid' => ['type' => 'string', 'required' => true],
                'role' => ['type' => 'string', 'required' => true],
                'content' => ['type' => 'text_long'],
                'tool_calls' => ['type' => 'text_long'],
                'tool_results' => ['type' => 'text_long'],
                'token_count' => ['type' => 'integer'],
                'tenant_id' => ['type' => 'string'],
                'workspace_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'workspace',
            label: 'Workspace',
            class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'wid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'description' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'metadata' => ['type' => 'string'],
                'repo_path' => ['type' => 'string'],
                'repo_url' => ['type' => 'string'],
                'branch' => ['type' => 'string'],
                'codex_model' => ['type' => 'string'],
                'last_commit_hash' => ['type' => 'string'],
                'ci_status' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'schedule_entry',
            label: 'Schedule Entry',
            class: ScheduleEntry::class,
            keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'seid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'title' => ['type' => 'string', 'required' => true],
                'starts_at' => ['type' => 'datetime', 'required' => true],
                'ends_at' => ['type' => 'datetime'],
                'notes' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'external_id' => ['type' => 'string'],
                'calendar_id' => ['type' => 'string'],
                'recurring_series_id' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'raw_payload' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'artifact',
            label: 'Artifact',
            class: Artifact::class,
            keys: ['id' => 'artid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'artid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'type' => ['type' => 'string'],
                'workspace_uuid' => ['type' => 'string'],
                'repo_url' => ['type' => 'string'],
                'branch' => ['type' => 'string'],
                'local_path' => ['type' => 'string'],
                'last_commit' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'operation',
            label: 'Operation',
            class: Operation::class,
            keys: ['id' => 'opid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'opid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_id' => ['type' => 'string'],
                'input_instruction' => ['type' => 'text_long'],
                'generated_prompt' => ['type' => 'text_long'],
                'model_response' => ['type' => 'text_long'],
                'applied_patch' => ['type' => 'text_long'],
                'commit_hash' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'temporal_notification',
            label: 'Temporal Notification',
            class: TemporalNotification::class,
            keys: ['id' => 'tnid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'tnid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'title' => ['type' => 'string'],
                'message' => ['type' => 'text_long'],
                'type' => ['type' => 'string'],
                'state' => ['type' => 'string'],
                'scheduled_at' => ['type' => 'datetime'],
                'delivered_at' => ['type' => 'datetime'],
                'snoozed_until' => ['type' => 'datetime'],
                'workspace_uuid' => ['type' => 'string'],
                'actions' => ['type' => 'text_long'],
                'action_states' => ['type' => 'text_long'],
                'metadata' => ['type' => 'text_long'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'issue_run',
            label: 'Issue Run',
            class: IssueRun::class,
            keys: ['id' => 'irid', 'uuid' => 'uuid', 'label' => 'issue_title'],
            group: 'orchestration',
            fieldDefinitions: [
                'issue_number' => ['type' => 'integer', 'label' => 'Issue Number'],
                'issue_title' => ['type' => 'string', 'label' => 'Issue Title'],
                'issue_body' => ['type' => 'text_long', 'label' => 'Issue Body'],
                'milestone_title' => ['type' => 'string', 'label' => 'Milestone'],
                'workspace_id' => ['type' => 'integer', 'label' => 'Workspace ID'],
                'status' => ['type' => 'string', 'label' => 'Status'],
                'branch_name' => ['type' => 'string', 'label' => 'Branch Name'],
                'pr_url' => ['type' => 'string', 'label' => 'PR URL'],
                'last_agent_output' => ['type' => 'text_long', 'label' => 'Last Agent Output'],
                'event_log' => ['type' => 'text_long', 'label' => 'Event Log'],
            ],
        ));

        $this->singleton(GoogleTokenManagerInterface::class, function () {
            $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '';
            $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: '';

            return new GoogleTokenManager(
                $this->resolve(EntityTypeManager::class),
                $clientId,
                $clientSecret,
            );
        });

        $this->singleton(InternalApiTokenGenerator::class, function () {
            $secret = $_ENV['AGENT_INTERNAL_SECRET'] ?? getenv('AGENT_INTERNAL_SECRET') ?: '';
            $env = $_ENV['CLAUDRIEL_ENV'] ?? getenv('CLAUDRIEL_ENV') ?: 'development';

            if ($secret === '' || strlen($secret) < 32 || $secret === 'change-me-to-a-random-string-at-least-32-bytes') {
                $message = 'AGENT_INTERNAL_SECRET is missing, too short (min 32 bytes), or still set to the example default. Internal API endpoints are unprotected.';
                if ($env === 'production') {
                    throw new \RuntimeException($message);
                }
                error_log('[claudriel] WARNING: '.$message);
            }

            return new InternalApiTokenGenerator($secret);
        });

        $this->singleton(InternalGoogleController::class, function () {
            return new InternalGoogleController(
                $this->resolve(GoogleTokenManagerInterface::class),
                $this->resolve(InternalApiTokenGenerator::class),
            );
        });
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
            'claudriel.public.login_form',
            RouteBuilder::create('/login')
                ->controller(PublicSessionController::class.'::loginForm')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.password_reset_request_form',
            RouteBuilder::create('/forgot-password')
                ->controller(PublicPasswordResetController::class.'::requestForm')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $forgotPasswordRoute = RouteBuilder::create('/forgot-password')
            ->controller(PublicPasswordResetController::class.'::requestReset')
            ->allowAll()
            ->methods('POST')
            ->render()
            ->build();
        $router->addRoute('claudriel.public.password_reset_request', $forgotPasswordRoute);

        $router->addRoute(
            'claudriel.public.password_reset_check_email',
            RouteBuilder::create('/forgot-password/check-email')
                ->controller(PublicPasswordResetController::class.'::checkEmail')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.password_reset_form',
            RouteBuilder::create('/reset-password/{token}')
                ->controller(PublicPasswordResetController::class.'::resetForm')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $passwordResetRoute = RouteBuilder::create('/reset-password/{token}')
            ->controller(PublicPasswordResetController::class.'::resetPassword')
            ->allowAll()
            ->methods('POST')
            ->render()
            ->build();
        $router->addRoute('claudriel.public.password_reset_submit', $passwordResetRoute);

        $router->addRoute(
            'claudriel.public.password_reset_complete',
            RouteBuilder::create('/reset-password/complete')
                ->controller(PublicPasswordResetController::class.'::resetComplete')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $loginRoute = RouteBuilder::create('/login')
            ->controller(PublicSessionController::class.'::login')
            ->allowAll()
            ->methods('POST')
            ->render()
            ->build();
        $router->addRoute('claudriel.public.login_submit', $loginRoute);

        $logoutRoute = RouteBuilder::create('/logout')
            ->controller(PublicSessionController::class.'::logout')
            ->allowAll()
            ->methods('POST')
            ->render()
            ->build();
        $router->addRoute('claudriel.public.logout', $logoutRoute);

        $router->addRoute(
            'claudriel.public.session_state',
            RouteBuilder::create('/account/session')
                ->controller(PublicSessionController::class.'::sessionState')
                ->allowAll()
                ->methods('GET')
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

        // Commitment CRUD routes removed — now served by /api/graphql (#180)

        $ingestRoute = RouteBuilder::create('/api/ingest')
            ->controller(IngestController::class.'::handle')
            ->allowAll()
            ->methods('POST')
            ->build();
        $ingestRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.ingest', $ingestRoute);

        // Internal API routes (agent subprocess → PHP)
        $internalGmailListRoute = RouteBuilder::create('/api/internal/gmail/list')
            ->controller(InternalGoogleController::class.'::gmailList')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalGmailListRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.gmail.list', $internalGmailListRoute);

        $internalGmailReadRoute = RouteBuilder::create('/api/internal/gmail/read/{id}')
            ->controller(InternalGoogleController::class.'::gmailRead')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalGmailReadRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.gmail.read', $internalGmailReadRoute);

        $internalGmailSendRoute = RouteBuilder::create('/api/internal/gmail/send')
            ->controller(InternalGoogleController::class.'::gmailSend')
            ->allowAll()
            ->methods('POST')
            ->build();
        $internalGmailSendRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.gmail.send', $internalGmailSendRoute);

        $internalCalendarListRoute = RouteBuilder::create('/api/internal/calendar/list')
            ->controller(InternalGoogleController::class.'::calendarList')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalCalendarListRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.calendar.list', $internalCalendarListRoute);

        $internalCalendarCreateRoute = RouteBuilder::create('/api/internal/calendar/create')
            ->controller(InternalGoogleController::class.'::calendarCreate')
            ->allowAll()
            ->methods('POST')
            ->build();
        $internalCalendarCreateRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.calendar.create', $internalCalendarCreateRoute);

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

        // Google OAuth
        $router->addRoute(
            'claudriel.auth.google.redirect',
            RouteBuilder::create('/auth/google')
                ->controller(GoogleOAuthController::class.'::redirect')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $googleCallbackRoute = RouteBuilder::create('/auth/google/callback')
            ->controller(GoogleOAuthController::class.'::callback')
            ->allowAll()
            ->methods('GET')
            ->build();
        $googleCallbackRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.auth.google.callback', $googleCallbackRoute);

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
        foreach (['mc_event', 'commitment', 'commitment_extraction_log', 'person', 'account', 'account_verification_token', 'account_password_reset_token', 'tenant', 'integration', 'skill', 'chat_session', 'chat_message', 'workspace', 'schedule_entry', 'artifact', 'operation', 'issue_run'] as $typeId) {
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
