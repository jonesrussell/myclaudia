<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Support\DriftDetector;
use Twig\Environment;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class DashboardController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2) . '/storage';
        $sessionStore = new BriefSessionStore($storageDir . '/brief-session.txt');
        $since = $sessionStore->getLastBriefAt() ?? new \DateTimeImmutable('-24 hours');

        // Assemble brief data
        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $commitmentStorage = $this->entityTypeManager->getStorage('commitment');
        $skillStorage = $this->entityTypeManager->getStorage('skill');

        $eventRepo = new StorageRepositoryAdapter($eventStorage);
        $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
        $skillRepo = new StorageRepositoryAdapter($skillStorage);
        $driftDetector = new DriftDetector($commitmentRepo);

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $skillRepo);
        $brief = $assembler->assemble('default', $since);

        $sessionStore->recordBriefAt(new \DateTimeImmutable());

        // Load chat sessions
        $chatSessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $sessionIds = $chatSessionStorage->getQuery()->execute();
        $allSessions = $chatSessionStorage->loadMultiple($sessionIds);
        usort($allSessions, fn ($a, $b) => ($b->get('created_at') ?? '') <=> ($a->get('created_at') ?? ''));
        $sessions = array_slice($allSessions, 0, 10);

        $twigSessions = array_map(fn ($s) => [
            'uuid' => $s->get('uuid'),
            'title' => $s->get('title') ?? 'New Chat',
            'created_at' => $s->get('created_at'),
        ], $sessions);

        $apiKey = getenv('ANTHROPIC_API_KEY');
        $apiConfigured = is_string($apiKey) && $apiKey !== '';

        // Twig rendering
        if ($this->twig !== null) {
            $twigEventsBySource = [];
            foreach ($brief['events_by_source'] as $source => $events) {
                foreach ($events as $event) {
                    $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];
                    $twigEventsBySource[$source][] = [
                        'type' => $event->get('type'),
                        'source' => $event->get('source'),
                        'occurred' => $event->get('occurred'),
                        'subject' => $payload['subject'] ?? $event->get('type'),
                        'from_name' => $payload['from_name'] ?? null,
                    ];
                }
            }

            $twigCommitments = array_map(fn ($c) => [
                'title' => $c->get('title'),
                'confidence' => $c->get('confidence') ?? 1.0,
                'due_date' => $c->get('due_date'),
            ], $brief['pending_commitments']);

            $twigDrifting = array_map(fn ($c) => [
                'title' => $c->get('title'),
                'updated_at' => $c->get('updated_at'),
            ], $brief['drifting_commitments']);

            $html = $this->twig->render('dashboard.twig', [
                'recent_events' => $brief['recent_events'],
                'events_by_source' => $twigEventsBySource,
                'people' => $brief['people'],
                'pending_commitments' => $twigCommitments,
                'drifting_commitments' => $twigDrifting,
                'sessions' => $twigSessions,
                'api_configured' => $apiConfigured,
            ]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        // JSON fallback
        return new SsrResponse(
            content: json_encode([
                'brief' => [
                    'recent_events' => array_map(fn ($e) => $e->toArray(), $brief['recent_events']),
                    'events_by_source' => array_map(
                        fn (array $events) => array_map(fn ($e) => $e->toArray(), $events),
                        $brief['events_by_source'],
                    ),
                    'people' => $brief['people'],
                    'pending_commitments' => array_map(fn ($c) => $c->toArray(), $brief['pending_commitments']),
                    'drifting_commitments' => array_map(fn ($c) => $c->toArray(), $brief['drifting_commitments']),
                ],
                'sessions' => $twigSessions,
                'api_configured' => $apiConfigured,
            ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
