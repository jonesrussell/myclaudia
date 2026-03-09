<?php

declare(strict_types=1);

namespace Claudriel\Domain\DayBrief\Assembler;

use Claudriel\Support\DriftDetector;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class DayBriefAssembler
{
    public function __construct(
        private readonly EntityRepositoryInterface $eventRepo,
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly DriftDetector $driftDetector,
        private readonly ?EntityRepositoryInterface $skillRepo = null,
    ) {}

    /** @return array{recent_events: array, events_by_source: array<string,array>, people: array<string,string>, pending_commitments: array, drifting_commitments: array, matched_skills: array} */
    public function assemble(string $tenantId, \DateTimeImmutable $since): array
    {
        // Load all events and filter in memory to support both SQL and in-memory drivers.
        // tenant_id and occurred are stored in the _data JSON blob, not as schema columns.
        $recentEvents = array_values(array_filter(
            $this->eventRepo->findBy([]),
            fn ($e) => new \DateTimeImmutable($e->get('occurred') ?? 'now') >= $since,
        ));

        $eventsBySource = [];
        $people = [];
        foreach ($recentEvents as $event) {
            $source = $event->get('source') ?? 'unknown';
            $eventsBySource[$source][] = $event;
            $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];
            $email = $payload['from_email'] ?? null;
            $name  = $payload['from_name'] ?? null;
            if (is_string($email) && $email !== '') {
                $people[$email] = $name ?? $email;
            }
        }

        $allCommitments = $this->commitmentRepo->findBy([]);
        $pendingCommitments = array_values(array_filter($allCommitments, fn ($c) => $c->get('status') === 'pending'));
        $driftingCommitments = $this->driftDetector->findDrifting($tenantId);

        $matchedSkills = $this->matchSkillsToEvents($recentEvents);

        return [
            'recent_events'        => $recentEvents,
            'events_by_source'     => $eventsBySource,
            'people'               => $people,
            'pending_commitments'  => $pendingCommitments,
            'drifting_commitments' => $driftingCommitments,
            'matched_skills'       => $matchedSkills,
        ];
    }

    /**
     * Match skills to recent events by checking trigger keywords against event text.
     *
     * @param array $recentEvents
     * @return array Skill entities whose trigger_keywords match event content.
     */
    private function matchSkillsToEvents(array $recentEvents): array
    {
        if ($this->skillRepo === null || empty($recentEvents)) {
            return [];
        }

        $allSkills = $this->skillRepo->findBy([]);
        if (empty($allSkills)) {
            return [];
        }

        // Build a combined text corpus from event metadata for keyword matching.
        $eventText = '';
        foreach ($recentEvents as $event) {
            $eventText .= ' ' . strtolower($event->get('source') ?? '');
            $eventText .= ' ' . strtolower($event->get('type') ?? '');
            $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];
            $eventText .= ' ' . strtolower($payload['subject'] ?? '');
            $eventText .= ' ' . strtolower($payload['from_name'] ?? '');
        }

        $matched = [];
        foreach ($allSkills as $skill) {
            $keywords = $skill->get('trigger_keywords') ?? '';
            $parts = array_map('trim', explode(',', strtolower($keywords)));
            foreach ($parts as $keyword) {
                if ($keyword !== '' && str_contains($eventText, $keyword)) {
                    $matched[] = $skill;
                    break;
                }
            }
        }

        return $matched;
    }
}
