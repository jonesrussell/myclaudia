<?php

declare(strict_types=1);

namespace Claudriel\Domain\DayBrief\Assembler;

use Claudriel\Support\DriftDetector;
use Claudriel\Support\SchedulePayloadNormalizer;
use Claudriel\Temporal\AtomicTimeService;
use Claudriel\Temporal\RelativeScheduleQueryService;
use Claudriel\Temporal\TemporalAwarenessEngine;
use Claudriel\Temporal\TemporalSuggestionEngine;
use Claudriel\Temporal\TimeSnapshot;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class DayBriefAssembler
{
    public function __construct(
        private readonly EntityRepositoryInterface $eventRepo,
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly DriftDetector $driftDetector,
        private readonly ?EntityRepositoryInterface $personRepo = null,
        private readonly ?EntityRepositoryInterface $skillRepo = null,
        private readonly ?EntityRepositoryInterface $scheduleRepo = null,
        private readonly ?EntityRepositoryInterface $workspaceRepo = null,
        private readonly ?EntityRepositoryInterface $triageRepo = null,
        private readonly ?AtomicTimeService $timeService = null,
    ) {}

    /** @return array{schedule: array, schedule_summary: string, temporal_awareness: array<string, mixed>, temporal_suggestions: list<array{type: string, title: string, summary: string}>, job_hunt: array, people: array, triage: array, creators: array, notifications: array, commitments: array{pending: array, drifting: array}, counts: array{job_alerts: int, messages: int, triage: int, due_today: int, drifting: int}, generated_at: string, time_snapshot: array<string, int|string>, matched_skills: array, workspaces: array} */
    public function assemble(string $tenantId, \DateTimeImmutable $since, ?string $workspaceUuid = null, ?TimeSnapshot $snapshot = null): array
    {
        $snapshot ??= ($this->timeService ?? new AtomicTimeService)->now();

        $recentEvents = array_values(array_filter(
            $this->eventRepo->findBy([]),
            fn ($e) => $this->eventMatchesScope($e, $tenantId, $workspaceUuid)
                && new \DateTimeImmutable($e->get('occurred') ?? 'now') >= $since,
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

        $schedule = $this->scheduleRepo !== null
            ? $this->buildNormalizedSchedule($tenantId, $workspaceUuid, $snapshot)
            : $this->prepareSchedule($schedule, $snapshot);
        $relativeSchedule = (new RelativeScheduleQueryService)->filter($schedule, $snapshot);
        $normalizedPeople = $this->personRepo !== null
            ? $this->buildNormalizedPeople($tenantId, $since)
            : [];
        $normalizedTriage = $this->triageRepo !== null
            ? $this->buildNormalizedTriage($tenantId, $since)
            : [];
        $people = $normalizedPeople !== [] ? $normalizedPeople : $peopleEvents;
        $triage = $normalizedTriage !== [] ? $normalizedTriage : $triage;

        $allCommitments = $this->commitmentRepo->findBy([]);
        $pending = array_values(array_filter(
            $allCommitments,
            fn ($c) => $this->entityMatchesTenant($c, $tenantId) && $c->get('status') === 'pending',
        ));
        $drifting = $this->driftDetector->findDrifting($tenantId);

        $today = $snapshot->local()->format('Y-m-d');
        $dueToday = count(array_filter($pending, fn ($c) => ($c->get('due_date') ?? '') === $today));
        $temporalAwareness = (new TemporalAwarenessEngine)->analyze($schedule, $snapshot);
        $temporalSuggestions = (new TemporalSuggestionEngine)->suggest($temporalAwareness, $snapshot);

        return [
            'schedule' => $relativeSchedule['schedule'],
            'schedule_summary' => $relativeSchedule['schedule_summary'],
            'temporal_awareness' => $temporalAwareness,
            'temporal_suggestions' => $temporalSuggestions,
            'job_hunt' => $jobHunt,
            'people' => $people,
            'triage' => $triage,
            'creators' => $creators,
            'notifications' => $notifications,
            'commitments' => [
                'pending' => $pending,
                'drifting' => $drifting,
            ],
            'counts' => [
                'job_alerts' => count($jobHunt),
                'messages' => count($people),
                'triage' => count($triage),
                'due_today' => $dueToday,
                'drifting' => count($drifting),
            ],
            'generated_at' => $snapshot->utc()->format(\DateTimeInterface::ATOM),
            'time_snapshot' => $snapshot->toArray(),
            'matched_skills' => $this->matchSkillsToEvents($recentEvents),
            'workspaces' => $this->buildWorkspaceData($recentEvents, $tenantId, $workspaceUuid),
        ];
    }

    /**
     * @param  list<array{title: mixed, start_time: mixed, end_time: mixed, source: mixed}>  $schedule
     * @return list<array{title: mixed, start_time: mixed, end_time: mixed, source: mixed}>
     */
    private function prepareSchedule(array $schedule, TimeSnapshot $snapshot): array
    {
        $today = $snapshot->local()->format('Y-m-d');
        $filtered = [];

        foreach ($schedule as $item) {
            $start = $this->parseDateTime($item['start_time']);
            if ($start === null || $start->format('Y-m-d') !== $today) {
                continue;
            }

            $filtered[] = $item;
        }

        usort($filtered, function (array $a, array $b): int {
            $left = $this->parseDateTime($a['start_time'] ?? null)?->getTimestamp() ?? PHP_INT_MAX;
            $right = $this->parseDateTime($b['start_time'] ?? null)?->getTimestamp() ?? PHP_INT_MAX;

            return $left <=> $right;
        });

        $unique = [];

        foreach ($filtered as $item) {
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

    /**
     * @return list<array{title: string, start_time: string, end_time: string, source: string}>
     */
    private function buildNormalizedSchedule(string $tenantId, ?string $workspaceUuid = null, ?TimeSnapshot $snapshot = null): array
    {
        $snapshot ??= ($this->timeService ?? new AtomicTimeService)->now();
        $today = $snapshot->local()->format('Y-m-d');
        $normalizer = new SchedulePayloadNormalizer;
        $scheduleEntries = array_values(array_filter(
            $this->scheduleRepo->findBy([]),
            function ($entry) use ($tenantId, $today, $workspaceUuid, $snapshot): bool {
                if (($this->getEntityValue($entry, 'status') ?? 'active') !== 'active') {
                    return false;
                }

                $entryTenant = $this->getEntityValue($entry, 'tenant_id');
                if (is_string($entryTenant) && $entryTenant !== '' && $entryTenant !== $tenantId) {
                    return false;
                }

                if ($workspaceUuid !== null) {
                    $entryWorkspace = $this->getEntityValue($entry, 'workspace_id') ?? $this->getEntityValue($entry, 'workspace_uuid');
                    if (is_string($entryWorkspace) && $entryWorkspace !== '' && $entryWorkspace !== $workspaceUuid) {
                        return false;
                    }
                }

                $startsAt = $this->parseDateTime($this->getEntityValue($entry, 'starts_at'));

                return $startsAt instanceof \DateTimeImmutable
                    && $startsAt->setTimezone($snapshot->local()->getTimezone())->format('Y-m-d') === $today;
            },
        ));

        usort($scheduleEntries, fn ($a, $b): int => ((string) $this->getEntityValue($a, 'starts_at')) <=> ((string) $this->getEntityValue($b, 'starts_at')));

        if ($scheduleEntries !== []) {
            $normalizedEntries = array_map(function ($entry) use ($normalizer): array {
                return $this->normalizeScheduleEntry($entry, $normalizer);
            }, $scheduleEntries);

            return $this->canonicalizeSchedule($normalizedEntries, $snapshot);
        }

        $legacySchedule = [];
        foreach ($this->eventRepo->findBy([]) as $event) {
            if (! $this->eventMatchesScope($event, $tenantId, $workspaceUuid)) {
                continue;
            }

            if (($this->getEntityValue($event, 'category') ?? 'notification') !== 'schedule') {
                continue;
            }

            $payload = json_decode((string) ($this->getEntityValue($event, 'payload') ?? '{}'), true) ?? [];
            $source = $this->getEntityValue($event, 'source');
            $occurred = $this->getEntityValue($event, 'occurred');
            $normalized = $normalizer->normalize(
                $payload + ['source' => is_string($source) ? $source : 'google-calendar'],
                is_string($occurred) ? $occurred : null,
            );
            $normalizedStart = $this->parseDateTime($normalized['start_time'])?->setTimezone($snapshot->local()->getTimezone());
            if ($normalized === null || ! $normalizedStart instanceof \DateTimeImmutable || $normalizedStart->format('Y-m-d') !== $today) {
                continue;
            }

            $legacySchedule[] = [
                'title' => $normalized['title'],
                'start_time' => $normalized['start_time'],
                'end_time' => $normalized['end_time'],
                'source' => $normalized['source'],
            ];
        }

        return $this->canonicalizeSchedule($legacySchedule, $snapshot);
    }

    /**
     * @param  array{title: string, start_time: string, end_time: string, source: string, external_id?: string|null}  $entry
     * @return array{title: string, start_time: string, end_time: string, source: string}
     */
    private function stripScheduleMeta(array $entry): array
    {
        return [
            'title' => $entry['title'],
            'start_time' => $entry['start_time'],
            'end_time' => $entry['end_time'],
            'source' => $entry['source'],
        ];
    }

    /**
     * @return array{title: string, start_time: string, end_time: string, source: string, external_id?: string|null}
     */
    private function normalizeScheduleEntry(mixed $entry, SchedulePayloadNormalizer $normalizer): array
    {
        $title = (string) ($this->getEntityValue($entry, 'title') ?? '');
        $startTime = (string) ($this->getEntityValue($entry, 'starts_at') ?? '');
        $endTime = (string) ($this->getEntityValue($entry, 'ends_at') ?? '');
        $source = (string) ($this->getEntityValue($entry, 'source') ?? 'manual');
        $externalId = $this->getEntityValue($entry, 'external_id');
        $rawPayload = $this->getEntityValue($entry, 'raw_payload');

        if ($source === 'google-calendar' && is_string($rawPayload) && $rawPayload !== '') {
            $payload = json_decode($rawPayload, true);
            $start = $this->parseDateTime($startTime);
            if (is_array($payload) && $start instanceof \DateTimeImmutable) {
                $normalized = $normalizer->normalizeForLocalDate($payload + ['source' => $source], $start);
                if ($normalized !== null) {
                    return [
                        'title' => $normalized['title'],
                        'start_time' => $normalized['start_time'],
                        'end_time' => $normalized['end_time'],
                        'source' => $normalized['source'],
                        'external_id' => $normalized['external_id'],
                    ];
                }
            }
        }

        return [
            'title' => $title,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'source' => $source,
            'external_id' => is_string($externalId) && $externalId !== '' ? $externalId : null,
        ];
    }

    /**
     * @param  list<array{title: string, start_time: string, end_time: string, source: string, external_id?: string|null}>  $schedule
     * @return list<array{title: string, start_time: string, end_time: string, source: string}>
     */
    private function canonicalizeSchedule(array $schedule, TimeSnapshot $snapshot): array
    {
        $prepared = $this->prepareSchedule(
            array_map(fn (array $item): array => $this->stripScheduleMeta($item), $schedule),
            $snapshot,
        );
        $candidates = [];

        foreach ($schedule as $item) {
            $start = $this->parseDateTime($item['start_time'])?->setTimezone($snapshot->local()->getTimezone());
            if ($start === null || $start->format('Y-m-d') !== $snapshot->local()->format('Y-m-d')) {
                continue;
            }

            $item['external_id'] = is_string($item['external_id'] ?? null) && $item['external_id'] !== '' ? $item['external_id'] : null;
            $candidates[] = $item;
        }

        usort($candidates, function (array $a, array $b): int {
            $aTs = $this->parseDateTime($a['start_time'])?->getTimestamp() ?? PHP_INT_MAX;
            $bTs = $this->parseDateTime($b['start_time'])?->getTimestamp() ?? PHP_INT_MAX;

            return $aTs <=> $bTs;
        });

        $canonical = [];
        foreach ($candidates as $candidate) {
            $merged = false;
            foreach ($canonical as $index => $existing) {
                if (! $this->shouldTreatAsShiftedVariant($existing, $candidate)) {
                    continue;
                }

                $existingStart = $this->parseDateTime($existing['start_time']);
                $candidateStart = $this->parseDateTime($candidate['start_time']);
                if ($existingStart instanceof \DateTimeImmutable && $candidateStart instanceof \DateTimeImmutable && $candidateStart < $existingStart) {
                    $canonical[$index] = $candidate;
                }
                $merged = true;
                break;
            }

            if (! $merged) {
                $canonical[] = $candidate;
            }
        }

        $result = $canonical !== [] ? $canonical : array_map(
            fn (array $item): array => $item + ['external_id' => null],
            $prepared,
        );

        return array_map(fn (array $item): array => $this->stripScheduleMeta($item), $result);
    }

    /**
     * @param  array{title: string, start_time: string, end_time: string, source: string, external_id?: string|null}  $left
     * @param  array{title: string, start_time: string, end_time: string, source: string, external_id?: string|null}  $right
     */
    private function shouldTreatAsShiftedVariant(array $left, array $right): bool
    {
        if (mb_strtolower(trim($left['title'])) !== mb_strtolower(trim($right['title']))) {
            return false;
        }

        if ($left['source'] !== 'google-calendar' || $right['source'] !== 'google-calendar') {
            return false;
        }

        if (($left['external_id'] ?? null) !== null && ($right['external_id'] ?? null) !== null) {
            return (string) $left['external_id'] === (string) $right['external_id'];
        }

        $leftStart = $this->parseDateTime($left['start_time']);
        $leftEnd = $this->parseDateTime($left['end_time']);
        $rightStart = $this->parseDateTime($right['start_time']);
        $rightEnd = $this->parseDateTime($right['end_time']);

        if (! $leftStart instanceof \DateTimeImmutable || ! $leftEnd instanceof \DateTimeImmutable || ! $rightStart instanceof \DateTimeImmutable || ! $rightEnd instanceof \DateTimeImmutable) {
            return false;
        }

        if ($leftStart->format('Y-m-d') !== $rightStart->format('Y-m-d')) {
            return false;
        }

        $durationDelta = abs(($leftEnd->getTimestamp() - $leftStart->getTimestamp()) - ($rightEnd->getTimestamp() - $rightStart->getTimestamp()));
        $startDelta = abs($leftStart->getTimestamp() - $rightStart->getTimestamp());

        return $durationDelta <= 900 && $startDelta <= 3600;
    }

    private function getEntityValue(mixed $entity, string $field): mixed
    {
        if (is_object($entity) && method_exists($entity, 'get')) {
            return $entity->get($field);
        }

        return null;
    }

    private function parseDateTime(mixed $value): ?\DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{person_name: string, person_email: string, summary: string, occurred: string}>
     */
    private function buildNormalizedPeople(string $tenantId, \DateTimeImmutable $since): array
    {
        if ($this->personRepo === null) {
            return [];
        }

        $people = array_values(array_filter(
            $this->personRepo->findBy([]),
            function ($person) use ($tenantId, $since): bool {
                $tenant = (string) ($this->getEntityValue($person, 'tenant_id') ?? '');
                if ($tenant !== '' && $tenant !== $tenantId) {
                    return false;
                }

                if ((string) ($this->getEntityValue($person, 'last_inbox_category') ?? '') !== 'people') {
                    return false;
                }

                $lastInteraction = $this->parseDateTime($this->getEntityValue($person, 'last_interaction_at'));

                return $lastInteraction instanceof \DateTimeImmutable && $lastInteraction >= $since;
            },
        ));

        usort($people, fn ($a, $b): int => ((string) $this->getEntityValue($b, 'last_interaction_at')) <=> ((string) $this->getEntityValue($a, 'last_interaction_at')));

        return array_map(fn ($person): array => [
            'person_name' => (string) ($this->getEntityValue($person, 'name') ?? $this->getEntityValue($person, 'email') ?? ''),
            'person_email' => (string) ($this->getEntityValue($person, 'email') ?? ''),
            'summary' => (string) ($this->getEntityValue($person, 'latest_summary') ?? ''),
            'occurred' => (string) ($this->getEntityValue($person, 'last_interaction_at') ?? ''),
        ], $people);
    }

    /**
     * @return list<array{person_name: string, person_email: string, summary: string, occurred: string}>
     */
    private function buildNormalizedTriage(string $tenantId, \DateTimeImmutable $since): array
    {
        if ($this->triageRepo === null) {
            return [];
        }

        $entries = array_values(array_filter(
            $this->triageRepo->findBy([]),
            function ($entry) use ($tenantId, $since): bool {
                if ((string) ($this->getEntityValue($entry, 'status') ?? 'open') !== 'open') {
                    return false;
                }

                $tenant = (string) ($this->getEntityValue($entry, 'tenant_id') ?? '');
                if ($tenant !== '' && $tenant !== $tenantId) {
                    return false;
                }

                $occurredAt = $this->parseDateTime($this->getEntityValue($entry, 'occurred_at'));

                return $occurredAt instanceof \DateTimeImmutable && $occurredAt >= $since;
            },
        ));

        usort($entries, fn ($a, $b): int => ((string) $this->getEntityValue($b, 'occurred_at')) <=> ((string) $this->getEntityValue($a, 'occurred_at')));

        return array_map(fn ($entry): array => [
            'person_name' => (string) ($this->getEntityValue($entry, 'sender_name') ?? 'Unknown sender'),
            'person_email' => (string) ($this->getEntityValue($entry, 'sender_email') ?? ''),
            'summary' => (string) ($this->getEntityValue($entry, 'summary') ?? ''),
            'occurred' => (string) ($this->getEntityValue($entry, 'occurred_at') ?? ''),
        ], $entries);
    }

    private function buildWorkspaceData(array $recentEvents, string $tenantId, ?string $workspaceUuid = null): array
    {
        if ($this->workspaceRepo === null) {
            return [];
        }
        $workspaces = array_values(array_filter(
            $this->workspaceRepo->findBy([]),
            function ($workspace) use ($tenantId, $workspaceUuid): bool {
                if (! $this->entityMatchesTenant($workspace, $tenantId)) {
                    return false;
                }

                if ($workspaceUuid !== null) {
                    return (string) ($this->getEntityValue($workspace, 'uuid') ?? '') === $workspaceUuid;
                }

                return true;
            },
        ));

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

    private function entityMatchesTenant(mixed $entity, string $tenantId): bool
    {
        $entityTenant = $this->getEntityValue($entity, 'tenant_id') ?? $this->getEntityValue($entity, 'account_id');
        if (! is_scalar($entityTenant) || trim((string) $entityTenant) === '') {
            return $tenantId === 'default';
        }

        return trim((string) $entityTenant) === $tenantId;
    }

    private function eventMatchesScope(mixed $event, string $tenantId, ?string $workspaceUuid = null): bool
    {
        if (! $this->entityMatchesTenant($event, $tenantId)) {
            return false;
        }

        if ($workspaceUuid === null) {
            return true;
        }

        return (string) ($this->getEntityValue($event, 'workspace_id') ?? '') === $workspaceUuid;
    }
}
