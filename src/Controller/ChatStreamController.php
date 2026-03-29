<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Domain\Chat\ChatSystemPromptBuilder;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\Chat\IssueIntentDetector;
use Claudriel\Domain\Chat\NativeAgentClient;
use Claudriel\Domain\Chat\Tool\BriefGenerateTool;
use Claudriel\Domain\Chat\Tool\CalendarCreateTool;
use Claudriel\Domain\Chat\Tool\CalendarListTool;
use Claudriel\Domain\Chat\Tool\CodeTaskCreateTool;
use Claudriel\Domain\Chat\Tool\CodeTaskStatusTool;
use Claudriel\Domain\Chat\Tool\CommitmentListTool;
use Claudriel\Domain\Chat\Tool\CommitmentUpdateTool;
use Claudriel\Domain\Chat\Tool\GmailListTool;
use Claudriel\Domain\Chat\Tool\GmailReadTool;
use Claudriel\Domain\Chat\Tool\GmailSendTool;
use Claudriel\Domain\Chat\Tool\RepoCloneTool;
use Claudriel\Domain\Chat\Tool\SpecialistExecuteTool;
use Claudriel\Domain\Chat\Tool\SpecialistListTool;
use Claudriel\Domain\Chat\Tool\WorkspaceCreateTool;
use Claudriel\Domain\Chat\Tool\WorkspaceDeleteTool;
use Claudriel\Domain\Chat\Tool\WorkspaceListTool;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\IssueOrchestrator;
use Claudriel\Domain\Memory\RehearsalService;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\MemoryAccessEvent;
use Claudriel\Entity\Workspace;
use Claudriel\Routing\RequestScopeViolation;
use Claudriel\Routing\TenantWorkspaceResolver;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Claudriel\Support\DriftDetector;
use Claudriel\Support\OAuthTokenManagerInterface;
use Claudriel\Support\StorageRepositoryAdapter;
use Claudriel\Temporal\TemporalContextFactory;
use Claudriel\Temporal\TimeSnapshot;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ChatStreamController
{
    private const DEFAULT_ANTHROPIC_MODEL = 'claude-sonnet-4-6';

    /** @var array<string, true> */
    private const ALLOWED_ANTHROPIC_MODELS = [
        'claude-opus-4-6' => true,
        'claude-sonnet-4-6' => true,
        'claude-haiku-4-5-20251001' => true,
    ];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $agentClientFactory = null,
        private readonly ?IssueOrchestrator $orchestrator = null,
        private readonly ?RehearsalService $rehearsalService = null,
        private readonly ?OAuthTokenManagerInterface $oauthTokenManager = null,
    ) {}

    /**
     * GET /stream/chat/{messageId} — SSE stream of Anthropic response tokens.
     */
    public function stream(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): StreamedResponse|SsrResponse
    {
        set_time_limit(0);

        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $requestScope = $resolver->resolve($query, $account, $httpRequest);
        } catch (RequestScopeViolation $exception) {
            return $this->jsonError($exception->getMessage(), $exception->statusCode());
        }

        $messageId = $params['messageId'] ?? '';

        // Find the user message
        $msgStorage = $this->entityTypeManager->getStorage('chat_message');
        $ids = $msgStorage->getQuery()->condition('uuid', $messageId)->execute();
        if ($ids === []) {
            return new SsrResponse(
                content: json_encode(['error' => 'Message not found']),
                statusCode: 404,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $userMsg = $msgStorage->load(reset($ids));
        if (! $userMsg instanceof ChatMessage) {
            return new SsrResponse(
                content: json_encode(['error' => 'Message not found']),
                statusCode: 404,
                headers: ['Content-Type' => 'application/json'],
            );
        }
        $sessionUuid = $userMsg->get('session_uuid');
        $tenantId = $this->resolveMessageTenantId($userMsg);
        $workspaceId = $this->resolveMessageWorkspaceId($userMsg);

        if ($requestScope->tenantId !== $tenantId) {
            return $this->jsonError('Message not found', 404);
        }

        if ($requestScope->workspaceId() !== null && $requestScope->workspaceId() !== $workspaceId) {
            return $this->jsonError('Message not found', 404);
        }

        $orchestratorResponse = $this->handleOrchestratorIntent($userMsg, $msgStorage);
        if ($orchestratorResponse instanceof StreamedResponse) {
            return $orchestratorResponse;
        }

        // Check API key
        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return new SsrResponse(
                content: json_encode(['error' => 'Chat not configured. Set ANTHROPIC_API_KEY.']),
                statusCode: 503,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $requestId = $this->resolveRequestId($httpRequest, $query, (string) $messageId);
        $snapshot = (new TemporalContextFactory($this->entityTypeManager))->snapshotForInteraction(
            scopeKey: 'chat-stream:'.$requestId,
            tenantId: $tenantId,
            workspaceUuid: $workspaceId,
            account: $account,
            requestTimezone: $this->resolveRequestedTimezone($httpRequest, $query),
        );

        return new StreamedResponse(
            function () use ($sessionUuid, $apiKey, $msgStorage, $tenantId, $workspaceId, $snapshot, $account): void {
                set_time_limit(0);
                if (session_status() === PHP_SESSION_ACTIVE) {
                    session_write_close();
                }
                $this->streamTokens($sessionUuid, $apiKey, $msgStorage, $tenantId, $workspaceId, $snapshot, $account);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function streamTokens(string $sessionUuid, string $apiKey, mixed $msgStorage, string $tenantId, ?string $workspaceUuid = null, ?TimeSnapshot $snapshot = null, mixed $account = null): void
    {
        $snapshot ??= (new TemporalContextFactory($this->entityTypeManager))->snapshotForInteraction(
            scopeKey: 'chat-stream:'.$sessionUuid,
            tenantId: $tenantId,
            workspaceUuid: $workspaceUuid,
        );
        echo "retry: 3000\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();

        // Load conversation history
        $allMsgIds = $msgStorage->getQuery()->execute();
        $allMessages = $msgStorage->loadMultiple($allMsgIds);
        $sessionMessages = [];
        foreach ($allMessages as $msg) {
            if ($msg->get('session_uuid') === $sessionUuid && $this->resolveMessageTenantId($msg) === $tenantId) {
                $sessionMessages[] = $msg;
            }
        }
        usort($sessionMessages, fn ($a, $b) => ($a->get('created_at') ?? '') <=> ($b->get('created_at') ?? ''));

        $apiMessages = $this->trimConversationHistory($sessionMessages);

        // Build system prompt (tools always available)
        $projectRoot = $this->resolveProjectRoot();
        $promptBuilder = $this->buildPromptBuilder($projectRoot);
        $workspace = $workspaceUuid !== null ? $this->findWorkspaceByUuid($workspaceUuid, $tenantId) : null;
        $activeWorkspace = $workspace?->get('name');
        $resolvedModel = $this->resolveChatModel($workspace);

        [$taskTypeOverride, $turnLimitOverride, $turnsConsumedStart] = $this->resolveSessionTurnBudgetOverrides($sessionUuid);
        $systemPrompt = $promptBuilder->build($tenantId, activeWorkspace: is_string($activeWorkspace) ? $activeWorkspace : null, snapshot: $snapshot);

        $onToken = function (string $token): void {
            $this->emitSseEvent('chat-token', ['token' => $token]);
        };

        $onDone = function (string $fullResponse) use ($sessionUuid, $msgStorage, $tenantId, $workspaceUuid, $snapshot): void {
            $assistantMsg = new ChatMessage([
                'uuid' => $this->generateUuid(),
                'session_uuid' => $sessionUuid,
                'role' => 'assistant',
                'content' => $fullResponse,
                'created_at' => $snapshot->utc()->format('c'),
                'tenant_id' => $tenantId,
                'workspace_id' => $workspaceUuid,
            ]);
            $msgStorage->save($assistantMsg);

            $this->emitSseEvent('chat-done', ['done' => true, 'full_response' => $fullResponse]);
        };

        $onError = function (string $error): void {
            $this->emitSseEvent('chat-error', ['error' => $error]);
        };

        $onProgress = function (array $payload): void {
            $normalized = $this->normalizeProgressPayload($payload);
            if ($normalized === null) {
                return;
            }

            $this->emitSseEvent('chat-progress', $normalized);
        };

        $onNeedsContinuation = function (array $payload) use ($sessionUuid): void {
            $this->emitSseEvent('chat-needs-continuation', [
                'session_uuid' => $sessionUuid,
                'turns_consumed' => $payload['turns_consumed'] ?? 0,
                'message' => $payload['message'] ?? 'The agent needs more turns to complete this task.',
            ]);
        };
        $onTelemetry = function (array $payload) use ($sessionUuid, $tenantId, $workspaceUuid): void {
            $this->recordTurnTelemetry($sessionUuid, $tenantId, $workspaceUuid, $payload);
        };

        $authenticatedAccount = $this->resolveAuthenticatedAccount($account);
        $accountId = $authenticatedAccount?->getUuid() ?? $tenantId;
        $turnLimitsOverride = $this->resolveAccountTurnLimitsOverride($authenticatedAccount);

        $this->emitSseEvent('chat-progress', [
            'phase' => 'prepare',
            'summary' => 'Starting agent',
            'level' => 'info',
        ]);

        $client = $this->createAgentClient($accountId, $tenantId, $resolvedModel);

        $client->stream(
            systemPrompt: $systemPrompt,
            messages: $apiMessages,
            accountId: $accountId,
            tenantId: $tenantId,
            apiBase: '',
            apiToken: '',
            onToken: $onToken,
            onDone: $onDone,
            onError: $onError,
            onProgress: $onProgress,
            model: $resolvedModel,
            onNeedsContinuation: $onNeedsContinuation,
            onTelemetry: $onTelemetry,
            taskTypeOverride: $taskTypeOverride,
            turnLimitOverride: $turnLimitOverride,
            turnsConsumedStart: $turnsConsumedStart,
            turnLimitsOverride: $turnLimitsOverride,
        );
    }

    private function getApiKey(): ?string
    {
        $key = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: null;

        return is_string($key) ? $key : null;
    }

    private function emitSseEvent(string $event, array $payload): void
    {
        $data = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        echo "event: {$event}\ndata: {$data}\n\n";
        if (ob_get_level() > 0) {
            ob_flush();
        }
        flush();
    }

    private function normalizeProgressPayload(array $payload): ?array
    {
        $phase = trim((string) ($payload['phase'] ?? ''));
        $summary = preg_replace('/\s+/u', ' ', trim((string) ($payload['summary'] ?? '')));
        $level = trim((string) ($payload['level'] ?? 'info'));

        if ($phase === '' || $summary === '') {
            return null;
        }

        $summary = mb_substr($summary, 0, 140);
        $allowedLevels = ['info', 'success', 'warning', 'error'];
        if (! in_array($level, $allowedLevels, true)) {
            $level = 'info';
        }

        return [
            'phase' => $phase,
            'summary' => $summary,
            'level' => $level,
        ];
    }

    private function resolveProjectRoot(): string
    {
        $root = getenv('CLAUDRIEL_ROOT');
        if (is_string($root) && $root !== '' && is_dir($root)) {
            return $root;
        }
        $dir = __DIR__;
        while ($dir !== '/' && $dir !== '') {
            if (is_file($dir.'/composer.json')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        return getcwd() ?: '/tmp';
    }

    private function buildPromptBuilder(string $projectRoot): ChatSystemPromptBuilder
    {
        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
        $skillStorage = $this->entityTypeManager->getStorage('skill');

        $eventRepo = new StorageRepositoryAdapter($eventStorage);
        $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
        $skillRepo = new StorageRepositoryAdapter($skillStorage);
        $driftDetector = new DriftDetector($commitmentRepo);

        $personRepo = null;
        try {
            $personRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('person'));
        } catch (\Throwable) {
        }

        $triageRepo = null;
        try {
            $triageRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('triage_entry'));
        } catch (\Throwable) {
        }

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $personRepo, $skillRepo, null, null, $triageRepo);

        return new ChatSystemPromptBuilder($assembler, $projectRoot);
    }

    private function createAgentClient(string $accountId, string $tenantId, ?string $model = null): NativeAgentClient
    {
        if (is_callable($this->agentClientFactory)) {
            return ($this->agentClientFactory)();
        }

        $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '';
        $model ??= $this->resolveDefaultModel();

        $tools = $this->buildAgentTools($accountId, $tenantId);

        return new NativeAgentClient(
            $apiKey,
            $tools,
            $model,
            function (string $toolName, mixed $result, string $eventTenantId): void {
                $this->recordMemoryAccessEvents($toolName, $result, $eventTenantId);
            },
        );
    }

    private function recordMemoryAccessEvents(string $toolName, mixed $result, string $tenantId): void
    {
        try {
            $storage = $this->entityTypeManager->getStorage('memory_access_event');
        } catch (\Throwable) {
            return;
        }

        $refs = $this->extractEntityReferencesFromToolResult($toolName, $result);
        if ($refs === []) {
            $refs[] = ['entity_type' => null, 'entity_uuid' => null];
        }

        $timestamp = (new \DateTimeImmutable)->format('c');
        foreach ($refs as $ref) {
            $event = new MemoryAccessEvent([
                'uuid' => $this->generateUuid(),
                'entity_type' => $ref['entity_type'],
                'entity_uuid' => $ref['entity_uuid'],
                'tool_name' => $toolName,
                'tenant_id' => $tenantId,
                'accessed_at' => $timestamp,
                'metadata' => json_encode([
                    'result_keys' => is_array($result) ? array_map('strval', array_keys($result)) : [],
                ], JSON_THROW_ON_ERROR),
            ]);
            $storage->save($event);

            if ($this->rehearsalService !== null && is_string($ref['entity_type']) && is_string($ref['entity_uuid'])) {
                $this->rehearsalService->recordAccess($ref['entity_type'], $ref['entity_uuid']);
            }
        }
    }

    /**
     * @return list<array{entity_type: ?string, entity_uuid: ?string}>
     */
    private function extractEntityReferencesFromToolResult(string $toolName, mixed $result): array
    {
        if (! is_array($result)) {
            return [];
        }

        $map = [
            'commitment_list' => 'commitment',
            'commitment_update' => 'commitment',
            'workspace_list' => 'workspace',
            'workspace_create' => 'workspace',
            'workspace_delete' => 'workspace',
        ];

        $entityType = $map[$toolName] ?? null;
        if ($entityType === null) {
            return [];
        }

        $rows = [];
        if (isset($result['items']) && is_array($result['items'])) {
            $rows = $result['items'];
        } else {
            $rows = [$result];
        }

        $refs = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $uuid = $row['uuid'] ?? null;
            if (is_string($uuid) && $uuid !== '') {
                $refs[] = ['entity_type' => $entityType, 'entity_uuid' => $uuid];
            }
        }

        return $refs;
    }

    private function resolveChatModel(?Workspace $workspace): string
    {
        $workspaceModel = $workspace?->get('anthropic_model');
        if (is_string($workspaceModel)) {
            $trimmed = trim($workspaceModel);
            if ($trimmed !== '' && isset(self::ALLOWED_ANTHROPIC_MODELS[$trimmed])) {
                return $trimmed;
            }
        }

        return $this->resolveDefaultModel();
    }

    private function resolveDefaultModel(): string
    {
        $configured = $_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: self::DEFAULT_ANTHROPIC_MODEL;

        if (is_string($configured)) {
            $trimmed = trim($configured);
            if ($trimmed !== '' && isset(self::ALLOWED_ANTHROPIC_MODELS[$trimmed])) {
                return $trimmed;
            }
        }

        return self::DEFAULT_ANTHROPIC_MODEL;
    }

    /**
     * @return list<AgentToolInterface>
     */
    private function buildAgentTools(string $accountId, string $tenantId): array
    {
        $tools = [];

        // Google tools (Gmail + Calendar) — requires OAuthTokenManager
        try {
            $tokenManager = $this->resolveOAuthTokenManager();
            if ($tokenManager !== null) {
                $tools[] = new GmailListTool($tokenManager, $accountId);
                $tools[] = new GmailReadTool($tokenManager, $accountId);
                $tools[] = new GmailSendTool($tokenManager, $accountId);
                $tools[] = new CalendarListTool($tokenManager, $accountId);
                $tools[] = new CalendarCreateTool($tokenManager, $accountId);
            }
        } catch (\Throwable) {
            // Google integration not configured, skip Gmail/Calendar tools
        }

        // Workspace tools
        try {
            $workspaceStorage = $this->entityTypeManager->getStorage('workspace');
            $workspaceRepo = new StorageRepositoryAdapter($workspaceStorage);
            $tools[] = new WorkspaceListTool($workspaceRepo, $tenantId);
            $tools[] = new WorkspaceCreateTool($workspaceRepo, $tenantId, $accountId);
            $tools[] = new WorkspaceDeleteTool($workspaceRepo, $tenantId);
        } catch (\Throwable) {
            // Workspace entity type not registered
        }

        // Commitment tools
        try {
            $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
            $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
            $tools[] = new CommitmentListTool($commitmentRepo, $tenantId);
            $tools[] = new CommitmentUpdateTool($commitmentRepo, $tenantId);
        } catch (\Throwable) {
            // Commitment entity type not registered
        }

        // Brief generation tool
        try {
            $eventStorage = $this->entityTypeManager->getStorage('mc_event');
            $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
            $skillStorage = $this->entityTypeManager->getStorage('skill');
            $eventRepo = new StorageRepositoryAdapter($eventStorage);
            $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
            $skillRepo = new StorageRepositoryAdapter($skillStorage);
            $driftDetector = new DriftDetector($commitmentRepo);

            $personRepo = null;
            try {
                $personRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('person'));
            } catch (\Throwable) {
            }

            $triageRepo = null;
            try {
                $triageRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('triage_entry'));
            } catch (\Throwable) {
            }

            $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $personRepo, $skillRepo, null, null, $triageRepo);
            $tools[] = new BriefGenerateTool($assembler, $tenantId);
        } catch (\Throwable) {
            // Brief dependencies not available
        }

        // Code task + repo tools — require AGENT_INTERNAL_SECRET
        $internalSecret = $_ENV['AGENT_INTERNAL_SECRET'] ?? getenv('AGENT_INTERNAL_SECRET') ?: '';
        if ($internalSecret !== '') {
            $tokenGen = new InternalApiTokenGenerator($internalSecret);
            $apiBase = $_ENV['CLAUDRIEL_API_URL'] ?? getenv('CLAUDRIEL_API_URL') ?: 'http://localhost:8081';

            $tools[] = new CodeTaskCreateTool($apiBase, $accountId, $tenantId, $tokenGen);
            $tools[] = new RepoCloneTool($apiBase, $accountId, $tenantId, $tokenGen);

            try {
                $codeTaskStorage = $this->entityTypeManager->getStorage('code_task');
                $codeTaskRepo = new StorageRepositoryAdapter($codeTaskStorage);
                $tools[] = new CodeTaskStatusTool($codeTaskRepo);
            } catch (\Throwable) {
                // code_task entity type not registered
            }
        }

        // Agency specialists (optional sidecar)
        $agencyUrl = getenv('AGENCY_AGENTS_URL');
        if ($agencyUrl !== false && $agencyUrl !== '') {
            $tools[] = new SpecialistListTool($agencyUrl);
            $tools[] = new SpecialistExecuteTool($agencyUrl);
        }

        return $tools;
    }

    private function resolveOAuthTokenManager(): ?OAuthTokenManagerInterface
    {
        if ($this->oauthTokenManager !== null) {
            return $this->oauthTokenManager;
        }

        $clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '';
        $clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: '';

        if ($clientId === '' || $clientSecret === '') {
            return null;
        }

        return null;
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF), random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
        );
    }

    private function handleOrchestratorIntent(ChatMessage $userMsg, mixed $msgStorage): ?StreamedResponse
    {
        if ($this->orchestrator === null) {
            return null;
        }

        $intent = IssueIntentDetector::detect((string) $userMsg->get('content'));
        if ($intent === null) {
            return null;
        }

        $responseText = match ($intent->action) {
            'run_issue' => $this->handleRunIssue($intent->params['issueNumber']),
            'show_run' => $this->handleShowRun($intent->params['runId']),
            'list_runs' => $this->handleListRuns(),
            'show_diff' => $this->handleShowDiff($intent->params['runId']),
            'pause_run' => $this->handlePauseRun($intent->params['runId']),
            'resume_run' => $this->handleResumeRun($intent->params['runId']),
            'abort_run' => $this->handleAbortRun($intent->params['runId']),
            default => 'Unknown orchestrator command.',
        };

        return new StreamedResponse(
            function () use ($userMsg, $msgStorage, $responseText): void {
                echo "retry: 3000\n\n";

                $assistantMsg = new ChatMessage([
                    'uuid' => $this->generateUuid(),
                    'session_uuid' => $userMsg->get('session_uuid'),
                    'role' => 'assistant',
                    'content' => $responseText,
                    'created_at' => (new \DateTimeImmutable)->format('c'),
                    'tenant_id' => $this->resolveMessageTenantId($userMsg),
                    'workspace_id' => $this->resolveMessageWorkspaceId($userMsg),
                ]);
                $msgStorage->save($assistantMsg);

                $this->emitSseEvent('chat-done', ['done' => true, 'full_response' => $responseText]);
            },
            200,
            [
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
                'X-Accel-Buffering' => 'no',
            ],
        );
    }

    private function handleRunIssue(int $issueNumber): string
    {
        try {
            $run = $this->orchestrator->createRun($issueNumber);
            $this->orchestrator->startRun($run);

            return $this->orchestrator->summarizeRun($run);
        } catch (\Throwable $e) {
            return "Failed to start run for issue #{$issueNumber}: {$e->getMessage()}";
        }
    }

    private function handleShowRun(string $runId): string
    {
        $run = $this->orchestrator->getRun($runId);

        return $run !== null
            ? $this->orchestrator->summarizeRun($run)
            : "Run {$runId} not found.";
    }

    private function handleListRuns(): string
    {
        $runs = $this->orchestrator->listRuns();
        if ($runs === []) {
            return 'No issue runs found.';
        }

        return implode("\n\n---\n\n", array_map(
            fn ($run) => $this->orchestrator->summarizeRun($run),
            $runs,
        ));
    }

    private function handleShowDiff(string $runId): string
    {
        $run = $this->orchestrator->getRun($runId);
        if ($run === null) {
            return "Run {$runId} not found.";
        }
        $diff = $this->orchestrator->getWorkspaceDiff($run);

        return $diff !== '' ? "```diff\n{$diff}\n```" : 'No changes detected.';
    }

    private function handlePauseRun(string $runId): string
    {
        $run = $this->orchestrator->getRun($runId);
        if ($run === null) {
            return "Run {$runId} not found.";
        }
        $this->orchestrator->pauseRun($run);

        return "Run paused.\n\n".$this->orchestrator->summarizeRun($run);
    }

    private function handleResumeRun(string $runId): string
    {
        $run = $this->orchestrator->getRun($runId);
        if ($run === null) {
            return "Run {$runId} not found.";
        }
        $this->orchestrator->resumeRun($run);

        return "Run resumed.\n\n".$this->orchestrator->summarizeRun($run);
    }

    private function handleAbortRun(string $runId): string
    {
        $run = $this->orchestrator->getRun($runId);
        if ($run === null) {
            return "Run {$runId} not found.";
        }
        $this->orchestrator->abortRun($run);

        return "Run aborted.\n\n".$this->orchestrator->summarizeRun($run);
    }

    private function findWorkspaceByUuid(string $workspaceUuid, string $tenantId): ?Workspace
    {
        return (new TenantWorkspaceResolver($this->entityTypeManager))->findWorkspaceByUuidForTenant($workspaceUuid, $tenantId);
    }

    private function resolveAuthenticatedAccount(mixed $account): ?AuthenticatedAccount
    {
        if ($account instanceof AuthenticatedAccount) {
            return $account;
        }

        return (new AuthenticatedAccountSessionResolver($this->entityTypeManager))->resolve();
    }

    private function resolveAccountTurnLimitsOverride(?AuthenticatedAccount $authenticatedAccount): ?array
    {
        if ($authenticatedAccount === null) {
            return null;
        }

        $account = $authenticatedAccount->account();
        $settings = $account->get('settings');
        if (! is_array($settings) && ! is_string($settings)) {
            return null;
        }

        if (is_string($settings)) {
            try {
                $decoded = json_decode($settings, true, 512, JSON_THROW_ON_ERROR);
                $settings = is_array($decoded) ? $decoded : [];
            } catch (\Throwable) {
                $settings = [];
            }
        }

        $rawTurnLimits = $settings['turn_limits'] ?? null;
        if (! is_array($rawTurnLimits)) {
            return null;
        }

        $allowed = [
            'quick_lookup' => true,
            'email_compose' => true,
            'brief_generation' => true,
            'research' => true,
            'general' => true,
            'onboarding' => true,
        ];

        $normalized = [];
        foreach ($rawTurnLimits as $taskType => $value) {
            if (! isset($allowed[$taskType])) {
                continue;
            }
            if (! is_numeric($value)) {
                continue;
            }
            $normalized[$taskType] = max(1, (int) $value);
        }

        return $normalized !== [] ? $normalized : null;
    }

    private function resolveMessageTenantId(mixed $message): string
    {
        $tenantId = $message instanceof ChatMessage ? $message->get('tenant_id') : null;

        return is_string($tenantId) && $tenantId !== '' ? $tenantId : 'default';
    }

    private function resolveMessageWorkspaceId(mixed $message): ?string
    {
        $workspaceId = $message instanceof ChatMessage ? $message->get('workspace_id') : null;

        return is_string($workspaceId) && $workspaceId !== '' ? $workspaceId : null;
    }

    private function jsonError(string $message, int $statusCode): SsrResponse
    {
        return new SsrResponse(
            content: json_encode(['error' => $message], JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    /**
     * Trim conversation history to limit token growth.
     *
     * When history exceeds $maxMessages, drops the oldest messages and
     * truncates older assistant responses to $olderAssistantMaxChars.
     * The last 4 messages (2 exchanges) are always kept in full.
     *
     * @param  list<ChatMessage>  $sessionMessages  Sorted chronologically
     * @return list<array{role: string, content: string}>
     */
    private function trimConversationHistory(array $sessionMessages, int $maxMessages = 20, int $olderAssistantMaxChars = 500): array
    {
        $total = count($sessionMessages);

        if ($total <= $maxMessages) {
            return array_map(
                fn ($m) => ['role' => $m->get('role'), 'content' => $m->get('content')],
                $sessionMessages,
            );
        }

        // Number of recent messages to keep in full (last 4 = 2 exchanges)
        $recentCount = min(4, $total);
        $cutoff = $total - $recentCount;

        $result = [];
        $olderStart = max(0, $total - $maxMessages);
        $trimmedCount = $olderStart;

        // Older messages within the cap window: truncate assistant responses
        for ($i = $olderStart; $i < $cutoff; $i++) {
            $msg = $sessionMessages[$i];
            $role = $msg->get('role');
            $content = (string) $msg->get('content');

            if ($role === 'assistant' && mb_strlen($content) > $olderAssistantMaxChars) {
                $content = mb_substr($content, 0, $olderAssistantMaxChars).' [truncated]';
            }

            // Prepend trim notice to the first kept message if messages were dropped
            if ($result === [] && $trimmedCount > 0 && $role === 'user') {
                $content = "[Earlier conversation trimmed — {$trimmedCount} messages]\n\n".$content;
            }

            $result[] = ['role' => $role, 'content' => $content];
        }

        // If oldest kept message was assistant (no user to prepend to), inject
        // a user message before it to maintain alternating roles
        if ($trimmedCount > 0 && $result !== [] && $result[0]['role'] === 'assistant') {
            array_unshift($result, [
                'role' => 'user',
                'content' => "[Earlier conversation trimmed — {$trimmedCount} messages]",
            ]);
        }

        // Recent messages: always in full
        for ($i = $cutoff; $i < $total; $i++) {
            $result[] = [
                'role' => $sessionMessages[$i]->get('role'),
                'content' => $sessionMessages[$i]->get('content'),
            ];
        }

        return $result;
    }

    private function resolveRequestId(?Request $httpRequest, array $query, string $fallback): string
    {
        $headerId = $httpRequest?->headers->get('X-Request-Id');
        if (is_string($headerId) && $headerId !== '') {
            return $headerId;
        }

        $queryId = $query['request_id'] ?? null;
        if (is_string($queryId) && $queryId !== '') {
            return $queryId;
        }

        return $fallback;
    }

    private function resolveRequestedTimezone(?Request $httpRequest, array $query): ?string
    {
        $queryTimezone = $query['timezone'] ?? null;
        if (is_string($queryTimezone) && $queryTimezone !== '') {
            return $queryTimezone;
        }

        $headerTimezone = $httpRequest?->headers->get('X-Timezone');

        return is_string($headerTimezone) && $headerTimezone !== '' ? $headerTimezone : null;
    }

    /**
     * @return array{0: ?string, 1: ?int, 2: int}
     */
    private function resolveSessionTurnBudgetOverrides(string $sessionUuid): array
    {
        try {
            $sessionStorage = $this->entityTypeManager->getStorage('chat_session');
        } catch (\Throwable) {
            return [null, null, 0];
        }

        $sessionIds = $sessionStorage->getQuery()->condition('uuid', $sessionUuid)->execute();
        if ($sessionIds === []) {
            return [null, null, 0];
        }

        $session = $sessionStorage->load(reset($sessionIds));
        if (! $session instanceof ContentEntityInterface) {
            return [null, null, 0];
        }

        $taskType = $session->get('task_type');
        $taskTypeOverride = is_string($taskType) && $taskType !== '' ? $taskType : null;

        $turnLimitRaw = $session->get('turn_limit_applied');
        $turnLimitOverride = is_int($turnLimitRaw) ? $turnLimitRaw : (is_numeric($turnLimitRaw) ? (int) $turnLimitRaw : null);
        if ($turnLimitOverride !== null && $turnLimitOverride <= 0) {
            $turnLimitOverride = null;
        }

        $turnsConsumedRaw = $session->get('turns_consumed');
        $turnsConsumedStart = is_numeric($turnsConsumedRaw) ? (int) $turnsConsumedRaw : 0;

        return [$taskTypeOverride, $turnLimitOverride, $turnsConsumedStart];
    }

    private function recordTurnTelemetry(string $sessionUuid, string $tenantId, ?string $workspaceUuid, array $payload): void
    {
        $turnNumber = (int) ($payload['turn_number'] ?? 0);
        $turnLimit = (int) ($payload['turn_limit'] ?? 0);
        $taskType = (string) ($payload['task_type'] ?? 'general');
        $model = (string) ($payload['model'] ?? '');
        $usage = is_array($payload['usage'] ?? null) ? $payload['usage'] : [];

        $this->updateSessionTurnMetadata($sessionUuid, $turnNumber, $turnLimit, $taskType, $model);
        $this->saveTokenUsage($sessionUuid, $tenantId, $workspaceUuid, $turnNumber, $model, $usage);
    }

    private function updateSessionTurnMetadata(string $sessionUuid, int $turnNumber, int $turnLimit, string $taskType, string $model): void
    {
        try {
            $sessionStorage = $this->entityTypeManager->getStorage('chat_session');
        } catch (\Throwable) {
            return;
        }

        $sessionIds = $sessionStorage->getQuery()->condition('uuid', $sessionUuid)->execute();
        if ($sessionIds === []) {
            return;
        }

        $session = $sessionStorage->load(reset($sessionIds));
        if (! $session instanceof ContentEntityInterface) {
            return;
        }

        $session->set('turns_consumed', $turnNumber);
        $session->set('turn_limit_applied', $turnLimit);
        if ($taskType !== '') {
            $session->set('task_type', $taskType);
        }
        if ($model !== '') {
            $session->set('model', $model);
        }
        $sessionStorage->save($session);
    }

    private function saveTokenUsage(
        string $sessionUuid,
        string $tenantId,
        ?string $workspaceUuid,
        int $turnNumber,
        string $model,
        array $usage,
    ): void {
        try {
            $storage = $this->entityTypeManager->getStorage('chat_token_usage');
        } catch (\Throwable) {
            return;
        }

        $entityClass = '\\Claudriel\\Entity\\ChatTokenUsage';
        if (! class_exists($entityClass)) {
            return;
        }

        $entry = new $entityClass([
            'uuid' => $this->generateUuid(),
            'session_uuid' => $sessionUuid,
            'turn_number' => $turnNumber,
            'model' => $model !== '' ? $model : null,
            'input_tokens' => (int) ($usage['input_tokens'] ?? 0),
            'output_tokens' => (int) ($usage['output_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'cache_write_tokens' => (int) ($usage['cache_creation_input_tokens'] ?? 0),
            'tenant_id' => $tenantId,
            'workspace_id' => $workspaceUuid,
            'created_at' => (new \DateTimeImmutable)->format('c'),
        ]);

        $storage->save($entry);
    }
}
