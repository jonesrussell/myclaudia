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
        private readonly ?EntityRepositoryInterface $personRepo = null,
        private readonly ?EntityRepositoryInterface $skillRepo = null,
        private readonly ?EntityRepositoryInterface $workspaceRepo = null,
    ) {}

    /** @return array{schedule: array, job_hunt: array, people: array, triage: array, creators: array, notifications: array, commitments: array{pending: array, drifting: array}, counts: array{job_alerts: int, messages: int, triage: int, due_today: int, drifting: int}, generated_at: string, matched_skills: array, workspaces: array} */
    public function assemble(string $tenantId, \DateTimeImmutable $since): array
    {
        $recentEvents = array_values(array_filter(
            $this->eventRepo->findBy([]),
            fn ($e) => new \DateTimeImmutable($e->get('occurred') ?? 'now') >= $since,
        ));

        $schedule = [];
        $jobHunt = [];
        $peopleEvents = [];
        $triage = [];
        $creators = [];
        $notifications = [];

        foreach ($recentEvents as $event) {
            $category = $event->get('category') ?? 'notification';
            $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];

            match ($category) {
                'schedule' => $schedule[] = [
                    'title' => $payload['title'] ?? $payload['subject'] ?? $event->get('type'),
                    'start_time' => $payload['start_time'] ?? $event->get('occurred'),
                    'end_time' => $payload['end_time'] ?? '',
                    'source' => $event->get('source'),
                ],
                'job_hunt' => $jobHunt[] = [
                    'title' => $payload['subject'] ?? $payload['title'] ?? '',
                    'source_name' => $payload['from_name'] ?? $event->get('source'),
                    'details' => $payload['snippet'] ?? $payload['body'] ?? '',
                ],
                'people' => $peopleEvents[] = [
                    'person_name' => $payload['from_name'] ?? $payload['from_email'] ?? '',
                    'person_email' => $payload['from_email'] ?? '',
                    'summary' => $payload['subject'] ?? '',
                    'occurred' => $event->get('occurred'),
                ],
                'triage' => $triage[] = [
                    'person_name' => $payload['from_name'] ?? $payload['from_email'] ?? '',
                    'person_email' => $payload['from_email'] ?? '',
                    'summary' => $payload['subject'] ?? '',
                    'occurred' => $event->get('occurred'),
                ],
                'creator' => $creators[] = [
                    'person_name' => $payload['from_name'] ?? $payload['from_email'] ?? '',
                    'person_email' => $payload['from_email'] ?? '',
                    'summary' => $payload['subject'] ?? '',
                    'occurred' => $event->get('occurred'),
                ],
                default => $notifications[] = [
                    'title' => $payload['subject'] ?? $event->get('type'),
                    'source' => $event->get('source'),
                    'occurred' => $event->get('occurred'),
                ],
            };
        }

        $schedule = $this->deduplicateSchedule($schedule);

        $allCommitments = $this->commitmentRepo->findBy([]);
        $pending = array_values(array_filter(
            $allCommitments,
            fn ($c) => $c->get('status') === 'pending',
        ));
        $drifting = $this->driftDetector->findDrifting($tenantId);

        $today = (new \DateTimeImmutable)->format('Y-m-d');
        $dueToday = count(array_filter($pending, fn ($c) => ($c->get('due_date') ?? '') === $today));

        return [
            'schedule' => $schedule,
            'job_hunt' => $jobHunt,
            'people' => $peopleEvents,
            'triage' => $triage,
            'creators' => $creators,
            'notifications' => $notifications,
            'commitments' => [
                'pending' => $pending,
                'drifting' => $drifting,
            ],
            'counts' => [
                'job_alerts' => count($jobHunt),
                'messages' => count($peopleEvents),
                'triage' => count($triage),
                'due_today' => $dueToday,
                'drifting' => count($drifting),
            ],
            'generated_at' => (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            'matched_skills' => $this->matchSkillsToEvents($recentEvents),
            'workspaces' => $this->buildWorkspaceData($recentEvents),
        ];
    }

    /**
     * @param  list<array{title: mixed, start_time: mixed, end_time: mixed, source: mixed}>  $schedule
     * @return list<array{title: mixed, start_time: mixed, end_time: mixed, source: mixed}>
     */
    private function deduplicateSchedule(array $schedule): array
    {
        $unique = [];

        foreach ($schedule as $item) {
            $fingerprint = implode('|', [
                mb_strtolower(trim((string) ($item['title'] ?? ''))),
                trim((string) ($item['start_time'] ?? '')),
                trim((string) ($item['end_time'] ?? '')),
                mb_strtolower(trim((string) ($item['source'] ?? ''))),
            ]);

            if (isset($unique[$fingerprint])) {
                continue;
            }

            $unique[$fingerprint] = $item;
        }

        return array_values($unique);
    }

    private function buildWorkspaceData(array $recentEvents): array
    {
        if ($this->workspaceRepo === null) {
            return [];
        }
        $workspaces = $this->workspaceRepo->findBy([]);

        return array_map(function ($ws) use ($recentEvents) {
            $wsUuid = $ws->get('uuid');
            $activityCount = count(array_filter(
                $recentEvents,
                fn ($e) => $e->get('workspace_id') === $wsUuid,
            ));

            return [
                'uuid' => $wsUuid,
                'name' => $ws->get('name') ?? '',
                'description' => $ws->get('description') ?? '',
                'activity_count' => $activityCount,
            ];
        }, $workspaces);
    }

    private function matchSkillsToEvents(array $recentEvents): array
    {
        if ($this->skillRepo === null || empty($recentEvents)) {
            return [];
        }

        $allSkills = $this->skillRepo->findBy([]);
        if (empty($allSkills)) {
            return [];
        }

        $eventText = '';
        foreach ($recentEvents as $event) {
            $eventText .= ' '.strtolower($event->get('source') ?? '');
            $eventText .= ' '.strtolower($event->get('type') ?? '');
            $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];
            $eventText .= ' '.strtolower($payload['subject'] ?? '');
            $eventText .= ' '.strtolower($payload['from_name'] ?? '');
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
