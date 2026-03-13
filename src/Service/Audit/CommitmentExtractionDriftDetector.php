<?php

declare(strict_types=1);

namespace Claudriel\Service\Audit;

use DateInterval;
use DateTimeImmutable;

final class CommitmentExtractionDriftDetector
{
    public function __construct(
        private readonly CommitmentExtractionAuditService $auditService,
        private readonly ?DateTimeImmutable $referenceDate = null,
    ) {}

    /**
     * @return array{
     *   scope: string,
     *   window_days: int,
     *   current_window: array<string, mixed>,
     *   previous_window: array<string, mixed>,
     *   delta: array{
     *     avg_confidence_delta: float,
     *     avg_confidence_drop: float,
     *     low_confidence_rate_delta: float,
     *     failure_category_distribution_delta: list<array{category: string, current_rate: float, previous_rate: float, delta: float}>
     *   },
     *   classification: string
     * }
     */
    public function detectDailyDrift(int $days = 14): array
    {
        $windowDays = $this->resolveComparisonWindow($days);
        $endDate = $this->resolveReferenceDate();
        $currentWindow = $this->auditService->getQualitySnapshot($windowDays, $endDate);
        $previousWindow = $this->auditService->getQualitySnapshot(
            $windowDays,
            $endDate->sub(new DateInterval(sprintf('P%dD', $windowDays))),
        );
        $delta = $this->buildDelta($currentWindow, $previousWindow);

        return [
            'scope' => 'global',
            'window_days' => $windowDays,
            'current_window' => $currentWindow,
            'previous_window' => $previousWindow,
            'delta' => $delta,
            'classification' => $this->classifyDrift($delta),
        ];
    }

    /**
     * @return array{
     *   scope: string,
     *   sender: string,
     *   window_days: int,
     *   current_window: array<string, mixed>,
     *   previous_window: array<string, mixed>,
     *   delta: array{
     *     avg_confidence_delta: float,
     *     avg_confidence_drop: float,
     *     low_confidence_rate_delta: float,
     *     failure_category_distribution_delta: list<array{category: string, current_rate: float, previous_rate: float, delta: float}>
     *   },
     *   classification: string
     * }
     */
    public function detectSenderDrift(string $email, int $days = 30): array
    {
        $sender = strtolower(trim($email));
        $windowDays = $this->resolveComparisonWindow($days);
        $endDate = $this->resolveReferenceDate();
        $currentWindow = $this->auditService->getQualitySnapshot($windowDays, $endDate, $sender);
        $previousWindow = $this->auditService->getQualitySnapshot(
            $windowDays,
            $endDate->sub(new DateInterval(sprintf('P%dD', $windowDays))),
            $sender,
        );
        $delta = $this->buildDelta($currentWindow, $previousWindow);

        return [
            'scope' => 'sender',
            'sender' => $sender,
            'window_days' => $windowDays,
            'current_window' => $currentWindow,
            'previous_window' => $previousWindow,
            'delta' => $delta,
            'classification' => $this->classifyDrift($delta),
        ];
    }

    /**
     * @param array{
     *   avg_confidence_delta: float,
     *   avg_confidence_drop: float,
     *   low_confidence_rate_delta: float,
     *   failure_category_distribution_delta: list<array{category: string, current_rate: float, previous_rate: float, delta: float}>
     * } $delta
     */
    public function classifyDrift(array $delta): string
    {
        $confidenceDrop = $delta['avg_confidence_drop'];

        return match (true) {
            $confidenceDrop > 0.15 => 'severe',
            $confidenceDrop > 0.08 => 'moderate',
            $confidenceDrop > 0.03 => 'minor',
            default => 'none',
        };
    }

    /**
     * @param  array<string, mixed>  $currentWindow
     * @param  array<string, mixed>  $previousWindow
     * @return array{
     *   avg_confidence_delta: float,
     *   avg_confidence_drop: float,
     *   low_confidence_rate_delta: float,
     *   failure_category_distribution_delta: list<array{category: string, current_rate: float, previous_rate: float, delta: float}>
     * }
     */
    private function buildDelta(array $currentWindow, array $previousWindow): array
    {
        $avgConfidenceDelta = round($currentWindow['average_confidence'] - $previousWindow['average_confidence'], 4);
        $lowConfidenceRateDelta = round($currentWindow['low_confidence_rate'] - $previousWindow['low_confidence_rate'], 4);

        $currentDistribution = [];
        foreach ($currentWindow['failure_category_distribution'] as $category) {
            $currentDistribution[$category['category']] = $category['rate'];
        }

        $previousDistribution = [];
        foreach ($previousWindow['failure_category_distribution'] as $category) {
            $previousDistribution[$category['category']] = $category['rate'];
        }

        $failureCategoryDistributionDelta = [];
        foreach (array_keys($currentWindow['failure_category_counts']) as $category) {
            $currentRate = $currentDistribution[$category] ?? 0.0;
            $previousRate = $previousDistribution[$category] ?? 0.0;
            $failureCategoryDistributionDelta[] = [
                'category' => $category,
                'current_rate' => $currentRate,
                'previous_rate' => $previousRate,
                'delta' => round($currentRate - $previousRate, 4),
            ];
        }

        return [
            'avg_confidence_delta' => $avgConfidenceDelta,
            'avg_confidence_drop' => round(max(0.0, $previousWindow['average_confidence'] - $currentWindow['average_confidence']), 4),
            'low_confidence_rate_delta' => $lowConfidenceRateDelta,
            'failure_category_distribution_delta' => $failureCategoryDistributionDelta,
        ];
    }

    private function resolveComparisonWindow(int $days): int
    {
        return max(1, intdiv(max(2, $days), 2));
    }

    private function resolveReferenceDate(): DateTimeImmutable
    {
        return ($this->referenceDate ?? new DateTimeImmutable('today'))->setTime(0, 0);
    }
}
