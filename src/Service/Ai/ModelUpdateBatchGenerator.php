<?php

declare(strict_types=1);

namespace Claudriel\Service\Ai;

use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Claudriel\Service\Audit\CommitmentExtractionDriftDetector;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;

final class ModelUpdateBatchGenerator
{
    public function __construct(
        private readonly TrainingExportService $trainingExportService,
        private readonly CommitmentExtractionAuditService $auditService,
        private readonly CommitmentExtractionDriftDetector $driftDetector,
        private readonly ExtractionSelfAssessmentService $selfAssessmentService,
        private readonly ExtractionImprovementSuggestionService $improvementSuggestionService,
        private readonly string $storageDirectory = __DIR__.'/../../../var/model-updates',
        private readonly ?DateTimeImmutable $referenceDate = null,
    ) {}

    /**
     * @return array{
     *   batch_id: string,
     *   metadata: array{
     *     generated_at: string,
     *     window_days: int,
     *     total_samples: int,
     *     failure_rate: float,
     *     drift_classification: string
     *   },
     *   samples: array{
     *     daily: array<string, mixed>,
     *     sender_hotspots: list<array{
     *       sender: string,
     *       classification: string,
     *       avg_confidence_drop: float,
     *       low_confidence_rate_delta: float,
     *       total_attempts: int,
     *       export: array<string, mixed>
     *     }>
     *   },
     *   failure_summary: array{
     *     total_failures: int,
     *     top_failure_categories: list<array{category: string, count: int, percentage: float}>
     *   },
     *   drift_summary: array<string, mixed>,
     *   improvement_suggestions: list<array{
     *     category: string,
     *     severity: string,
     *     rationale: string,
     *     recommended_action: string
     *   }>,
     *   recommended_actions: list<string>
     * }
     */
    public function generateBatch(int $days = 14): array
    {
        $days = max(1, $days);
        $dailyExport = $this->trainingExportService->exportDailySamples($days);
        $snapshot = $this->auditService->getQualitySnapshot($days);
        $drift = $this->driftDetector->detectDailyDrift(max(14, $days * 2));
        $assessment = $this->selfAssessmentService->generateAssessment($days);
        $suggestionReport = $this->improvementSuggestionService->generateSuggestions($days);
        $batchId = $this->generateBatchId();

        $batch = [
            'batch_id' => $batchId,
            'metadata' => [
                'generated_at' => $this->resolveReferenceDate()->format(DateTimeInterface::ATOM),
                'window_days' => $days,
                'total_samples' => $this->countDailySamples($dailyExport),
                'failure_rate' => $snapshot['low_confidence_rate'],
                'drift_classification' => $drift['classification'],
            ],
            'samples' => [
                'daily' => $dailyExport,
                'sender_hotspots' => $this->buildSenderHotspotExports($assessment['sender_hotspots'], $days),
            ],
            'failure_summary' => [
                'total_failures' => $snapshot['low_confidence_logs'],
                'top_failure_categories' => $this->buildFailureSummary($snapshot['failure_category_distribution']),
            ],
            'drift_summary' => $drift,
            'improvement_suggestions' => $suggestionReport['suggestions'],
            'recommended_actions' => $this->buildRecommendedActions(
                $suggestionReport['suggestions'],
                $snapshot['failure_category_distribution'],
                $assessment['sender_hotspots'],
                $days,
            ),
        ];

        return $batch;
    }

    public function generateBatchId(): string
    {
        $date = $this->resolveReferenceDate()->format('Y-m-d');
        $sequence = 1;

        if (is_dir($this->storageDirectory)) {
            $pattern = sprintf('%s/batch-%s-*.json', rtrim($this->storageDirectory, '/'), $date);
            $existing = glob($pattern) ?: [];
            $sequence = count($existing) + 1;
        }

        return sprintf('batch-%s-%03d', $date, $sequence);
    }

