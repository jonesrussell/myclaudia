<?php

declare(strict_types=1);

namespace Claudriel\Controller\Platform;

use Claudriel\Service\Ai\ExtractionImprovementSuggestionService;
use Claudriel\Service\Ai\ExtractionSelfAssessmentService;
use Claudriel\Service\Ai\ModelUpdateBatchGenerator;
use Claudriel\Service\Ai\TrainingExportService;
use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Claudriel\Service\Audit\CommitmentExtractionDriftDetector;
use Claudriel\Service\Audit\CommitmentExtractionFailureClassifier;
use Claudriel\Service\Governance\CodifiedContextIntegrityScanner;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ObservabilityDashboardController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
        private readonly ?string $projectRoot = null,
        private readonly ?string $batchStorageDirectory = null,
    ) {}

    public function index(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $payload = $this->buildPayload($query, $httpRequest);

        if ($this->twig !== null) {
            $html = $this->twig->render('platform/observability/index.twig', $payload);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return $this->json($payload);
    }

    public function jsonView(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        return $this->json($this->buildPayload($query, $httpRequest));
    }

    /**
     * @return array{
     *   items: list<array{label: string, value: string, status: string, badge: string}>
     * }
     */
    public function getStatusBarData(): array
    {
        $services = $this->buildServices();
        $snapshot = $services['audit']->getQualitySnapshot(14);
        $assessment = $services['self_assessment']->generateAssessment(14);
        $drift = $services['drift']->detectDailyDrift(14);
        $integrityScan = $services['integrity']->scan();
        $batches = $services['batch_generator']->listStoredBatches(1);
        $lastBatch = is_string($batches[0]['generated_at'] ?? null) ? $batches[0]['generated_at'] : 'No batches';

        $scoreStatus = $this->resolveScoreStatus($assessment['overall_score']);
        $driftStatus = $this->resolveDriftStatus($drift['classification']);
        $failureRateStatus = $this->resolveFailureRateStatus($snapshot['low_confidence_rate']);
        $integrityStatus = $this->resolveIntegrityStatus(count($integrityScan['issues']));
        $batchStatus = $batches === [] ? 'yellow' : 'green';

        return [
            'items' => [
                [
                    'label' => 'Extraction Health',
                    'value' => sprintf('%d/100', $assessment['overall_score']),
                    'status' => $scoreStatus,
                    'badge' => $scoreStatus,
                ],
                [
                    'label' => 'Drift',
                    'value' => ucfirst($drift['classification']),
                    'status' => $driftStatus,
                    'badge' => $driftStatus,
                ],
                [
                    'label' => 'Failure Rate',
                    'value' => sprintf('%.1f%%', $snapshot['low_confidence_rate'] * 100),
                    'status' => $failureRateStatus,
                    'badge' => $failureRateStatus,
                ],
                [
                    'label' => 'Integrity',
                    'value' => count($integrityScan['issues']) === 0 ? 'Healthy' : sprintf('%d issues', count($integrityScan['issues'])),
                    'status' => $integrityStatus,
                    'badge' => $integrityStatus,
                ],
                [
                    'label' => 'Last Model Batch',
                    'value' => $lastBatch,
                    'status' => $batchStatus,
                    'badge' => $batchStatus,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(array $query = [], ?Request $httpRequest = null): array
    {
        $days = max(1, (int) ($query['days'] ?? $httpRequest?->query->get('days', 14) ?? 14));
        $services = $this->buildServices();

        $qualitySnapshot = $services['audit']->getQualitySnapshot($days);
        $drift = $services['drift']->detectDailyDrift(max(14, $days * 2));
        $assessment = $services['self_assessment']->generateAssessment($days);
        $suggestionReport = $services['improvement']->generateSuggestions($days);
        $dailyExport = $services['training_export']->exportDailySamples($days);
        $failureExport = $services['training_export']->exportAllFailures(max(30, $days));
        $integrityScan = $services['integrity']->scan();
        $integrityClassifications = $services['integrity']->classifyIssues($integrityScan['issues']);
        $recentBatches = $services['batch_generator']->listStoredBatches(10);

        $payload = [
            'generated_at' => (new DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            'window_days' => $days,
            'statusBarData' => $this->getStatusBarData(),
            'extraction_health' => [
                'average_confidence' => $qualitySnapshot['average_confidence'],
                'low_confidence_rate' => $qualitySnapshot['low_confidence_rate'],
                'summary_metrics' => $services['audit']->getSummaryMetrics(),
                'top_failure_categories' => array_slice(
                    array_values(array_filter(
                        $qualitySnapshot['failure_category_distribution'],
                        static fn (array $category): bool => $category['count'] > 0,
                    )),
                    0,
                    3,
                ),
            ],
            'drift_overview' => $drift,
            'self_assessment' => $assessment,
            'improvement_suggestions' => $suggestionReport['suggestions'],
            'training_export_readiness' => [
                'daily_sample_count' => $this->countDailySamples($dailyExport),
                'failure_sample_count' => count($failureExport['samples']),
                'failure_distribution' => $qualitySnapshot['failure_category_distribution'],
                'daily_export' => $dailyExport,
                'failure_export' => $failureExport,
            ],
            'governance_integrity' => [
                'generated_at' => $integrityScan['generated_at'],
                'issues' => $integrityScan['issues'],
                'classifications' => $integrityClassifications,
                'summary' => $services['integrity']->summarize($integrityScan['issues']),
            ],
            'model_update_batches' => $recentBatches,
        ];

        $payload['system_summary'] = $this->buildSystemSummary($payload);

        return $payload;
    }

    /**
     * @return array{
     *   audit: CommitmentExtractionAuditService,
     *   drift: CommitmentExtractionDriftDetector,
     *   self_assessment: ExtractionSelfAssessmentService,
     *   improvement: ExtractionImprovementSuggestionService,
     *   training_export: TrainingExportService,
     *   integrity: CodifiedContextIntegrityScanner,
     *   batch_generator: ModelUpdateBatchGenerator
     * }
     */
    private function buildServices(): array
    {
        $auditService = new CommitmentExtractionAuditService($this->entityTypeManager);
        $driftDetector = new CommitmentExtractionDriftDetector($auditService);
        $failureClassifier = new CommitmentExtractionFailureClassifier;
        $selfAssessment = new ExtractionSelfAssessmentService($auditService, $driftDetector, $failureClassifier);
        $trainingExport = new TrainingExportService($this->entityTypeManager);
        $improvement = new ExtractionImprovementSuggestionService($selfAssessment, $driftDetector, $auditService, $trainingExport);

        return [
            'audit' => $auditService,
            'drift' => $driftDetector,
            'self_assessment' => $selfAssessment,
            'improvement' => $improvement,
            'training_export' => $trainingExport,
            'integrity' => new CodifiedContextIntegrityScanner($this->projectRoot ?? __DIR__.'/../../..'),
            'batch_generator' => new ModelUpdateBatchGenerator(
                $trainingExport,
                $auditService,
                $driftDetector,
                $selfAssessment,
                $improvement,
                $this->batchStorageDirectory ?? __DIR__.'/../../../var/model-updates',
            ),
        ];
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
     * @param  array<string, mixed>  $payload
     */
    private function buildSystemSummary(array $payload): string
    {
        $topFailure = $payload['extraction_health']['top_failure_categories'][0]['category'] ?? 'none';
        $topHotspot = $payload['self_assessment']['sender_hotspots'][0]['sender'] ?? 'no hotspot';
        $topSuggestion = $payload['improvement_suggestions'][0]['recommended_action'] ?? 'Continue routine monitoring.';

        return sprintf(
            'Extraction confidence is %.2f with a %.1f%% low-confidence rate over the latest %d-day window. Drift is %s, the self-assessment score is %d/100, and the leading failure category is %s. Governance currently reports %d issue%s, recent hotspot pressure is centered on %s, and the next recommended action is %s. %d stored model update batch%s are available for retraining readiness review.',
            $payload['extraction_health']['average_confidence'],
            $payload['extraction_health']['low_confidence_rate'] * 100,
            $payload['window_days'],
            $payload['drift_overview']['classification'],
            $payload['self_assessment']['overall_score'],
            str_replace('_', ' ', $topFailure),
            count($payload['governance_integrity']['issues']),
            count($payload['governance_integrity']['issues']) === 1 ? '' : 's',
            $topHotspot,
            rtrim($topSuggestion, '.').'.',
            count($payload['model_update_batches']),
            count($payload['model_update_batches']) === 1 ? '' : 'es',
        );
    }

    private function resolveScoreStatus(int $score): string
    {
        return match (true) {
            $score >= 75 => 'green',
            $score >= 55 => 'yellow',
            default => 'red',
        };
    }

    private function resolveDriftStatus(string $classification): string
    {
        return match ($classification) {
            'none' => 'green',
            'minor' => 'yellow',
            default => 'red',
        };
    }

    private function resolveFailureRateStatus(float $rate): string
    {
        return match (true) {
            $rate < 0.2 => 'green',
            $rate < 0.4 => 'yellow',
            default => 'red',
        };
    }

    private function resolveIntegrityStatus(int $issues): string
    {
        return match (true) {
            $issues === 0 => 'green',
            $issues <= 2 => 'yellow',
            default => 'red',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
