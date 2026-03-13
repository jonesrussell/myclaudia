<?php

declare(strict_types=1);

namespace Claudriel\Service\Audit;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

final class CommitmentExtractionAuditService
{
    private const CONFIDENCE_BUCKETS = [
        '0.0-0.3' => [0.0, 0.3],
        '0.3-0.5' => [0.3, 0.5],
        '0.5-0.7' => [0.5, 0.7],
        '0.7-0.9' => [0.7, 0.9],
        '0.9-1.0' => [0.9, 1.0],
    ];

    /** @var array<string, McEvent|null> */
    private array $eventCache = [];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    /**
     * @return array{
     *   total_extraction_attempts: int,
     *   total_successful_commitments: int,
     *   total_low_confidence_logs: int
     * }
     */
    public function getSummaryMetrics(): array
    {
        $successfulCommitments = $this->getSuccessfulCommitments();
        $logs = $this->getLogEntries();

        return [
            'total_extraction_attempts' => count($successfulCommitments) + count($logs),
            'total_successful_commitments' => count($successfulCommitments),
            'total_low_confidence_logs' => count($logs),
        ];
    }

    /**
     * @return array<int, array{label: string, count: int}>
     */
    public function getConfidenceDistribution(): array
    {
        $distribution = [];
        foreach (array_keys(self::CONFIDENCE_BUCKETS) as $label) {
            $distribution[$label] = 0;
        }

        foreach ($this->getAllExtractionAttempts() as $attempt) {
            $confidence = $attempt['confidence'];
            $distribution[$this->resolveBucketLabel($confidence)]++;
        }

        $results = [];
        foreach ($distribution as $label => $count) {
            $results[] = ['label' => $label, 'count' => $count];
        }

        return $results;
    }

    /**
     * @return array<int, array{
     *   sender: string,
     *   total_attempts: int,
     *   low_confidence_attempts: int,
     *   successful_commitments: int,
     *   low_confidence_rate: float
     * }>
     */
    public function getTopSenders(): array
    {
        $senderStats = [];

        foreach ($this->getSuccessfulCommitments() as $commitment) {
            $sender = $this->resolveSenderForCommitment($commitment);
            if ($sender === null) {
                continue;
            }

            $senderStats[$sender] ??= [
                'sender' => $sender,
                'total_attempts' => 0,
                'low_confidence_attempts' => 0,
                'successful_commitments' => 0,
                'low_confidence_rate' => 0.0,
            ];

            $senderStats[$sender]['total_attempts']++;
            $senderStats[$sender]['successful_commitments']++;
        }

        foreach ($this->getLogEntries() as $log) {
            $sender = $this->resolveSenderForLog($log);
            if ($sender === null) {
                continue;
            }

            $senderStats[$sender] ??= [
                'sender' => $sender,
                'total_attempts' => 0,
                'low_confidence_attempts' => 0,
                'successful_commitments' => 0,
                'low_confidence_rate' => 0.0,
            ];

            $senderStats[$sender]['total_attempts']++;
            $senderStats[$sender]['low_confidence_attempts']++;
        }

        foreach ($senderStats as &$stats) {
            $stats['low_confidence_rate'] = round($stats['low_confidence_attempts'] / $stats['total_attempts'], 4);
        }
        unset($stats);

        usort($senderStats, function (array $a, array $b): int {
            return [$b['low_confidence_rate'], $b['low_confidence_attempts'], $b['total_attempts'], $a['sender']]
                <=> [$a['low_confidence_rate'], $a['low_confidence_attempts'], $a['total_attempts'], $b['sender']];
        });

        return array_slice($senderStats, 0, 10);
    }

    /**
     * @return array<string, int>
     */
    public function getFailureCategoryCounts(): array
    {
        $counts = $this->initializeFailureCategoryCounts();

        foreach ($this->getLogEntries() as $log) {
            $category = $this->normalizeFailureCategory($log->get('failure_category'));
            $counts[$category]++;
        }

        return $counts;
    }

    /**
     * @return list<array{category: string, count: int, rate: float}>
     */
    public function getFailureCategoryDistribution(): array
    {
        return $this->buildFailureCategoryDistribution($this->getFailureCategoryCounts());
    }