    /**
     * @param  array<string, mixed>  $batch
     */
    public function saveBatch(array $batch): void
    {
        $batchId = $batch['batch_id'] ?? null;
        if (! is_string($batchId) || $batchId === '') {
            throw new RuntimeException('Batch must include a batch_id before it can be saved.');
        }

        if (! is_dir($this->storageDirectory)) {
            mkdir($this->storageDirectory, 0755, true);
        }

        file_put_contents(
            $this->getBatchPath($batchId),
            json_encode($batch, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
        );
    }

    public function getBatchPath(string $batchId): string
    {
        return rtrim($this->storageDirectory, '/').'/'.$batchId.'.json';
    }

    /**
     * @return list<array{
     *   batch_id: string,
     *   path: string,
     *   generated_at: string|null,
     *   window_days: int|null,
     *   total_samples: int|null,
     *   failure_rate: float|null,
     *   drift_classification: string|null
     * }>
     */
    public function listStoredBatches(int $limit = 10): array
    {
        if (! is_dir($this->storageDirectory)) {
            return [];
        }

        $files = glob(rtrim($this->storageDirectory, '/').'/*.json') ?: [];
        rsort($files);

        $batches = [];
        foreach (array_slice($files, 0, max(1, $limit)) as $file) {
            $contents = file_get_contents($file);
            if (! is_string($contents) || trim($contents) === '') {
                continue;
            }

            /** @var array<string, mixed> $payload */
            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            $metadata = $payload['metadata'] ?? [];

            $batches[] = [
                'batch_id' => (string) ($payload['batch_id'] ?? basename($file, '.json')),
                'path' => $file,
                'generated_at' => is_string($metadata['generated_at'] ?? null) ? $metadata['generated_at'] : null,
                'window_days' => is_int($metadata['window_days'] ?? null) ? $metadata['window_days'] : null,
                'total_samples' => is_int($metadata['total_samples'] ?? null) ? $metadata['total_samples'] : null,
                'failure_rate' => is_float($metadata['failure_rate'] ?? null) || is_int($metadata['failure_rate'] ?? null)
                    ? (float) $metadata['failure_rate']
                    : null,
                'drift_classification' => is_string($metadata['drift_classification'] ?? null) ? $metadata['drift_classification'] : null,
            ];
        }

        return $batches;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadBatch(string $batchId): ?array
    {
        $path = $this->getBatchPath($batchId);
        if (! is_file($path)) {
            return null;
        }

        $content = file_get_contents($path);
        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        /** @var array<string, mixed> $batch */
        $batch = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        return $batch;
    }

    /**
     * @param  array<string, mixed>  $dailyExport
     */
    private function countDailySamples(array $dailyExport): int
    {
        $total = 0;
        foreach ($dailyExport['days'] as $day) {
            $total += count($day['samples']);
        }

        return $total;
    }

    /**
     * @param  list<array{
     *   sender: string,
     *   classification: string,
     *   avg_confidence_drop: float,
     *   low_confidence_rate_delta: float,
     *   total_attempts: int
     * }>  $hotspots
     * @return list<array{
     *   sender: string,
     *   classification: string,
     *   avg_confidence_drop: float,
     *   low_confidence_rate_delta: float,
     *   total_attempts: int,
     *   export: array<string, mixed>
     * }>
     */
    private function buildSenderHotspotExports(array $hotspots, int $days): array
    {
        $results = [];

        foreach ($hotspots as $hotspot) {
            $results[] = [
                'sender' => $hotspot['sender'],
                'classification' => $hotspot['classification'],
                'avg_confidence_drop' => $hotspot['avg_confidence_drop'],
                'low_confidence_rate_delta' => $hotspot['low_confidence_rate_delta'],
                'total_attempts' => $hotspot['total_attempts'],
                'export' => $this->trainingExportService->exportSenderSamples($hotspot['sender'], max(30, $days)),
            ];
        }

        return $results;
    }

    /**
     * @param  list<array{category: string, count: int, rate: float}>  $distribution
     * @return list<array{category: string, count: int, percentage: float}>
     */
    private function buildFailureSummary(array $distribution): array
    {
        $summary = [];

        foreach ($distribution as $category) {
            if ($category['count'] <= 0) {
                continue;
            }

            $summary[] = [
                'category' => $category['category'],
                'count' => $category['count'],
                'percentage' => round($category['rate'] * 100, 1),
            ];
        }

        usort($summary, fn (array $a, array $b): int => [$b['count'], $a['category']] <=> [$a['count'], $b['category']]);

        return $summary;
    }

    /**
     * @param  list<array{
     *   category: string,
     *   severity: string,
     *   rationale: string,
     *   recommended_action: string
     * }>  $suggestions
     * @param  list<array{category: string, count: int, rate: float}>  $failureDistribution
     * @param  list<array{
     *   sender: string,
     *   classification: string,
     *   avg_confidence_drop: float,
     *   low_confidence_rate_delta: float,
     *   total_attempts: int
     * }>  $senderHotspots
     * @return list<string>
     */
    private function buildRecommendedActions(array $suggestions, array $failureDistribution, array $senderHotspots, int $days): array
    {
        $actions = [];

        foreach ($suggestions as $suggestion) {
            $actions[] = $suggestion['recommended_action'];
        }

        $topFailure = $failureDistribution[0]['category'] ?? null;
        if ($topFailure === 'insufficient_context') {
            $actions[] = 'Add rule-based fallback for missing dates and unresolved assignees in partial commitments.';
        }

        if ($topFailure === 'ambiguous') {
            $actions[] = 'Tighten ambiguity handling with additional disambiguation examples for vague requests.';
        }

        if ($senderHotspots !== []) {
            $actions[] = 'Fine-tune on sender hotspots with targeted examples from the current batch exports.';
        }

        $actions[] = sprintf('Retrain with last %d days of failures and validated successful extracts.', $days);

        return array_values(array_unique(array_slice($actions, 0, 6)));
    }

    private function resolveReferenceDate(): DateTimeImmutable
    {
        return $this->referenceDate ?? new DateTimeImmutable;
    }
}
