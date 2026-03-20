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
use Claudriel\Support\BriefSignal;
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

        $localActionResponse = $this->handleLocalAction($userMsg, $msgStorage, $tenantId);
        if ($localActionResponse instanceof StreamedResponse) {
            return $localActionResponse;
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

    private function handleLocalAction(ChatMessage $userMsg, mixed $msgStorage, string $tenantId): ?StreamedResponse
    {
        $content = trim((string) $userMsg->get('content'));
        $workspaceDeletes = $this->extractWorkspaceDeletionNames($content);
        $workspaceStorage = $this->entityTypeManager->getStorage('workspace');

        if ($workspaceDeletes !== []) {
            $deleted = [];
            $missing = [];

            foreach ($workspaceDeletes as $workspaceName) {
                $workspace = $this->findWorkspaceByName($workspaceName, $tenantId);
                if (! $workspace instanceof Workspace) {
                    $missing[] = $workspaceName;

                    continue;
                }

                $workspaceStorage->delete([$workspace]);
                $deleted[] = (string) $workspace->get('name');
            }

            $responseText = $this->buildWorkspaceDeletionResponse($deleted, $missing);
            if ($deleted !== []) {
                $this->touchBriefSignal();
            }

            return $this->buildLocalActionResponse($userMsg, $msgStorage, $responseText);
        }

        $workspaceName = $this->extractWorkspaceName($content);

        if ($workspaceName === null) {
            return null;
        }

        $existing = $this->findWorkspaceByName($workspaceName, $tenantId);
        if ($existing instanceof Workspace) {
            $responseText = sprintf(
                'The workspace "%s" already exists.',
                (string) $existing->get('name'),
            );
        } else {
            $workspace = new Workspace([
                'name' => $workspaceName,
                'description' => '',
                'tenant_id' => $tenantId,
            ]);
            $workspaceStorage->save($workspace);
            $this->touchBriefSignal();

            $responseText = sprintf(
                'Created the Claudriel workspace "%s". Refresh the sidebar if it is not visible yet.',
                $workspaceName,
            );
        }

        return $this->buildLocalActionResponse($userMsg, $msgStorage, $responseText);
    }

    private function buildLocalActionResponse(ChatMessage $userMsg, mixed $msgStorage, string $responseText): StreamedResponse
    {
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

    private function findWorkspaceByName(string $workspaceName, string $tenantId): ?Workspace
    {
        return (new TenantWorkspaceResolver($this->entityTypeManager))->findWorkspaceByNameForTenant($workspaceName, $tenantId);
    }

    /**
     * @return list<string>
     */
    private function extractWorkspaceDeletionNames(string $message): array
    {
        $normalized = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
            ['"', '"', "'", "'"],
            $message,
        );
        $patterns = [
            '/\b(?:delete|remove)\b.*?\bworkspace\b(?:s)?\s+(.+)$/iu',
            '/\b(?:delete|remove)\b\s+(.+?)\s+\bworkspace\b(?:s)?$/iu',
        ];

        $targetSegment = null;
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized, $matches)) {
                $targetSegment = trim($matches[1]);
                break;
            }
        }

        if ($targetSegment === null || $targetSegment === '') {
            return [];
        }

        $parts = preg_split('/\s*(?:,|and)\s*/iu', $targetSegment) ?: [];
        $names = [];

        foreach ($parts as $part) {
            $name = trim($part);
            $name = trim($name, " \t\n\r\0\x0B\"'.,!?;:");
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  list<string>  $deleted
     * @param  list<string>  $missing
     */
    private function buildWorkspaceDeletionResponse(array $deleted, array $missing): string
    {
        if ($deleted !== [] && $missing === []) {
            return sprintf(
                'Deleted %s.',
                $this->formatWorkspaceNameList($deleted),
            );
        }

        if ($deleted === [] && $missing !== []) {
            return sprintf(
                'Could not find %s.',
                $this->formatWorkspaceNameList($missing),
            );
        }

        if ($deleted !== [] && $missing !== []) {
            return sprintf(
                'Deleted %s. Could not find %s.',
                $this->formatWorkspaceNameList($deleted),
                $this->formatWorkspaceNameList($missing),
            );
        }

        return 'No workspace names were recognized in that delete request.';
    }

    /**
     * @param  list<string>  $names
     */
    private function formatWorkspaceNameList(array $names): string
    {
        $quoted = array_map(static fn (string $name): string => sprintf('"%s"', $name), $names);

        return match (count($quoted)) {
            0 => 'no workspaces',
            1 => $quoted[0],
            2 => $quoted[0].' and '.$quoted[1],
            default => implode(', ', array_slice($quoted, 0, -1)).', and '.$quoted[array_key_last($quoted)],
        };
    }

    private function touchBriefSignal(): void
    {
        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2).'/storage';
        $signal = new BriefSignal($storageDir.'/brief-signal.txt');
        $signal->touch();
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

        $apiMessages = array_map(
            fn ($m) => ['role' => $m->get('role'), 'content' => $m->get('content')],
            $sessionMessages,
        );

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

        $apiBase = $_ENV['CLAUDRIEL_API_URL'] ?? getenv('CLAUDRIEL_API_URL') ?: 'http://localhost:8088';

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

    private function extractWorkspaceName(string $message): ?string
    {
        $normalized = str_replace(
            ["\u{201C}", "\u{201D}", "\u{2018}", "\u{2019}"],
            ['"', '"', "'", "'"],
            $message,
        );
        $patterns = [
            '/\bcreate\b.*?\bworkspace\b.*?\b(?:named|called)\s+["\']?([^"\']+)["\']?/iu',
            '/\bcreate\b.*?\bworkspace\b\s+["\']?([^"\']+)["\']?/iu',
            '/\bnew\b.*?\bworkspace\b.*?\b(?:named|called)\s+["\']?([^"\']+)["\']?/iu',
        ];

        foreach ($patterns as $pattern) {
            if (! preg_match($pattern, $normalized, $matches)) {
                continue;
            }

            $name = trim($matches[1]);
            $name = trim($name, " \t\n\r\0\x0B.,!?;:");

            if ($name !== '') {
                return $name;
            }
        }

        return null;
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

        return $this->buildLocalActionResponse($userMsg, $msgStorage, $responseText);
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
