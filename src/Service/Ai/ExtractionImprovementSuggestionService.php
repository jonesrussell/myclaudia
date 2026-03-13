<?php

declare(strict_types=1);

namespace Claudriel\Service\Ai;

use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Claudriel\Service\Audit\CommitmentExtractionDriftDetector;

final class ExtractionImprovementSuggestionService
{
    public function __construct(
        private readonly ExtractionSelfAssessmentService $selfAssessmentService,
        private readonly CommitmentExtractionDriftDetector $driftDetector,
        private readonly CommitmentExtractionAuditService $auditService,
        private readonly TrainingExportService $trainingExportService,
    ) {}

    /**
     * @return array{
     *   window_days: int,
     *   assessment: array<string, mixed>,
     *   drift: array<string, mixed>,
     *   suggestions: list<array{
     *     category: string,
     *     severity: string,
     *     rationale: string,
     *     recommended_action: string
     *   }>
     * }
     */
    public function generateSuggestions(int $days = 14): array
    {
        $days = max(1, $days);
        $assessment = $this->selfAssessmentService->generateAssessment($days);
        $drift = $this->driftDetector->detectDailyDrift(max(14, $days * 2));
        $snapshot = $this->auditService->getQualitySnapshot($days);
        $failureDistribution = $this->indexFailureDistribution($assessment['failure_category_distribution']);
        $failureExport = $this->trainingExportService->exportAllFailures(max(30, $days));
        $suggestions = [];

        $insufficientContextRate = $failureDistribution['insufficient_context']['rate'] ?? 0.0;
        if ($insufficientContextRate > 0.25) {
            $suggestions[] = [
                'category' => 'failure_category',
                'severity' => $insufficientContextRate > 0.4 ? 'high' : 'medium',
                'rationale' => sprintf(
                    'Insufficient-context failures account for %.1f%% of recent low-confidence logs, which suggests Claudriel is missing action owners, dates, or linked entities in partial requests.',
                    $insufficientContextRate * 100,
                ),
                'recommended_action' => 'Improve entity linking and partial-commitment resolution so extracted commitments preserve people, dates, and referenced actions.',
            ];
        }

        if (in_array($drift['classification'], ['moderate', 'severe'], true)) {
            $suggestions[] = [
                'category' => 'drift',
                'severity' => $drift['classification'] === 'severe' ? 'high' : 'medium',
                'rationale' => sprintf(
                    'Recent extraction drift is %s, with average confidence changing by %.1f points and low-confidence rate changing by %.1f points between comparison windows.',
                    $drift['classification'],
                    $drift['delta']['avg_confidence_delta'] * 100,
                    $drift['delta']['low_confidence_rate_delta'] * 100,
                ),
                'recommended_action' => sprintf(
                    'Retrain or recalibrate the extractor using the latest 30 days of failure exports (%d samples) before the next model rollout.',
                    count($failureExport['samples']),
                ),
            ];
        }

        $clusteredHotspots = array_values(array_filter(
            $assessment['sender_hotspots'],
            static fn (array $hotspot): bool => in_array($hotspot['classification'], ['moderate', 'severe'], true),
        ));
        if (count($clusteredHotspots) >= 2) {
            $topSenders = array_map(
                static fn (array $hotspot): string => $hotspot['sender'],
                array_slice($clusteredHotspots, 0, 3),
            );

            $suggestions[] = [
                'category' => 'sender_hotspot',
                'severity' => count($clusteredHotspots) >= 3 ? 'high' : 'medium',
                'rationale' => sprintf(
                    'Sender degradation is clustered across %d hotspots, led by %s.',
                    count($clusteredHotspots),
                    implode(', ', $topSenders),
                ),
                'recommended_action' => 'Create sender-specific evaluation slices and fine-tuning examples for the worst hotspot senders before broader retraining.',
            ];
        }

        if ($snapshot['average_confidence'] < 0.65) {
            $suggestions[] = [
                'category' => 'confidence',
                'severity' => $snapshot['average_confidence'] < 0.55 ? 'high' : 'medium',
                'rationale' => sprintf(
                    'Average extraction confidence is %.2f over the last %d days, below the 0.65 operating target.',
                    $snapshot['average_confidence'],
                    $days,
                ),
                'recommended_action' => 'Adjust extraction heuristics and prompt structure to reduce weak parses before adding more model capacity.',
            ];
        }

        $exportPattern = $this->detectFailureExportPattern($failureExport['samples']);
        if ($exportPattern !== null) {
            $suggestions[] = [
                'category' => 'training_export',
                'severity' => $exportPattern['rate'] > 0.5 ? 'high' : 'medium',
                'rationale' => sprintf(
                    'Failure exports are dominated by %s cases (%.1f%% of %d recent failures), so the retraining set is skewed toward a single remediation theme.',
                    str_replace('_', ' ', $exportPattern['category']),
                    $exportPattern['rate'] * 100,
                    $exportPattern['count'],
                ),
                'recommended_action' => sprintf(
                    'Oversample %s failures in the next training export and attach corrected labels so retraining directly addresses the dominant failure pattern.',
                    str_replace('_', ' ', $exportPattern['category']),
                ),
            ];
        }

        if ($assessment['overall_score'] < 60) {
            $suggestions[] = [
                'category' => 'self_assessment',
                'severity' => $assessment['overall_score'] < 45 ? 'high' : 'medium',
                'rationale' => sprintf(
                    'The current self-assessment score is %d/100, indicating that recent extraction quality is not stable enough for unattended tuning.',
                    $assessment['overall_score'],
                ),
                'recommended_action' => 'Gate autonomous retraining behind a higher self-assessment score and require a focused remediation pass on the top failure categories first.',
            ];
        }

        if ($suggestions === []) {
            $suggestions[] = [
                'category' => 'maintenance',
                'severity' => 'low',
                'rationale' => 'No urgent extraction regressions were detected in the current evaluation window.',
                'recommended_action' => 'Continue monitoring daily drift and refresh the failure export set on the normal retraining cadence.',
            ];
        }

        usort($suggestions, function (array $a, array $b): int {
            $priority = [
                'drift' => 0,
                'sender_hotspot' => 1,
                'failure_category' => 2,
                'confidence' => 3,
                'training_export' => 4,
                'self_assessment' => 5,
                'maintenance' => 6,
            ];

            $severityComparison = $this->severityRank($b['severity']) <=> $this->severityRank($a['severity']);
            if ($severityComparison !== 0) {
                return $severityComparison;
            }

            $priorityComparison = $priority[$a['category']] <=> $priority[$b['category']];
            if ($priorityComparison !== 0) {
                return $priorityComparison;
            }

            return $a['category'] <=> $b['category'];
        });

        return [
            'window_days' => $days,
            'assessment' => $assessment,
            'drift' => $drift,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * @param  list<array{
     *   category: string,
     *   severity: string,
     *   rationale: string,
     *   recommended_action: string
     * }>  $suggestions
     */
    public function summarizeSuggestions(array $suggestions): string
    {
        if ($suggestions === []) {
            return 'No improvement suggestions were generated for the current evaluation window. Extraction quality is stable enough to keep the current monitoring cadence.';
        }

        $sentences = [];
        $sentences[] = sprintf(
            'Claudriel generated %d improvement suggestion%s for the current review window.',
            count($suggestions),
            count($suggestions) === 1 ? '' : 's',
        );

        foreach (array_slice($suggestions, 0, 3) as $suggestion) {
            $sentences[] = sprintf(
                '%s severity %s recommendation: %s',
                ucfirst($suggestion['severity']),
                str_replace('_', ' ', $suggestion['category']),
                rtrim($suggestion['recommended_action'], '.').'.',
            );
        }

        return implode(' ', array_slice($sentences, 0, 4));
    }

    /**
     * @param  list<array{category: string, count: int, rate: float}>  $distribution
     * @return array<string, array{category: string, count: int, rate: float}>
     */
    private function indexFailureDistribution(array $distribution): array
    {
        $indexed = [];

        foreach ($distribution as $category) {
            $indexed[$category['category']] = $category;
        }

        return $indexed;
    }

    /**
     * @param  list<array<string, mixed>>  $samples
     * @return array{category: string, rate: float, count: int}|null
     */
    private function detectFailureExportPattern(array $samples): ?array
    {
        if ($samples === []) {
            return null;
        }

        $counts = [];
        $total = 0;

        foreach ($samples as $sample) {
            $category = (string) ($sample['failure_category'] ?? 'unknown');
            $counts[$category] = ($counts[$category] ?? 0) + 1;
            $total++;
        }

        arsort($counts);
        $category = (string) array_key_first($counts);
        $count = $counts[$category];
        $rate = round($count / $total, 4);

        if ($rate < 0.35) {
            return null;
        }

        return [
            'category' => $category,
            'rate' => $rate,
            'count' => $total,
        ];
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            default => 0,
        };
    }
}