    /**
     * @return array{
     *   sender: string,
     *   total_low_confidence_logs: int,
     *   categories: list<array{category: string, count: int, rate: float}>
     * }
     */
    public function getSenderFailureCategories(string $email): array
    {
        $sender = $this->normalizeSender($email) ?? strtolower(trim($email));
        $counts = $this->initializeFailureCategoryCounts();
        $total = 0;

        foreach ($this->getLogEntries() as $log) {
            if ($this->resolveSenderForLog($log) !== $sender) {
                continue;
            }

            $counts[$this->normalizeFailureCategory($log->get('failure_category'))]++;
            $total++;
        }

        return [
            'sender' => $sender,
            'total_low_confidence_logs' => $total,
            'categories' => $this->buildFailureCategoryDistribution($counts),
        ];
    }

    /**
     * @return array{
     *   start_date: string,
     *   end_date: string,
     *   total_attempts: int,
     *   successful_extractions: int,
     *   low_confidence_logs: int,
     *   average_confidence: float,
     *   low_confidence_rate: float,
     *   failure_category_counts: array<string, int>,
     *   failure_category_distribution: list<array{category: string, count: int, rate: float}>
     * }
     */
    public function getQualitySnapshot(int $days, ?DateTimeImmutable $endDate = null, ?string $senderEmail = null): array
    {
        $days = max(1, $days);
        $endDate = ($endDate ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $startDate = $endDate->sub(new DateInterval(sprintf('P%dD', $days - 1)));
        $sender = $senderEmail !== null ? ($this->normalizeSender($senderEmail) ?? strtolower(trim($senderEmail))) : null;

        $totalAttempts = 0;
        $successfulExtractions = 0;
        $lowConfidenceLogs = 0;
        $confidenceTotal = 0.0;
        $failureCategoryCounts = $this->initializeFailureCategoryCounts();

        foreach ($this->getNormalizedAttempts() as $attempt) {
            if ($sender !== null && $attempt['sender'] !== $sender) {
                continue;
            }

            $occurredAt = $this->parseDateTime($attempt['occurred_at']);
            if ($occurredAt === null) {
                continue;
            }

            $occurredDate = $occurredAt->setTime(0, 0);
            if ($occurredDate < $startDate || $occurredDate > $endDate) {
                continue;
            }

            $totalAttempts++;
            $successfulExtractions += $attempt['is_successful'] ? 1 : 0;
            $lowConfidenceLogs += $attempt['is_low_confidence'] ? 1 : 0;
            $confidenceTotal += $attempt['confidence'];

            if ($attempt['is_low_confidence']) {
                $failureCategoryCounts[$this->normalizeFailureCategory($attempt['failure_category'])]++;
            }
        }

        return [
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'total_attempts' => $totalAttempts,
            'successful_extractions' => $successfulExtractions,
            'low_confidence_logs' => $lowConfidenceLogs,
            'average_confidence' => $totalAttempts > 0 ? round($confidenceTotal / $totalAttempts, 4) : 0.0,
            'low_confidence_rate' => $totalAttempts > 0 ? round($lowConfidenceLogs / $totalAttempts, 4) : 0.0,
            'failure_category_counts' => $failureCategoryCounts,
            'failure_category_distribution' => $this->buildFailureCategoryDistribution($failureCategoryCounts),
        ];
    }

    /**
     * @return array{
     *   window_days: int,
     *   generated_at: string,
     *   summary: array{
     *     total_attempts: int,
     *     successful_extractions: int,
     *     low_confidence_logs: int,
     *     average_confidence: float,
     *     failure_categories: array<string, int>
     *   },
     *   series: list<array{
     *     date: string,
     *     label: string,
     *     total_attempts: int,
     *     successful_extractions: int,
     *     low_confidence_logs: int,
     *     average_confidence: float
     *   }>
     * }
     */
    public function getDailyTrends(int $days = 7): array
    {
        $days = max(1, $days);
        $end = new DateTimeImmutable('today');
        $start = $end->sub(new DateInterval(sprintf('P%dD', $days - 1)));

        $series = [];
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end->add(new DateInterval('P1D'))) as $date) {
            $key = $date->format('Y-m-d');
            $series[$key] = [
                'date' => $key,
                'label' => $date->format('M j'),
                'total_attempts' => 0,
                'successful_extractions' => 0,
                'low_confidence_logs' => 0,
                'average_confidence' => 0.0,
                '_confidence_total' => 0.0,
            ];
        }

