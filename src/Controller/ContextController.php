<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

/**
 * GET /api/context — composite endpoint returning brief + context files.
 */
final class ContextController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function show(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $projectRoot = getenv('CLAUDRIEL_PROJECT_ROOT') ?: dirname(__DIR__, 2);

        // Load recent events.
        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $allEventIds = $eventStorage->getQuery()->execute();
        /** @var ContentEntityInterface[] $allEvents */
        $allEvents = $eventStorage->loadMultiple($allEventIds);

        $since = new \DateTimeImmutable('-24 hours');
        $recentEvents = array_values(array_filter(
            $allEvents,
            static fn (ContentEntityInterface $e) => new \DateTimeImmutable($e->get('occurred') ?? 'now') >= $since,
        ));

        // Load pending commitments.
        $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
        $allCommitmentIds = $commitmentStorage->getQuery()->execute();
        /** @var ContentEntityInterface[] $allCommitments */
        $allCommitments = $commitmentStorage->loadMultiple($allCommitmentIds);
        $pendingCommitments = array_values(array_filter(
            $allCommitments,
            static fn (ContentEntityInterface $c) => $c->get('status') === 'pending',
        ));

        // Drifting commitments (active + updated_at < 48h).
        $driftingCommitments = array_values(array_filter(
            $allCommitments,
            static fn (ContentEntityInterface $c) => $c->get('status') === 'active'
                && ($c->get('updated_at') ?? null) !== null
                && new \DateTimeImmutable($c->get('updated_at')) < new \DateTimeImmutable('-48 hours'),
        ));

        $brief = [
            'recent_events' => array_map(fn ($e) => $e->toArray(), $recentEvents),
            'pending_commitments' => array_map(fn ($c) => $c->toArray(), $pendingCommitments),
            'drifting_commitments' => array_map(fn ($c) => $c->toArray(), $driftingCommitments),
        ];

        $contextFiles = [
            'me' => $this->readContextFile($projectRoot.'/context/me.md'),
            'commitments' => $this->readContextFile($projectRoot.'/context/commitments.md'),
            'patterns' => $this->readContextFile($projectRoot.'/context/patterns.md'),
        ];

        $payload = [
            'brief' => $brief,
            'context_files' => $contextFiles,
        ];

        return new SsrResponse(
            content: json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function readContextFile(string $path): ?string
    {
        if (! is_file($path) || ! is_readable($path)) {
            return null;
        }

        return file_get_contents($path) ?: null;
    }
}
