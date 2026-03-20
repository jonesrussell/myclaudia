<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Routing\RequestScopeViolation;
use Claudriel\Routing\TenantWorkspaceResolver;
use Claudriel\Support\DriftDetector;
use Claudriel\Temporal\Agent\TemporalGuidanceAssembler;
use Claudriel\Temporal\TemporalContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;
use Waaseyaa\User\Middleware\CsrfMiddleware;

final class DashboardController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
        private readonly ?TemporalContextFactory $temporalContextFactory = null,
    ) {}

    public function show(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account, $httpRequest);
        } catch (RequestScopeViolation $exception) {
            return new SsrResponse(
                content: json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR),
                statusCode: $exception->statusCode(),
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2).'/storage';
        $sessionStore = new BriefSessionStore($storageDir.'/brief-session.txt');

        // Always show last 24h for Day Brief. The session cursor is preserved
        // for future "new items" indicators but doesn't gate the main display.
        $since = new \DateTimeImmutable('-24 hours');
        $requestId = $this->resolveRequestId($httpRequest, $query);
        $snapshot = ($this->temporalContextFactory ?? new TemporalContextFactory($this->entityTypeManager))->snapshotForInteraction(
            scopeKey: 'dashboard:'.$requestId,
            tenantId: $scope->tenantId,
            workspaceUuid: $scope->workspaceId(),
            account: $account,
            requestTimezone: $this->resolveRequestedTimezone($httpRequest, $query),
        );

        // Assemble brief data
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

        $workspaceRepo = null;
        try {
            $workspaceRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('workspace'));
        } catch (\Throwable) {
        }

        $scheduleRepo = null;
        try {
            $scheduleRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('schedule_entry'));
        } catch (\Throwable) {
        }

        $triageRepo = null;
        try {
            $triageRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('triage_entry'));
        } catch (\Throwable) {
        }

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $personRepo, $skillRepo, $scheduleRepo, $workspaceRepo, $triageRepo);
        $brief = $assembler->assemble($scope->tenantId, $since, $scope->workspaceId(), $snapshot);
        $proactiveGuidance = (new TemporalGuidanceAssembler($this->entityTypeManager))
            ->build($scope->tenantId, $scope->workspaceId(), $brief, $snapshot);
        $briefPayload = $this->buildBriefPayload($brief);
        $briefPayload['proactive_guidance'] = $proactiveGuidance;
        $fallbackPayload = [
            'workspaces' => $briefPayload['workspaces'],
            'briefs' => $briefPayload,
            'updated_at' => $snapshot->utc()->format(\DateTimeInterface::ATOM),
        ];

        $sessionStore->recordBriefAt(new \DateTimeImmutable);

        // Load chat sessions
        $chatSessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $sessionIds = $chatSessionStorage->getQuery()->execute();
        /** @var ContentEntityInterface[] $loadedSessions */
        $loadedSessions = $chatSessionStorage->loadMultiple($sessionIds);
        $allSessions = array_values(array_filter(
            $loadedSessions,
            fn ($session): bool => $resolver->tenantMatches($session, $scope->tenantId),
        ));
        usort($allSessions, static fn (ContentEntityInterface $a, ContentEntityInterface $b) => ($b->get('created_at') ?? '') <=> ($a->get('created_at') ?? ''));

        $twigSessions = array_map(static fn (ContentEntityInterface $s) => [
            'uuid' => $s->get('uuid'),
            'title' => $s->get('title') ?? 'New Chat',
            'created_at' => $s->get('created_at'),
        ], $allSessions);

        $apiKey = getenv('ANTHROPIC_API_KEY');
        $apiConfigured = is_string($apiKey) && $apiKey !== '';
        $model = $_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-6';

        /** @var ContentEntityInterface[] $pendingCommitments */
        $pendingCommitments = $brief['commitments']['pending'];
        $twigCommitments = array_map(static fn (ContentEntityInterface $c) => [
            'title' => $c->get('title'),
            'confidence' => $c->get('confidence') ?? 1.0,
            'due_date' => $c->get('due_date'),
        ], $pendingCommitments);

        /** @var ContentEntityInterface[] $driftingCommitments */
        $driftingCommitments = $brief['commitments']['drifting'];
        $twigDrifting = array_map(static fn (ContentEntityInterface $c) => [
            'title' => $c->get('title'),
            'updated_at' => $c->get('updated_at'),
        ], $driftingCommitments);

        /** @var ContentEntityInterface[] $waitingOnCommitments */
        $waitingOnCommitments = $brief['commitments']['waiting_on'];
        $twigWaitingOn = array_map(static fn (ContentEntityInterface $c) => [
            'title' => $c->get('title'),
            'person_id' => $c->get('person_id'),
        ], $waitingOnCommitments);

        // Twig rendering
        if ($this->twig !== null) {
            $html = $this->twig->render('dashboard.twig', array_merge($brief, [
                'pending_commitments' => $twigCommitments,
                'drifting_commitments' => $twigDrifting,
                'waiting_on_commitments' => $twigWaitingOn,
                'sessions' => $twigSessions,
                'api_configured' => $apiConfigured,
                'csrf_token' => CsrfMiddleware::token(),
                'model' => $model,
                'workspaces' => $brief['workspaces'],
                'active_tenant_id' => $scope->tenantId,
                'active_workspace_uuid' => $scope->workspaceId(),
                'proactive_guidance' => $proactiveGuidance,
                'brief_fallback_payload' => json_encode($fallbackPayload, JSON_THROW_ON_ERROR),
                'brief_fallback_url' => '/stream/brief?transport=fallback&request_id='.$requestId,
            ]));

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        // JSON fallback
        return new SsrResponse(
            content: json_encode([
                'brief' => $briefPayload,
                'sessions' => $twigSessions,
                'api_configured' => $apiConfigured,
                'workspaces' => $brief['workspaces'],
                'brief_fallback' => $fallbackPayload,
                'brief_fallback_url' => '/stream/brief?transport=fallback&request_id='.$requestId,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function buildBriefPayload(array $brief): array
    {
        $payload = $brief;
        $payload['commitments']['pending'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['pending']);
        $payload['commitments']['drifting'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['drifting']);
        $payload['commitments']['waiting_on'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['waiting_on']);
        $payload['matched_skills'] = array_map(fn ($s) => $s->toArray(), $brief['matched_skills']);

        return $payload;
    }

    private function resolveRequestId(mixed $httpRequest, array $query): string
    {
        if ($httpRequest instanceof Request) {
            $headerId = $httpRequest->headers->get('X-Request-Id');
            if (is_string($headerId) && $headerId !== '') {
                return $headerId;
            }
        }

        $queryId = $query['request_id'] ?? null;
        if (is_string($queryId) && $queryId !== '') {
            return $queryId;
        }

        return bin2hex(random_bytes(8));
    }

    private function resolveRequestedTimezone(mixed $httpRequest, array $query): ?string
    {
        $queryTimezone = $query['timezone'] ?? null;
        if (is_string($queryTimezone) && $queryTimezone !== '') {
            return $queryTimezone;
        }

        if ($httpRequest instanceof Request) {
            $headerTimezone = $httpRequest->headers->get('X-Timezone');
            if (is_string($headerTimezone) && $headerTimezone !== '') {
                return $headerTimezone;
            }
        }

        return null;
    }
}
