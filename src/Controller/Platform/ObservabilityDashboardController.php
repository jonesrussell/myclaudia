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
use Claudriel\Temporal\Agent\OverrunAlertAgent;
use Claudriel\Temporal\Agent\ShiftRiskAgent;
use Claudriel\Temporal\Agent\TemporalAgentContextBuilder;
use Claudriel\Temporal\Agent\TemporalAgentLifecycle;
use Claudriel\Temporal\Agent\TemporalAgentOrchestrator;
use Claudriel\Temporal\Agent\TemporalAgentRegistry;
use Claudriel\Temporal\Agent\TemporalNotificationDeliveryService;
use Claudriel\Temporal\Agent\UpcomingBlockPrepAgent;
use Claudriel\Temporal\Agent\WrapUpPromptAgent;
use Claudriel\Temporal\AtomicTimeService;
use Claudriel\Temporal\Clock\SystemWallClock;
use Claudriel\Temporal\ClockHealthMonitor;
use Claudriel\Temporal\SystemClockSyncProbe;
use Claudriel\Temporal\TemporalAwarenessEngine;
use Claudriel\Temporal\TimeSnapshot;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ObservabilityDashboardController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
        private readonly ?string $projectRoot = null,
        private readonly ?string $batchStorageDirectory = null,
        private readonly ?DateTimeImmutable $referenceDate = null,
        private readonly ?DateTimeImmutable $heartbeatTimestampOverride = null,
    ) {}

    public function index(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
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

    public function jsonView(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
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
        $heartbeat = $this->getHeartbeatTimestamp();
        $heartbeatStatus = $this->resolveHeartbeatStatus($heartbeat);

        return [
            'heartbeat' => $heartbeat,
            'heartbeatBadge' => $heartbeatStatus,
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

    public function getHeartbeatTimestamp(): string
    {
        return ($this->heartbeatTimestampOverride ?? $this->referenceDate ?? new DateTimeImmutable)->format(\DateTimeInterface::ATOM);
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
            'generated_at' => ($this->referenceDate ?? new DateTimeImmutable)->format(\DateTimeInterface::ATOM),
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

        $payload['temporal_tooling'] = $this->buildTemporalToolingSignals();
        $payload['call_chain'] = $this->buildCallChain($payload);
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

    private function resolveHeartbeatStatus(string $heartbeatTimestamp): string
    {
        try {
            $heartbeat = new DateTimeImmutable($heartbeatTimestamp);
        } catch (\Throwable) {
            return 'red';
        }

        $reference = $this->referenceDate ?? new DateTimeImmutable;
        $age = max(0, $reference->getTimestamp() - $heartbeat->getTimestamp());

        return match (true) {
            $age < 600 => 'green',
            $age < 3600 => 'yellow',
            default => 'red',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *   root: array<string, mixed>,
     *   legend: list<array{status: string, label: string}>
     * }
     */
    private function buildCallChain(array $payload): array
    {
        $extractionStatus = $this->mapHealthStatusToCallChain(
            $this->resolveFailureRateStatus((float) $payload['extraction_health']['low_confidence_rate']),
        );
        $driftStatus = $this->mapHealthStatusToCallChain(
            $this->resolveDriftStatus((string) $payload['drift_overview']['classification']),
        );
        $assessmentStatus = $this->mapHealthStatusToCallChain(
            $this->resolveScoreStatus((int) $payload['self_assessment']['overall_score']),
        );
        $governanceStatus = $this->mapHealthStatusToCallChain(
            $this->resolveIntegrityStatus(count($payload['governance_integrity']['issues'])),
        );
        $batchStatus = $payload['model_update_batches'] === [] ? 'fallback' : 'success';
        $trainingStatus = ((int) $payload['training_export_readiness']['daily_sample_count'] > 0 && (int) $payload['training_export_readiness']['failure_sample_count'] > 0)
            ? 'success'
            : 'fallback';
        $suggestionStatus = $this->resolveSuggestionStatus($payload['improvement_suggestions']);
        $rootStatus = in_array('error', [$extractionStatus, $driftStatus, $assessmentStatus, $governanceStatus, $suggestionStatus], true)
            ? 'error'
            : (in_array('retry', [$extractionStatus, $driftStatus, $assessmentStatus, $governanceStatus, $suggestionStatus], true) ? 'retry' : 'success');

        return [
            'root' => [
                'title' => 'Platform observability scan',
                'summary' => sprintf('Window: %d days · generated %s', $payload['window_days'], $payload['generated_at']),
                'status' => $rootStatus,
                'expanded' => true,
                'children' => [
                    [
                        'title' => 'Extraction health',
                        'summary' => sprintf('Confidence %.2f · low-confidence %.1f%%', $payload['extraction_health']['average_confidence'], $payload['extraction_health']['low_confidence_rate'] * 100),
                        'status' => $extractionStatus,
                        'expanded' => true,
                        'children' => [
                            [
                                'title' => 'Failure categories',
                                'summary' => ($payload['extraction_health']['top_failure_categories'][0]['category'] ?? 'none').' leading',
                                'status' => $payload['extraction_health']['top_failure_categories'] === [] ? 'fallback' : 'success',
                                'expanded' => false,
                                'children' => [],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Drift overview',
                        'summary' => sprintf('%s drift · avg delta %.1f%%', ucfirst((string) $payload['drift_overview']['classification']), $payload['drift_overview']['delta']['avg_confidence_delta'] * 100),
                        'status' => $driftStatus,
                        'expanded' => false,
                        'children' => [],
                    ],
                    [
                        'title' => 'Self-assessment',
                        'summary' => sprintf('Score %d/100 · hotspot %s', $payload['self_assessment']['overall_score'], $payload['self_assessment']['sender_hotspots'][0]['sender'] ?? 'none'),
                        'status' => $assessmentStatus,
                        'expanded' => false,
                        'children' => [],
                    ],
                    [
                        'title' => 'Improvement suggestions',
                        'summary' => sprintf('%d suggestion%s ready', count($payload['improvement_suggestions']), count($payload['improvement_suggestions']) === 1 ? '' : 's'),
                        'status' => $suggestionStatus,
                        'expanded' => false,
                        'children' => [],
                    ],
                    [
                        'title' => 'Training export readiness',
                        'summary' => sprintf('%d daily samples · %d failure samples', $payload['training_export_readiness']['daily_sample_count'], $payload['training_export_readiness']['failure_sample_count']),
                        'status' => $trainingStatus,
                        'expanded' => false,
                        'children' => [],
                    ],
                    [
                        'title' => 'Governance integrity',
                        'summary' => sprintf('%d issue%s detected', count($payload['governance_integrity']['issues']), count($payload['governance_integrity']['issues']) === 1 ? '' : 's'),
                        'status' => $governanceStatus,
                        'expanded' => false,
                        'children' => [],
                    ],
                    [
                        'title' => 'Model update batches',
                        'summary' => $payload['model_update_batches'] === []
                            ? 'No stored batches available'
                            : sprintf('%d stored batch%s', count($payload['model_update_batches']), count($payload['model_update_batches']) === 1 ? '' : 'es'),
                        'status' => $batchStatus,
                        'expanded' => false,
                        'children' => [],
                    ],
                    [
                        'title' => 'Temporal + tooling execution',
                        'summary' => $payload['temporal_tooling']['summary'],
                        'status' => $payload['temporal_tooling']['status'],
                        'expanded' => true,
                        'children' => $payload['temporal_tooling']['children'],
                    ],
                ],
            ],
            'legend' => [
                ['status' => 'success', 'label' => 'Success'],
                ['status' => 'fallback', 'label' => 'Fallback'],
                ['status' => 'retry', 'label' => 'Retry'],
                ['status' => 'error', 'label' => 'Error'],
            ],
        ];
    }

    private function mapHealthStatusToCallChain(string $status): string
    {
        return match ($status) {
            'green' => 'success',
            'yellow' => 'retry',
            default => 'error',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $suggestions
     */
    private function resolveSuggestionStatus(array $suggestions): string
    {
        if ($suggestions === []) {
            return 'fallback';
        }

        $severities = array_map(
            static fn (array $suggestion): string => (string) ($suggestion['severity'] ?? 'info'),
            $suggestions,
        );

        if (array_intersect($severities, ['high', 'critical']) !== []) {
            return 'error';
        }

        if (array_intersect($severities, ['medium', 'warning']) !== []) {
            return 'retry';
        }

        return 'success';
    }

    /**
     * @return array{
     *   summary: string,
     *   status: string,
     *   snapshot: array<string, int|string>,
     *   clock_health: array<string, mixed>,
     *   awareness: array<string, mixed>,
     *   children: list<array<string, mixed>>
     * }
     */
    private function buildTemporalToolingSignals(): array
    {
        $snapshot = $this->temporalSnapshot();
        $clockHealth = (new ClockHealthMonitor(
            new AtomicTimeService,
            new SystemClockSyncProbe,
            new SystemWallClock,
        ))->assess('system-wall-clock');
        $schedule = $this->loadTodaySchedule($snapshot->local());
        $awareness = (new TemporalAwarenessEngine)->analyze($schedule, $snapshot);
        $currentBlock = is_array($awareness['current_block']) ? $awareness['current_block'] : null;
        $nextBlock = is_array($awareness['next_block']) ? $awareness['next_block'] : null;

        $toolingChildren = [
            [
                'title' => 'Brief stream fallback transport',
                'summary' => sprintf('Fallback payload channel available with %d second retry pacing', 30),
                'status' => 'fallback',
                'expanded' => false,
                'children' => [],
            ],
            [
                'title' => 'Chat stream progress transport',
                'summary' => 'SSE progress channel emits retry cadence and structured progress events',
                'status' => 'retry',
                'expanded' => false,
                'children' => [],
            ],
        ];

        $recentOperation = $this->loadRecentOperationSummary();
        if ($recentOperation !== null) {
            $toolingChildren[] = [
                'title' => 'Recent workspace operation',
                'summary' => $recentOperation['summary'],
                'status' => $recentOperation['status'],
                'expanded' => false,
                'children' => [],
            ];
        }

        $children = [
            [
                'title' => 'Atomic time snapshot',
                'summary' => sprintf('Local %s · UTC %s · monotonic %s', $snapshot->local()->format('g:i A T'), $snapshot->utc()->format('H:i:s \\U\\T\\C'), number_format($snapshot->monotonicNanoseconds())),
                'status' => $clockHealth['safe_for_temporal_reasoning'] ? 'success' : 'fallback',
                'expanded' => false,
                'children' => [],
            ],
            [
                'title' => 'Clock health + drift monitor',
                'summary' => sprintf('%s via %s · drift %.1fs · fallback %s', $clockHealth['state'], $clockHealth['provider'], $clockHealth['drift_seconds'], $clockHealth['fallback_mode']),
                'status' => $clockHealth['safe_for_temporal_reasoning'] ? 'success' : 'fallback',
                'expanded' => false,
                'children' => [],
            ],
            [
                'title' => 'Snapshot injection',
                'summary' => 'Brief, chat, and schedule flows consume a stable per-interaction now() payload',
                'status' => 'success',
                'expanded' => false,
                'children' => [],
            ],
            [
                'title' => 'Temporal reasoning stages',
                'summary' => sprintf(
                    'Current %s · next %s · %d gap%s · %d overrun%s',
                    $currentBlock['title'] ?? 'none',
                    $nextBlock['title'] ?? 'none',
                    count($awareness['gaps']),
                    count($awareness['gaps']) === 1 ? '' : 's',
                    count($awareness['overruns']),
                    count($awareness['overruns']) === 1 ? '' : 's',
                ),
                'status' => $schedule === []
                    ? 'fallback'
                    : (count($awareness['overruns']) > 0 ? 'retry' : 'success'),
                'expanded' => true,
                'children' => [
                    [
                        'title' => 'Current block resolution',
                        'summary' => $currentBlock['title'] ?? 'No active block',
                        'status' => $currentBlock !== null ? 'success' : 'fallback',
                        'expanded' => false,
                        'children' => [],
                    ],
                    [
                        'title' => 'Next block resolution',
                        'summary' => $nextBlock['title'] ?? 'No upcoming block',
                        'status' => $nextBlock !== null ? 'success' : 'fallback',
                        'expanded' => false,
                        'children' => [],
                    ],
                    [
                        'title' => 'Gap detection',
                        'summary' => count($awareness['gaps']) === 0 ? 'No active gaps detected' : sprintf('%d scheduling gap%s detected', count($awareness['gaps']), count($awareness['gaps']) === 1 ? '' : 's'),
                        'status' => count($awareness['gaps']) === 0 ? 'success' : 'retry',
                        'expanded' => false,
                        'children' => [],
                    ],
                    [
                        'title' => 'Overrun detection',
                        'summary' => count($awareness['overruns']) === 0 ? 'No overruns detected' : sprintf('%d overrun%s detected', count($awareness['overruns']), count($awareness['overruns']) === 1 ? '' : 's'),
                        'status' => count($awareness['overruns']) === 0 ? 'success' : 'retry',
                        'expanded' => false,
                        'children' => [],
                    ],
                ],
            ],
            $this->buildTemporalAgentCallChainNode(
                $this->buildTemporalAgentObservability($snapshot, $clockHealth, $schedule, $awareness),
            ),
            [
                'title' => 'Tooling transport signals',
                'summary' => 'Fallback, retry, and operation signals are wired into the panel',
                'status' => $recentOperation !== null && $recentOperation['status'] === 'error' ? 'error' : 'retry',
                'expanded' => false,
                'children' => $toolingChildren,
            ],
        ];

        $status = 'success';
        foreach ($children as $child) {
            if ($child['status'] === 'error') {
                $status = 'error';
                break;
            }
            if (in_array($child['status'], ['retry', 'fallback'], true)) {
                $status = $status === 'success' ? $child['status'] : $status;
            }
        }

        return [
            'summary' => sprintf('Snapshot %s · clock %s · %d schedule block%s', $snapshot->local()->format('g:i A T'), $clockHealth['state'], count($schedule), count($schedule) === 1 ? '' : 's'),
            'status' => $status,
            'snapshot' => $snapshot->toArray(),
            'clock_health' => $clockHealth,
            'awareness' => $awareness,
            'children' => $children,
        ];
    }

    private function temporalSnapshot(): TimeSnapshot
    {
        if ($this->referenceDate instanceof DateTimeImmutable) {
            $utc = $this->referenceDate->setTimezone(new \DateTimeZone('UTC'));

            return new TimeSnapshot(
                $utc,
                $this->referenceDate,
                0,
                $this->referenceDate->getTimezone()->getName(),
            );
        }

        return (new AtomicTimeService)->now();
    }

    /**
     * @param  array{
     *   summary: string,
     *   status: string,
     *   children: list<array<string, mixed>>
     * }  $agentObservability
     * @return array<string, mixed>
     */
    private function buildTemporalAgentCallChainNode(array $agentObservability): array
    {
        return [
            'title' => 'Proactive agent execution',
            'summary' => $agentObservability['summary'],
            'status' => $agentObservability['status'],
            'expanded' => true,
            'children' => $agentObservability['children'],
        ];
    }

    /**
     * @param  array{
     *   provider: string,
     *   synchronized: bool,
     *   reference_source: string,
     *   drift_seconds: float,
     *   threshold_seconds: int,
     *   state: string,
     *   safe_for_temporal_reasoning: bool,
     *   retry_after_seconds: int,
     *   fallback_mode: string,
     *   metadata: array<string, scalar|null>
     * }  $clockHealth
     * @param  list<array{title: string, start_time: string, end_time: string, source: string}>  $schedule
     * @param  array{
     *   current_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *   next_block: ?array{title: string, start_time: string, end_time: string, source: string},
     *   gaps: list<array{starts_at: string, ends_at: string, duration_minutes: int, between: array{from: string, to: string}}>,
     *   overruns: list<array{title: string, ended_at: string, overrun_minutes: int}>
     * }  $awareness
     * @return array{
     *   summary: string,
     *   status: string,
     *   children: list<array<string, mixed>>
     * }
     */
    private function buildTemporalAgentObservability(
        TimeSnapshot $snapshot,
        array $clockHealth,
        array $schedule,
        array $awareness,
    ): array {
        try {
            $context = (new TemporalAgentContextBuilder)->build(
                tenantId: 'platform-observability',
                workspaceUuid: null,
                snapshot: $snapshot,
                clockHealth: $clockHealth,
                schedule: $schedule,
                temporalAwareness: $awareness,
                timezoneContext: [
                    'timezone' => $snapshot->timezone(),
                    'source' => 'time_snapshot',
                ],
            );

            $batch = (new TemporalAgentOrchestrator($this->temporalAgentRegistry()))->evaluate($context);
            $notificationsByDeliveryKey = $this->loadTemporalNotificationsByDeliveryKey();
            $children = array_map(
                fn (array $decision): array => $this->buildTemporalAgentDecisionNode(
                    $decision,
                    $notificationsByDeliveryKey[$decision['suppression']['key']] ?? [],
                ),
                $batch->toArray()['decisions'],
            );

            return [
                'summary' => sprintf(
                    '%d emitted · %d suppressed across %d agents',
                    $batch->toArray()['emitted_count'],
                    $batch->toArray()['suppressed_count'],
                    count($batch->toArray()['decisions']),
                ),
                'status' => $this->aggregateChildStatuses($children),
                'children' => $children,
            ];
        } catch (\Throwable $exception) {
            return [
                'summary' => 'Temporal agent observability failed during evaluation replay',
                'status' => 'error',
                'children' => [[
                    'title' => 'Evaluation replay failure',
                    'summary' => $exception->getMessage(),
                    'status' => 'error',
                    'expanded' => false,
                    'children' => [],
                ]],
            ];
        }
    }

    private function temporalAgentRegistry(): TemporalAgentRegistry
    {
        return new TemporalAgentRegistry([
            new OverrunAlertAgent,
            new ShiftRiskAgent,
            new WrapUpPromptAgent,
            new UpcomingBlockPrepAgent,
        ]);
    }

    /**
     * @param  array{
     *   agent: string,
     *   state: string,
     *   kind: string,
     *   title: string,
     *   summary: string,
     *   reason_code: string,
     *   actions: list<array{type: string, label: string, payload: array<string, scalar|array<array-key, scalar>|null>}>,
     *   metadata: array<string, scalar|array<array-key, scalar>|null>,
     *   suppression: array{key: string, window_starts_at: string, window_seconds: int}
     * }  $decision
     * @param  list<array<string, mixed>>  $notifications
     * @return array<string, mixed>
     */
    private function buildTemporalAgentDecisionNode(array $decision, array $notifications): array
    {
        $decisionStatus = $this->decisionStatus($decision);
        $children = [[
            'title' => 'Decision envelope',
            'summary' => sprintf(
                '%s · reason %s · %d action%s',
                $decision['kind'],
                $decision['reason_code'],
                count($decision['actions']),
                count($decision['actions']) === 1 ? '' : 's',
            ),
            'status' => $decisionStatus,
            'expanded' => false,
            'children' => [],
        ]];

        if ($notifications === []) {
            $children[] = [
                'title' => 'Notification delivery',
                'summary' => $decision['state'] === TemporalAgentLifecycle::EMITTED
                    ? 'No persisted notification matched the emitted decision'
                    : 'Delivery skipped because the decision was suppressed',
                'status' => $decision['state'] === TemporalAgentLifecycle::EMITTED ? 'retry' : 'fallback',
                'expanded' => false,
                'children' => [],
            ];
        } else {
            foreach ($notifications as $notification) {
                $children[] = $this->buildTemporalNotificationNode($notification);
            }
        }

        return [
            'title' => $decision['agent'],
            'summary' => sprintf('%s · %s', $decision['state'], $decision['summary']),
            'status' => $this->combineStatusWithChildren($decisionStatus, $children),
            'expanded' => true,
            'children' => $children,
        ];
    }

    /**
     * @param  array<string, mixed>  $notification
     * @return array<string, mixed>
     */
    private function buildTemporalNotificationNode(array $notification): array
    {
        $actions = is_array($notification['actions'] ?? null) ? $notification['actions'] : [];
        $actionStates = is_array($notification['action_states'] ?? null) ? $notification['action_states'] : [];
        $children = [];

        foreach ($actions as $action) {
            $type = is_string($action['type'] ?? null) ? $action['type'] : 'unknown';
            $state = is_string($actionStates[$type] ?? null) ? $actionStates[$type] : 'idle';
            $children[] = [
                'title' => sprintf('Action: %s', $type),
                'summary' => sprintf('%s · state %s', (string) ($action['label'] ?? $type), $state),
                'status' => $this->actionStatus($state),
                'expanded' => false,
                'children' => [],
            ];
        }

        $status = $this->combineStatusWithChildren(
            $this->notificationStatus((string) ($notification['state'] ?? 'unknown')),
            $children,
        );

        return [
            'title' => 'Notification delivery',
            'summary' => sprintf(
                '%s · delivered %s',
                (string) ($notification['state'] ?? 'unknown'),
                (string) ($notification['delivered_at'] ?? 'unknown'),
            ),
            'status' => $status,
            'expanded' => $children !== [],
            'children' => $children,
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function loadTemporalNotificationsByDeliveryKey(): array
    {
        try {
            $storage = $this->entityTypeManager->getStorage('temporal_notification');
            $notifications = $storage->loadMultiple($storage->getQuery()->execute());
        } catch (\Throwable) {
            return [];
        }

        $grouped = [];

        foreach ($notifications as $notification) {
            if (! method_exists($notification, 'get')) {
                continue;
            }

            $deliveryKey = $notification->get('delivery_key');
            if (! is_string($deliveryKey) || $deliveryKey === '') {
                continue;
            }

            $grouped[$deliveryKey][] = [
                'uuid' => $notification->get('uuid'),
                'state' => $notification->get('state'),
                'actions' => $notification->get('actions'),
                'action_states' => $notification->get('action_states'),
                'delivered_at' => $notification->get('delivered_at'),
            ];
        }

        foreach ($grouped as &$notificationsForDecision) {
            usort($notificationsForDecision, static fn (array $left, array $right): int => strcmp(
                (string) ($right['delivered_at'] ?? ''),
                (string) ($left['delivered_at'] ?? ''),
            ));
        }

        unset($notificationsForDecision);

        return $grouped;
    }

    /**
     * @param  array{
     *   state: string,
     *   reason_code: string
     * }  $decision
     */
    private function decisionStatus(array $decision): string
    {
        if ($decision['state'] === TemporalAgentLifecycle::EMITTED) {
            return 'success';
        }

        return match ($decision['reason_code']) {
            'duplicate_within_window' => 'retry',
            default => 'fallback',
        };
    }

    private function notificationStatus(string $state): string
    {
        return match ($state) {
            TemporalNotificationDeliveryService::STATE_ACTIVE => 'success',
            TemporalNotificationDeliveryService::STATE_SNOOZED => 'retry',
            TemporalNotificationDeliveryService::STATE_DISMISSED,
            TemporalNotificationDeliveryService::STATE_EXPIRED => 'fallback',
            default => 'error',
        };
    }

    private function actionStatus(string $state): string
    {
        return match ($state) {
            'complete' => 'success',
            'working' => 'retry',
            'idle' => 'fallback',
            'failed' => 'error',
            default => 'error',
        };
    }

    /**
     * @param  list<array<string, mixed>>  $children
     */
    private function aggregateChildStatuses(array $children): string
    {
        $status = 'success';

        foreach ($children as $child) {
            if (($child['status'] ?? null) === 'error') {
                return 'error';
            }

            if (($child['status'] ?? null) === 'retry') {
                $status = 'retry';

                continue;
            }

            if (($child['status'] ?? null) === 'fallback' && $status === 'success') {
                $status = 'fallback';
            }
        }

        return $status;
    }

    /**
     * @param  list<array<string, mixed>>  $children
     */
    private function combineStatusWithChildren(string $baseStatus, array $children): string
    {
        if ($baseStatus === 'error') {
            return 'error';
        }

        $status = $baseStatus;

        foreach ($children as $child) {
            if (($child['status'] ?? null) === 'error') {
                return 'error';
            }

            if (($child['status'] ?? null) === 'retry') {
                $status = 'retry';

                continue;
            }

            if (($child['status'] ?? null) === 'fallback' && $status === 'success') {
                $status = 'fallback';
            }
        }

        return $status;
    }

    /**
     * @return list<array{title: string, start_time: string, end_time: string, source: string}>
     */
    private function loadTodaySchedule(DateTimeImmutable $localNow): array
    {
        $schedule = [];

        try {
            foreach ($this->entityTypeManager->getStorage('schedule_entry')->loadMultiple($this->entityTypeManager->getStorage('schedule_entry')->getQuery()->execute()) as $entry) {
                $start = $this->getEntityField($entry, 'starts_at');
                $end = $this->getEntityField($entry, 'ends_at');
                if (! is_string($start) || ! is_string($end) || $start === '' || $end === '') {
                    continue;
                }

                try {
                    $startAt = new DateTimeImmutable($start);
                } catch (\Throwable) {
                    continue;
                }

                if ($startAt->setTimezone($localNow->getTimezone())->format('Y-m-d') !== $localNow->format('Y-m-d')) {
                    continue;
                }

                $schedule[] = [
                    'title' => (string) ($this->getEntityField($entry, 'title') ?? 'Untitled block'),
                    'start_time' => $start,
                    'end_time' => $end,
                    'source' => (string) ($this->getEntityField($entry, 'source') ?? 'manual'),
                ];
            }
        } catch (\Throwable) {
        }

        usort($schedule, static fn (array $left, array $right): int => strcmp($left['start_time'], $right['start_time']));

        return $schedule;
    }

    /**
     * @return ?array{summary: string, status: string}
     */
    private function loadRecentOperationSummary(): ?array
    {
        try {
            $storage = $this->entityTypeManager->getStorage('operation');
            $operations = $storage->loadMultiple($storage->getQuery()->execute());
        } catch (\Throwable) {
            return null;
        }

        if ($operations === []) {
            return null;
        }

        usort($operations, fn ($left, $right): int => strcmp((string) ($this->getEntityField($right, 'created_at') ?? ''), (string) ($this->getEntityField($left, 'created_at') ?? '')));
        $operation = $operations[0];
        $status = (string) ($this->getEntityField($operation, 'status') ?? 'pending');

        return [
            'summary' => sprintf('%s · commit %s', $status, (string) ($this->getEntityField($operation, 'commit_hash') ?? 'n/a')),
            'status' => $status === 'complete' ? 'success' : ($status === 'failed' ? 'error' : 'retry'),
        ];
    }

    private function getEntityField(mixed $entity, string $field): mixed
    {
        if (is_object($entity) && method_exists($entity, 'get')) {
            return $entity->get($field);
        }

        return null;
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
