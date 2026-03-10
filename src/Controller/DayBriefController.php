<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
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

    public function show(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $storageDir   = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2) . '/storage';
        $sessionStore = new BriefSessionStore($storageDir . '/brief-session.txt');
        $since        = $sessionStore->getLastBriefAt() ?? new \DateTimeImmutable('-24 hours');

        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $allEventIds  = $eventStorage->getQuery()->execute();
        $allEvents    = $eventStorage->loadMultiple($allEventIds);

        $recentEvents = array_values(array_filter(
            $allEvents,
            fn ($e) => new \DateTimeImmutable($e->get('occurred') ?? 'now') >= $since,
        ));

        $eventsBySource = [];
        $people = [];
        foreach ($recentEvents as $event) {
            $source = $event->get('source') ?? 'unknown';
            $eventsBySource[$source][] = $event;
            $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];
            $email   = $payload['from_email'] ?? null;
            $name    = $payload['from_name'] ?? null;
            if (is_string($email) && $email !== '') {
                $people[$email] = $name ?? $email;
            }
        }

        $commitmentStorage  = $this->entityTypeManager->getStorage('commitment');
        $allCommitmentIds   = $commitmentStorage->getQuery()->execute();
        $allCommitments     = $commitmentStorage->loadMultiple($allCommitmentIds);
        $pendingCommitments = array_values(array_filter(
            $allCommitments,
            fn ($c) => $c->get('status') === 'pending',
        ));

        $sessionStore->recordBriefAt(new \DateTimeImmutable());

        // Check Accept header: if the client wants JSON, skip Twig rendering.
        $wantsJson = false;
        if ($httpRequest !== null && isset($httpRequest->headers)) {
            $accept = $httpRequest->headers->get('Accept', '');
            $wantsJson = str_contains($accept, 'application/json');
        }

        // Render HTML via Twig when available and client does not prefer JSON.
        if ($this->twig !== null && !$wantsJson) {
            // Pre-process events for Twig: decode payload JSON so the template
            // doesn't need a json_decode filter.
            $twigEventsBySource = [];
            foreach ($eventsBySource as $source => $events) {
                foreach ($events as $event) {
                    $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];
                    $twigEventsBySource[$source][] = [
                        'type'      => $event->get('type'),
                        'source'    => $event->get('source'),
                        'occurred'  => $event->get('occurred'),
                        'subject'   => $payload['subject'] ?? $event->get('type'),
                        'from_name' => $payload['from_name'] ?? null,
                    ];
                }
            }

            $twigCommitments = [];
            foreach ($pendingCommitments as $c) {
                $twigCommitments[] = [
                    'title'      => $c->get('title'),
                    'confidence' => $c->get('confidence') ?? 1.0,
                    'due_date'   => $c->get('due_date'),
                ];
            }

            $html = $this->twig->render('day-brief.html.twig', [
                'recent_events'        => $recentEvents,
                'events_by_source'     => $twigEventsBySource,
                'people'               => $people,
                'pending_commitments'  => $twigCommitments,
                'drifting_commitments' => [],
            ]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        $brief = [
            'recent_events'        => array_map(fn ($e) => $e->toArray(), $recentEvents),
            'events_by_source'     => array_map(
                fn (array $events) => array_map(fn ($e) => $e->toArray(), $events),
                $eventsBySource,
            ),
            'people'               => $people,
            'pending_commitments'  => array_map(fn ($c) => $c->toArray(), $pendingCommitments),
            'drifting_commitments' => [],
        ];

        return new SsrResponse(
            content: json_encode($brief, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