        foreach ($this->getNormalizedAttempts() as $attempt) {
            $occurredAt = $this->parseDateTime($attempt['occurred_at']);
            if ($occurredAt === null) {
                continue;
            }

            $key = $occurredAt->format('Y-m-d');
            if (! isset($series[$key])) {
                continue;
            }

            $series[$key]['total_attempts']++;
            $series[$key]['successful_extractions'] += $attempt['is_successful'] ? 1 : 0;
            $series[$key]['low_confidence_logs'] += $attempt['is_low_confidence'] ? 1 : 0;
            $series[$key]['_confidence_total'] += $attempt['confidence'];
        }

        return [
            'window_days' => $days,
            'generated_at' => gmdate(DateTimeInterface::ATOM),
            'summary' => $this->buildTrendSummary($series, $this->getFailureCategoryCounts()),
            'series' => $this->finalizeSeries($series),
        ];
    }

    /**
     * @return array{
     *   window_months: int,
     *   generated_at: string,
     *   summary: array{
     *     total_attempts: int,
     *     successful_extractions: int,
     *     low_confidence_logs: int,
     *     average_confidence: float,
     *     failure_categories: array<string, int>
     *   },
     *   series: list<array{
     *     month: string,
     *     label: string,
     *     total_attempts: int,
     *     successful_extractions: int,
     *     low_confidence_logs: int,
     *     average_confidence: float
     *   }>
     * }
     */
    public function getMonthlyTrends(int $months = 1): array
    {
        $months = max(1, $months);
        $end = new DateTimeImmutable('first day of this month');
        $start = $end->sub(new DateInterval(sprintf('P%dM', $months - 1)));

        $series = [];
        foreach (new DatePeriod($start, new DateInterval('P1M'), $end->add(new DateInterval('P1M'))) as $month) {
            $key = $month->format('Y-m');
            $series[$key] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'total_attempts' => 0,
                'successful_extractions' => 0,
                'low_confidence_logs' => 0,
                'average_confidence' => 0.0,
                '_confidence_total' => 0.0,
            ];
        }

        foreach ($this->getNormalizedAttempts() as $attempt) {
            $occurredAt = $this->parseDateTime($attempt['occurred_at']);
            if ($occurredAt === null) {
                continue;
            }

            $key = $occurredAt->format('Y-m');
            if (! isset($series[$key])) {
                continue;
            }

            $series[$key]['total_attempts']++;
            $series[$key]['successful_extractions'] += $attempt['is_successful'] ? 1 : 0;
            $series[$key]['low_confidence_logs'] += $attempt['is_low_confidence'] ? 1 : 0;
            $series[$key]['_confidence_total'] += $attempt['confidence'];
        }

        return [
            'window_months' => $months,
            'generated_at' => gmdate(DateTimeInterface::ATOM),
            'summary' => $this->buildTrendSummary($series, $this->getFailureCategoryCounts()),
            'series' => $this->finalizeSeries($series),
        ];
    }

    /**
     * @return array{
     *   sender: string,
     *   window_days: int,
     *   generated_at: string,
     *   summary: array{
     *     total_attempts: int,
     *     successful_extractions: int,
     *     low_confidence_logs: int,
     *     low_confidence_rate: float,
     *     average_confidence: float,
     *     failure_categories: array<string, int>
     *   },
     *   confidence_distribution: list<array{label: string, count: int}>,
     *   failure_categories: list<array{category: string, count: int, rate: float}>,
     *   daily_trends: list<array{
     *     date: string,
     *     label: string,
     *     total_attempts: int,
     *     successful_extractions: int,
     *     low_confidence_logs: int,
     *     average_confidence: float,
     *     low_confidence_rate: float
     *   }>
     * }
     */
    public function getSenderTrends(string $senderEmail, int $days = 30): array
    {
        $sender = $this->normalizeSender($senderEmail) ?? strtolower(trim($senderEmail));
        $days = max(1, $days);
        $end = new DateTimeImmutable('today');
        $start = $end->sub(new DateInterval(sprintf('P%dD', $days - 1)));
        $failureCategoryCounts = $this->initializeFailureCategoryCounts();

        $distribution = [];
        foreach (array_keys(self::CONFIDENCE_BUCKETS) as $label) {
            $distribution[$label] = 0;
        }

        $series = [];
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end->add(new DateInterval('P1D'))) as $date) {
            $key = $date->format('Y-m-d');
            $series[$key] = [
                'date' => $key,
                'label' => $date->format('M j'),
                'total_attempts' => 0,
                'successful_extractions' => 0,
                'low_confidence_logs' => 0,
                'average_confidence' => 0.0,
                'low_confidence_rate' => 0.0,
                '_confidence_total' => 0.0,
            ];
        }

        foreach ($this->getNormalizedAttempts() as $attempt) {
            if ($attempt['sender'] !== $sender) {
                continue;
            }

            $distribution[$this->resolveBucketLabel($attempt['confidence'])]++;
            if ($attempt['is_low_confidence']) {
                $failureCategoryCounts[$this->normalizeFailureCategory($attempt['failure_category'])]++;
            }

            $occurredAt = $this->parseDateTime($attempt['occurred_at']);
            if ($occurredAt === null) {
                continue;
            }

            $key = $occurredAt->format('Y-m-d');
            if (! isset($series[$key])) {
                continue;
            }

            $series[$key]['total_attempts']++;
            $series[$key]['successful_extractions'] += $attempt['is_successful'] ? 1 : 0;
            $series[$key]['low_confidence_logs'] += $attempt['is_low_confidence'] ? 1 : 0;
            $series[$key]['_confidence_total'] += $attempt['confidence'];
        }

        $dailyTrends = [];
        $totals = [
            'total_attempts' => 0,
            'successful_extractions' => 0,
            'low_confidence_logs' => 0,
            'average_confidence' => 0.0,
            '_confidence_total' => 0.0,
        ];

        foreach ($series as $point) {
            $totalAttempts = $point['total_attempts'];
            $averageConfidence = $totalAttempts > 0 ? round($point['_confidence_total'] / $totalAttempts, 4) : 0.0;
            $lowConfidenceRate = $totalAttempts > 0 ? round($point['low_confidence_logs'] / $totalAttempts, 4) : 0.0;

            $dailyTrends[] = [
                'date' => $point['date'],
                'label' => $point['label'],
                'total_attempts' => $totalAttempts,
                'successful_extractions' => $point['successful_extractions'],
                'low_confidence_logs' => $point['low_confidence_logs'],
                'average_confidence' => $averageConfidence,
                'low_confidence_rate' => $lowConfidenceRate,
            ];

            $totals['total_attempts'] += $totalAttempts;
            $totals['successful_extractions'] += $point['successful_extractions'];
            $totals['low_confidence_logs'] += $point['low_confidence_logs'];
            $totals['_confidence_total'] += $point['_confidence_total'];
        }

        $confidenceDistribution = [];
        foreach ($distribution as $label => $count) {
            $confidenceDistribution[] = ['label' => $label, 'count' => $count];
        }

        return [
            'sender' => $sender,
            'window_days' => $days,
            'generated_at' => gmdate(DateTimeInterface::ATOM),
            'summary' => [
                'total_attempts' => $totals['total_attempts'],
                'successful_extractions' => $totals['successful_extractions'],
                'low_confidence_logs' => $totals['low_confidence_logs'],
                'low_confidence_rate' => $totals['total_attempts'] > 0
                    ? round($totals['low_confidence_logs'] / $totals['total_attempts'], 4)
                    : 0.0,
                'average_confidence' => $totals['total_attempts'] > 0
                    ? round($totals['_confidence_total'] / $totals['total_attempts'], 4)
                    : 0.0,
                'failure_categories' => $failureCategoryCounts,
            ],
            'confidence_distribution' => $confidenceDistribution,
            'failure_categories' => $this->buildFailureCategoryDistribution($failureCategoryCounts),
            'daily_trends' => $dailyTrends,
        ];
    }

    /**
     * @return array{
     *   items: array<int, array<string, mixed>>,
     *   page: int,
     *   per_page: int,
     *   total: int,
     *   total_pages: int
     * }
     */
    public function getPaginatedLogs(int $page = 1, int $perPage = 25): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $logs = array_map(fn (CommitmentExtractionLog $log): array => $this->normalizeLog($log), $this->getLogEntries());
        usort($logs, fn (array $a, array $b): int => [$b['created_at'], $b['id']] <=> [$a['created_at'], $a['id']]);

        $total = count($logs);
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * $perPage;

        return [
            'items' => array_slice($logs, $offset, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLogDetail(int|string $id): ?array
    {
        /** @var CommitmentExtractionLog|null $log */
        $log = $this->getLogStorage()->load((string) $id);
        if ($log === null) {
            return null;
        }

        return $this->normalizeLog($log);
    }

    /**
     * @return list<Commitment>
     */
    private function getSuccessfulCommitments(): array
    {
        $storage = $this->entityTypeManager->getStorage('commitment');
        $ids = $storage->getQuery()->execute();
        /** @var list<Commitment> $entities */
        $entities = array_values($storage->loadMultiple($ids));

        return array_values(array_filter(
            $entities,
            fn (Commitment $entity): bool => (float) ($entity->get('confidence') ?? 0.0) >= 0.7
        ));
    }

    /**
     * @return list<CommitmentExtractionLog>
     */
    private function getLogEntries(): array
    {
        $storage = $this->getLogStorage();
        $ids = $storage->getQuery()->execute();

        /** @var list<CommitmentExtractionLog> $logs */
        $logs = array_values($storage->loadMultiple($ids));

        return $logs;
    }

    private function getLogStorage(): EntityStorageInterface
    {
        return $this->entityTypeManager->getStorage('commitment_extraction_log');
    }

    /**
     * @return list<array{
     *   confidence: float,
     *   sender: string|null,
     *   occurred_at: string|null,
     *   failure_category: string|null,
     *   is_successful: bool,
     *   is_low_confidence: bool
     * }>
     */
    private function getAllExtractionAttempts(): array
    {
        $attempts = [];

        foreach ($this->getSuccessfulCommitments() as $commitment) {
            $attempts[] = [
                'confidence' => (float) ($commitment->get('confidence') ?? 0.0),
                'sender' => $this->resolveSenderForCommitment($commitment),
                'occurred_at' => $this->resolveOccurredAtForCommitment($commitment),
                'failure_category' => null,
                'is_successful' => true,
                'is_low_confidence' => false,
            ];
        }

        foreach ($this->getLogEntries() as $log) {
            $attempts[] = [
                'confidence' => (float) ($log->get('confidence') ?? 0.0),
                'sender' => $this->resolveSenderForLog($log),
                'occurred_at' => $this->resolveOccurredAtForLog($log),
                'failure_category' => $this->normalizeFailureCategory($log->get('failure_category')),
                'is_successful' => false,
                'is_low_confidence' => true,
            ];
        }

        return $attempts;
    }

    /**
     * @return list<array{
     *   confidence: float,
     *   sender: string|null,
     *   occurred_at: string|null,
     *   failure_category: string|null,
     *   is_successful: bool,
     *   is_low_confidence: bool
     * }>
     */
    private function getNormalizedAttempts(): array
    {
        return $this->getAllExtractionAttempts();
    }

    private function resolveBucketLabel(float $confidence): string
    {
        foreach (self::CONFIDENCE_BUCKETS as $label => [$min, $max]) {
            $isFinalBucket = $max === 1.0;
            if ($confidence >= $min && ($confidence < $max || ($isFinalBucket && $confidence <= $max))) {
                return $label;
            }
        }

        return '0.0-0.3';
    }

    private function resolveSenderForCommitment(Commitment $commitment): ?string
    {
        $eventId = $commitment->get('source_event_id');
        if ($eventId === null) {
            return null;
        }

        $event = $this->loadEvent((string) $eventId);
        if ($event === null) {
            return null;
        }

        return $this->resolveSenderFromPayload($event->get('payload')) ?? $this->normalizeSender($event->get('from_email'));
    }

    private function resolveSenderForLog(CommitmentExtractionLog $log): ?string
    {
        $sender = $this->resolveSenderFromPayload($log->get('raw_event_payload'));
        if ($sender !== null) {
            return $sender;
        }

        $eventId = $log->get('mc_event_id');
        if ($eventId === null) {
            return null;
        }

        $event = $this->loadEvent((string) $eventId);
        if ($event === null) {
            return null;
        }

        return $this->resolveSenderFromPayload($event->get('payload')) ?? $this->normalizeSender($event->get('from_email'));
    }

    private function resolveSenderFromPayload(mixed $payload): ?string
    {
        if (is_string($payload) && $payload !== '') {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                return $this->extractSenderFromArray($decoded);
            }

            return null;
        }

        if (is_array($payload)) {
            return $this->extractSenderFromArray($payload);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractSenderFromArray(array $payload): ?string
    {
        foreach (['from_email', 'sender_email', 'sender'] as $key) {
            $sender = $this->normalizeSender($payload[$key] ?? null);
            if ($sender !== null) {
                return $sender;
            }
        }

        return null;
    }

    private function normalizeSender(mixed $sender): ?string
    {
        if (! is_string($sender)) {
            return null;
        }

        $sender = strtolower(trim($sender));

        return $sender === '' ? null : $sender;
    }

    private function resolveOccurredAtForCommitment(Commitment $commitment): ?string
    {
        $eventId = $commitment->get('source_event_id');
        if ($eventId === null) {
            return null;
        }

        $event = $this->loadEvent((string) $eventId);
        if ($event === null) {
            return null;
        }

        foreach (['occurred', 'created_at'] as $field) {
            $timestamp = $event->get($field);
            if (is_string($timestamp) && trim($timestamp) !== '') {
                return $timestamp;
            }
        }

        return null;
    }

    private function resolveOccurredAtForLog(CommitmentExtractionLog $log): ?string
    {
        $timestamp = $log->get('created_at');

        return is_string($timestamp) && trim($timestamp) !== '' ? $timestamp : null;
    }

    private function loadEvent(string $eventId): ?McEvent
    {
        if (! array_key_exists($eventId, $this->eventCache)) {
            /** @var McEvent|null $event */
            $event = $this->entityTypeManager->getStorage('mc_event')->load($eventId);
            $this->eventCache[$eventId] = $event;
        }

        return $this->eventCache[$eventId];
    }

    /**
     * @param  array<string, array<string, mixed>>  $series
     * @return array{
     *   total_attempts: int,
     *   successful_extractions: int,
     *   low_confidence_logs: int,
     *   average_confidence: float,
     *   failure_categories: array<string, int>
     * }
     */
    private function buildTrendSummary(array $series, array $failureCategories): array
    {
        $totalAttempts = 0;
        $successfulExtractions = 0;
        $lowConfidenceLogs = 0;
        $confidenceTotal = 0.0;

        foreach ($series as $point) {
            $totalAttempts += (int) $point['total_attempts'];
            $successfulExtractions += (int) $point['successful_extractions'];
            $lowConfidenceLogs += (int) $point['low_confidence_logs'];
            $confidenceTotal += (float) ($point['_confidence_total'] ?? 0.0);
        }

        return [
            'total_attempts' => $totalAttempts,
            'successful_extractions' => $successfulExtractions,
            'low_confidence_logs' => $lowConfidenceLogs,
            'average_confidence' => $totalAttempts > 0 ? round($confidenceTotal / $totalAttempts, 4) : 0.0,
            'failure_categories' => $failureCategories,
        ];
    }

    /**
     * @param  array<string, array<string, mixed>>  $series
     * @return list<array<string, mixed>>
     */
    private function finalizeSeries(array $series): array
    {
        $results = [];

        foreach ($series as $point) {
            $totalAttempts = (int) $point['total_attempts'];
            $point['average_confidence'] = $totalAttempts > 0
                ? round(((float) $point['_confidence_total']) / $totalAttempts, 4)
                : 0.0;
            unset($point['_confidence_total']);
            $results[] = $point;
        }

        return $results;
    }

    private function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, int>
     */
    private function initializeFailureCategoryCounts(): array
    {
        $counts = [];
        foreach (CommitmentExtractionLog::FAILURE_CATEGORIES as $category) {
            $counts[$category] = 0;
        }

        return $counts;
    }

    private function normalizeFailureCategory(mixed $category): string
    {
        if (is_string($category) && in_array($category, CommitmentExtractionLog::FAILURE_CATEGORIES, true)) {
            return $category;
        }

        return 'unknown';
    }

    /**
     * @param  array<string, int>  $counts
     * @return list<array{category: string, count: int, rate: float}>
     */
    private function buildFailureCategoryDistribution(array $counts): array
    {
        $total = array_sum($counts);
        $distribution = [];

        foreach ($counts as $category => $count) {
            $distribution[] = [
                'category' => $category,
                'count' => $count,
                'rate' => $total > 0 ? round($count / $total, 4) : 0.0,
            ];
        }

        return $distribution;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeLog(CommitmentExtractionLog $log): array
    {
        return [
            'id' => $log->id(),
            'uuid' => $log->get('uuid'),
            'mc_event_id' => $log->get('mc_event_id'),
            'raw_event_payload' => $log->get('raw_event_payload'),
            'extracted_commitment_payload' => $log->get('extracted_commitment_payload'),
            'confidence' => (float) ($log->get('confidence') ?? 0.0),
            'failure_category' => $this->normalizeFailureCategory($log->get('failure_category')),
            'created_at' => $log->get('created_at'),
            'sender' => $this->resolveSenderForLog($log),
        ];
    }
}
