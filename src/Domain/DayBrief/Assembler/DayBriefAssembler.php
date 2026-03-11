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

    /** @return array{schedule: array, job_hunt: array, people: array, creators: array, notifications: array, commitments: array{pending: array, drifting: array}, counts: array{job_alerts: int, messages: int, due_today: int, drifting: int}, generated_at: string, matched_skills: array, workspaces: array} */
    public function assemble(string $tenantId, \DateTimeImmutable $since): array
    {
        $recentEvents = array_values(array_filter(
            $this->eventRepo->findBy([]),
            fn ($e) => new \DateTimeImmutable($e->get('occurred') ?? 'now') >= $since,
        ));

        $peopleByEmail = $this->indexPeopleByEmail();

        $schedule = [];
        $jobHunt = [];
        $peopleEvents = [];
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
            'creators' => $creators,
            'notifications' => $notifications,
            'commitments' => [
                'pending' => $pending,
                'drifting' => $drifting,
            ],
            'counts' => [
                'job_alerts' => count($jobHunt),
                'messages' => count($peopleEvents),
                'due_today' => $dueToday,
                'drifting' => count($drifting),
            ],
            'generated_at' => (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            'matched_skills' => $this->matchSkillsToEvents($recentEvents),
            'workspaces' => $this->buildWorkspaceData($recentEvents),
        ];
    }

    /** @return array<string, mixed> */
    private function indexPeopleByEmail(): array
    {
        if ($this->personRepo === null) {
            return [];
        }
        $index = [];
        foreach ($this->personRepo->findBy([]) as $person) {
            $email = $person->get('email');
            if ($email) {
                $index[$email] = $person;
            }
        }

        return $index;
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
