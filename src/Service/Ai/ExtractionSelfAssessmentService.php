<?php

declare(strict_types=1);

namespace Claudriel\Service\Ai;

use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Claudriel\Service\Audit\CommitmentExtractionDriftDetector;
use Claudriel\Service\Audit\CommitmentExtractionFailureClassifier;

final class ExtractionSelfAssessmentService
{
    /** @var array<string, mixed>|null */
    private ?array $lastAssessment = null;

    public function __construct(
        private readonly CommitmentExtractionAuditService $auditService,
        private readonly CommitmentExtractionDriftDetector $driftDetector,
        private readonly CommitmentExtractionFailureClassifier $failureClassifier,
    ) {}

    /**
     * @return array{
     *   window_days: int,
     *   overall_score: int,
     *   drift_summary: array<string, mixed>,
     *   failure_category_distribution: list<array{category: string, count: int, rate: float}>,
     *   top_failure_categories: list<array{category: string, count: int, rate: float}>,
     *   recommended_focus_areas: list<string>,
     *   sender_hotspots: list<array{
     *     sender: string,
     *     classification: string,
     *     avg_confidence_drop: float,
     *     low_confidence_rate_delta: float,
     *     total_attempts: int
     *   }>
     * }
     */
    public function generateAssessment(int $days = 7): array
    {
        $days = max(1, $days);
        $snapshot = $this->auditService->getQualitySnapshot($days);
        $drift = $this->driftDetector->detectDailyDrift(max(14, $days * 2));
        $failureCategories = $snapshot['failure_category_distribution'];
        usort($failureCategories, fn (array $a, array $b): int => [$b['count'], $a['category']] <=> [$a['count'], $b['category']]);
        $topFailureCategories = array_values(array_filter($failureCategories, fn (array $category): bool => $category['count'] > 0));

        $score = $this->calculateOverallScore(
            $snapshot['average_confidence'],
            $snapshot['low_confidence_rate'],
            $drift['classification'],
        );

        $assessment = [
            'window_days' => $days,
            'overall_score' => $score,
            'drift_summary' => $drift,
            'failure_category_distribution' => $snapshot['failure_category_distribution'],
            'top_failure_categories' => $topFailureCategories,
            'recommended_focus_areas' => $this->buildRecommendedFocusAreas($topFailureCategories, $drift['classification']),
            'sender_hotspots' => $this->buildSenderHotspots(max(14, $days * 2)),
        ];

        $this->lastAssessment = $assessment;

        return $assessment;
    }

    public function generateFocusSummary(): string
    {
        /** @var array{
         *   overall_score: int,
         *   drift_summary: array{classification: string},
         *   top_failure_categories: list<array{category: string, count: int, rate: float}>,
         *   recommended_focus_areas: list<string>,
         *   sender_hotspots: list<array{sender: string, classification: string, avg_confidence_drop: float, low_confidence_rate_delta: float, total_attempts: int}>
         * } $assessment
         */
        $assessment = $this->lastAssessment ?? $this->generateAssessment();

        $topCategory = $assessment['top_failure_categories'][0]['category'] ?? 'unknown';
        $topHotspot = $assessment['sender_hotspots'][0]['sender'] ?? null;
        $focusArea = $assessment['recommended_focus_areas'][0] ?? 'Review uncategorized extraction behavior';

        $sentences = [
            sprintf(
                'Claudriel scored %d/100 over the latest assessment window, with drift classified as %s.',
                $assessment['overall_score'],
                $assessment['drift_summary']['classification'],
            ),
            sprintf('The most common recent failure mode is %s, so the current priority is %s.', str_replace('_', ' ', $topCategory), rtrim($focusArea, '.')),
        ];

        if ($topHotspot !== null) {
            $sentences[] = sprintf('Sender drift is most acute for %s, which should be reviewed before the next extraction update.', $topHotspot);
        }

        return implode(' ', array_slice($sentences, 0, 3));
    }

    private function calculateOverallScore(float $averageConfidence, float $lowConfidenceRate, string $driftClassification): int
    {
        $driftStability = match ($driftClassification) {
            'none' => 100,
            'minor' => 70,
            'moderate' => 40,
            'severe' => 10,
            default => 0,
        };

        $score = ($averageConfidence * 100 * 0.4)
            + ((1 - $lowConfidenceRate) * 100 * 0.4)
            + ($driftStability * 0.2);

        return max(0, min(100, (int) round($score)));
    }

    /**
     * @param  list<array{category: string, count: int, rate: float}>  $topFailureCategories
     * @return list<string>
     */
    private function buildRecommendedFocusAreas(array $topFailureCategories, string $driftClassification): array
    {
        $areas = [];
        $modelParseCategory = $this->failureClassifier->classify([], null, 0.0);

        foreach ($topFailureCategories as $category) {
            $areas[] = match ($category['category']) {
                'ambiguous' => 'Improve commitment disambiguation for vague or weakly phrased requests.',
                'insufficient_context' => 'Improve date extraction and person resolution for partial commitments.',
                'non_actionable' => 'Improve action verb detection to separate actionable requests from informational text.',
                $modelParseCategory => 'Improve model response parsing and schema adherence for malformed outputs.',
                default => 'Review unknown failure cases and expand the classifier heuristics.',
            };
        }

        if (in_array($driftClassification, ['moderate', 'severe'], true)) {
            $areas[] = 'Reduce recent confidence drift before shipping the next extraction update.';
        }

        return array_values(array_unique(array_slice($areas, 0, 4)));
    }

    /**
     * @return list<array{
     *   sender: string,
     *   classification: string,
     *   avg_confidence_drop: float,
     *   low_confidence_rate_delta: float,
     *   total_attempts: int
     * }>
     */
    private function buildSenderHotspots(int $days): array
    {
        $hotspots = [];

        foreach ($this->auditService->getTopSenders() as $sender) {
            $drift = $this->driftDetector->detectSenderDrift($sender['sender'], $days);
            $hotspots[] = [
                'sender' => $sender['sender'],
                'classification' => $drift['classification'],
                'avg_confidence_drop' => $drift['delta']['avg_confidence_drop'],
                'low_confidence_rate_delta' => $drift['delta']['low_confidence_rate_delta'],
                'total_attempts' => $sender['total_attempts'],
            ];
        }

        usort($hotspots, function (array $a, array $b): int {
            return [$b['avg_confidence_drop'], $b['low_confidence_rate_delta'], $b['total_attempts'], $a['sender']]
                <=> [$a['avg_confidence_drop'], $a['low_confidence_rate_delta'], $a['total_attempts'], $b['sender']];
        });

        return array_slice($hotspots, 0, 5);
    }
}
