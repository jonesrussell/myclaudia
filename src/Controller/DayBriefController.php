<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Routing\RequestScopeViolation;
use Claudriel\Routing\TenantWorkspaceResolver;
use Claudriel\Support\DriftDetector;
use Claudriel\Support\StorageRepositoryAdapter;
use Claudriel\Temporal\TemporalContextFactory;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

/**
 * Web controller for the daily brief JSON endpoint.
 *
 * The HttpKernel instantiates app controllers as new $class($entityTypeManager, $twig)
 * and expects SsrResponse with public content/statusCode/headers properties.
 */
final class DayBriefController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        try {
            $scope = (new TenantWorkspaceResolver($this->entityTypeManager))->resolve($query, $account, $httpRequest);
        } catch (RequestScopeViolation $exception) {
            return new SsrResponse(
                content: json_encode(['error' => $exception->getMessage()], JSON_THROW_ON_ERROR),
                statusCode: $exception->statusCode(),
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2).'/storage';
        $sessionStore = new BriefSessionStore($storageDir.'/brief-session.txt');

        $since = new \DateTimeImmutable('-24 hours');
        $snapshot = (new TemporalContextFactory($this->entityTypeManager))->snapshotForInteraction(
            scopeKey: 'brief:'.$this->resolveRequestId($httpRequest, $query),
            tenantId: $scope->tenantId,
            workspaceUuid: $scope->workspaceId(),
            account: $account,
            requestTimezone: $this->resolveRequestedTimezone($query, $httpRequest),
        );

        $assembler = $this->buildAssembler();
        $brief = $assembler->assemble($scope->tenantId, $since, $scope->workspaceId(), $snapshot);

        $wantsJson = false;
        if ($httpRequest !== null) {
            $accept = $httpRequest->headers->get('Accept', '');
            $wantsJson = $httpRequest->getRequestFormat('') === 'json'
                || str_contains($accept, 'application/json')
                || str_contains($accept, 'application/vnd.api+json');
        }

        if (! $wantsJson) {
            $sessionStore->recordBriefAt(new \DateTimeImmutable);
        }

        if ($this->twig !== null && ! $wantsJson) {
            $html = $this->twig->render('day-brief.html.twig', $brief);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        $jsonBrief = $brief;
        $jsonBrief['commitments']['pending'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['pending']);
        $jsonBrief['commitments']['drifting'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['drifting']);
        $jsonBrief['commitments']['waiting_on'] = array_map(fn ($c) => $c->toArray(), $brief['commitments']['waiting_on']);
        $jsonBrief['matched_skills'] = array_map(fn ($s) => $s->toArray(), $brief['matched_skills']);

        return new SsrResponse(
            content: json_encode($jsonBrief, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function buildAssembler(): DayBriefAssembler
    {
        $eventRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('mc_event'));
        $commitmentRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('commitment'));

        $personRepo = null;
        try {
            $personRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('person'));
        } catch (\Throwable) {
            // person entity type may not be registered in tests
        }

        $scheduleRepo = null;
        try {
            $scheduleRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('schedule_entry'));
        } catch (\Throwable) {
            // schedule entity type may not be registered in tests
        }

        $triageRepo = null;
        try {
            $triageRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('triage_entry'));
        } catch (\Throwable) {
            // triage entity type may not be registered in tests
        }

        return new DayBriefAssembler(
            $eventRepo,
            $commitmentRepo,
            new DriftDetector($commitmentRepo),
            $personRepo,
            null,
            $scheduleRepo,
            null,
            $triageRepo,
        );
    }

    private function resolveRequestId(?Request $httpRequest, array $query): string
    {
        $headerId = $httpRequest?->headers->get('X-Request-Id');
        if (is_string($headerId) && $headerId !== '') {
            return $headerId;
        }

        $queryId = $query['request_id'] ?? null;
        if (is_string($queryId) && $queryId !== '') {
            return $queryId;
        }

        return bin2hex(random_bytes(8));
    }

    private function resolveRequestedTimezone(array $query, ?Request $httpRequest): ?string
    {
        $queryTimezone = $query['timezone'] ?? null;
        if (is_string($queryTimezone) && $queryTimezone !== '') {
            return $queryTimezone;
        }

        $headerTimezone = $httpRequest?->headers->get('X-Timezone');

        return is_string($headerTimezone) && $headerTimezone !== '' ? $headerTimezone : null;
    }
}
