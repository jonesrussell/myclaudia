<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Domain\Chat\ChatSystemPromptBuilder;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\Chat\IssueIntentDetector;
use Claudriel\Domain\Chat\SubprocessChatClient;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\IssueOrchestrator;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\Workspace;
use Claudriel\Routing\RequestScopeViolation;
use Claudriel\Routing\TenantWorkspaceResolver;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Claudriel\Support\DriftDetector;
use Claudriel\Support\StorageRepositoryAdapter;
use Claudriel\Temporal\TemporalContextFactory;
use Claudriel\Temporal\TimeSnapshot;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ChatStreamController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?InternalApiTokenGenerator $tokenGenerator = null,
        private readonly mixed $subprocessClientFactory = null,
        private readonly ?IssueOrchestrator $orchestrator = null,
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
        $activeWorkspace = $workspaceUuid !== null ? $this->findWorkspaceByUuid($workspaceUuid, $tenantId)?->get('name') : null;
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

        $authenticatedAccount = $this->resolveAuthenticatedAccount($account);
        $accountId = $authenticatedAccount?->getUuid() ?? $tenantId;
        $tokenGenerator = $this->tokenGenerator ?? new InternalApiTokenGenerator(
            $_ENV['AGENT_INTERNAL_SECRET'] ?? getenv('AGENT_INTERNAL_SECRET') ?: '',
        );
        $apiToken = $tokenGenerator->generate($accountId);

        $apiBase = $_ENV['CLAUDRIEL_API_URL'] ?? getenv('CLAUDRIEL_API_URL') ?: '';
        if ($apiBase === '') {
            $onError('CLAUDRIEL_API_URL environment variable is not set. The agent cannot call back to the PHP API without it.');

            return;
        }

        $this->emitSseEvent('chat-progress', [
            'phase' => 'prepare',
            'summary' => 'Starting agent',
            'level' => 'info',
        ]);

        $client = $this->createSubprocessClient($projectRoot);

        $client->stream(
            systemPrompt: $systemPrompt,
            messages: $apiMessages,
            accountId: $accountId,
            tenantId: $tenantId,
            apiBase: $apiBase,
            apiToken: $apiToken,
            onToken: $onToken,
            onDone: $onDone,
            onError: $onError,
            onProgress: $onProgress,
            onNeedsContinuation: $onNeedsContinuation,
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

    private function createSubprocessClient(string $projectRoot): SubprocessChatClient
    {
        if (is_callable($this->subprocessClientFactory)) {
            return ($this->subprocessClientFactory)();
        }

        $dockerImage = $_ENV['AGENT_DOCKER_IMAGE'] ?? getenv('AGENT_DOCKER_IMAGE') ?: '';

        if ($dockerImage !== '') {
            // Production: run agent inside Docker container, pass API key
            $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '';
            $command = ['docker', 'run', '--rm', '-i', '--network=host', '-e', 'ANTHROPIC_API_KEY='.$apiKey, $dockerImage, 'python', '/srv/agent/main.py'];
        } else {
            // Local dev: run agent directly via venv
            $venv = $_ENV['AGENT_VENV'] ?? getenv('AGENT_VENV') ?: $projectRoot.'/agent/.venv';
            $agentPath = $_ENV['AGENT_PATH'] ?? getenv('AGENT_PATH') ?: $projectRoot.'/agent/main.py';
            $command = [$venv.'/bin/python', $agentPath];
        }

        return new SubprocessChatClient($command);
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
}
