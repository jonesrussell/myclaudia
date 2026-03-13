<?php

declare(strict_types=1);

namespace Claudriel\Service\Audit;

use Waaseyaa\Entity\EntityInterface;
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
            $confidence = (float) ($attempt['confidence'] ?? 0.0);
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
            $stats['low_confidence_rate'] = $stats['total_attempts'] > 0
                ? round($stats['low_confidence_attempts'] / $stats['total_attempts'], 4)
                : 0.0;
        }
        unset($stats);

        usort($senderStats, function (array $a, array $b): int {
            return [$b['low_confidence_rate'], $b['low_confidence_attempts'], $b['total_attempts'], $a['sender']]
                <=> [$a['low_confidence_rate'], $a['low_confidence_attempts'], $a['total_attempts'], $b['sender']];
        });

        return array_slice($senderStats, 0, 10);
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

        $logs = array_map(fn (EntityInterface $log): array => $this->normalizeLog($log), $this->getLogEntries());
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
        $log = $this->getLogStorage()->load((string) $id);
        if ($log === null) {
            return null;
        }

        return $this->normalizeLog($log);
    }

    /**
     * @return list<EntityInterface>
     */
    private function getSuccessfulCommitments(): array
    {
        $storage = $this->entityTypeManager->getStorage('commitment');
        $ids = $storage->getQuery()->execute();
        $entities = array_values($storage->loadMultiple($ids));

        return array_values(array_filter(
            $entities,
            fn (EntityInterface $entity): bool => (float) ($entity->get('confidence') ?? 0.0) >= 0.7
        ));
    }

    /**
     * @return list<EntityInterface>
     */
    private function getLogEntries(): array
    {
        $storage = $this->getLogStorage();
        $ids = $storage->getQuery()->execute();

        return array_values($storage->loadMultiple($ids));
    }

    private function getLogStorage(): EntityStorageInterface
    {
        return $this->entityTypeManager->getStorage('commitment_extraction_log');
    }

    /**
     * @return list<array{confidence: float}>
     */
    private function getAllExtractionAttempts(): array
    {
        $attempts = [];

        foreach ($this->getSuccessfulCommitments() as $commitment) {
            $attempts[] = ['confidence' => (float) ($commitment->get('confidence') ?? 0.0)];
        }

        foreach ($this->getLogEntries() as $log) {
            $attempts[] = ['confidence' => (float) ($log->get('confidence') ?? 0.0)];
        }

        return $attempts;
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

    private function resolveSenderForCommitment(EntityInterface $commitment): ?string
    {
        $eventId = $commitment->get('source_event_id');
        if ($eventId === null) {
            return null;
        }

        $event = $this->entityTypeManager->getStorage('mc_event')->load((string) $eventId);
        if ($event === null) {
            return null;
        }

        return $this->resolveSenderFromPayload($event->get('payload')) ?? $this->normalizeSender($event->get('from_email'));
    }

    private function resolveSenderForLog(EntityInterface $log): ?string
    {
        $sender = $this->resolveSenderFromPayload($log->get('raw_event_payload'));
        if ($sender !== null) {
            return $sender;
        }

        $eventId = $log->get('mc_event_id');
        if ($eventId === null) {
            return null;
        }

        $event = $this->entityTypeManager->getStorage('mc_event')->load((string) $eventId);
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
     * @param array<string, mixed> $payload
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

    /**
     * @return array<string, mixed>
     */
    private function normalizeLog(EntityInterface $log): array
    {
        return [
            'id' => $log->id(),
            'uuid' => $log->get('uuid'),
            'mc_event_id' => $log->get('mc_event_id'),
            'raw_event_payload' => $log->get('raw_event_payload'),
            'extracted_commitment_payload' => $log->get('extracted_commitment_payload'),
            'confidence' => (float) ($log->get('confidence') ?? 0.0),
            'created_at' => $log->get('created_at'),
            'sender' => $this->resolveSenderForLog($log),
        ];
    }
}
