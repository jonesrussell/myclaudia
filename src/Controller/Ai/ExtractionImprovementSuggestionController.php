<?php

declare(strict_types=1);

namespace Claudriel\Controller\Ai;

use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Service\Ai\ExtractionImprovementSuggestionService;
use Claudriel\Service\Ai\ExtractionSelfAssessmentService;
use Claudriel\Service\Ai\TrainingExportService;
use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Claudriel\Service\Audit\CommitmentExtractionDriftDetector;
use Claudriel\Service\Audit\CommitmentExtractionFailureClassifier;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ExtractionImprovementSuggestionController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
        private readonly ?string $projectRoot = null,
        private readonly ?string $batchStorageDirectory = null,
    ) {}

    public function index(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $payload = $this->buildPayload($query, $httpRequest);

        if ($this->twig !== null) {
            $html = $this->twig->render('ai/improvement-suggestions/index.twig', $payload);

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
     * @return array<string, mixed>
     */
    private function buildPayload(array $query = [], ?Request $httpRequest = null): array
    {
        $days = max(1, (int) ($query['days'] ?? $httpRequest?->query->get('days', 14) ?? 14));
        $service = $this->buildService();
        $report = $service->generateSuggestions($days);

        return [
            'report' => $report,
            'summary' => $service->summarizeSuggestions($report['suggestions']),
            'statusBarData' => $this->getStatusBarData(),
        ];
    }

    private function buildService(): ExtractionImprovementSuggestionService
    {
        $auditService = new CommitmentExtractionAuditService($this->entityTypeManager);
        $driftDetector = new CommitmentExtractionDriftDetector($auditService);
        $failureClassifier = new CommitmentExtractionFailureClassifier;

        return new ExtractionImprovementSuggestionService(
            new ExtractionSelfAssessmentService($auditService, $driftDetector, $failureClassifier),
            $driftDetector,
            $auditService,
            new TrainingExportService($this->entityTypeManager),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function json(array $payload, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($payload, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function getStatusBarData(): array
    {
        return (new ObservabilityDashboardController(
            $this->entityTypeManager,
            null,
            $this->projectRoot,
            $this->batchStorageDirectory,
        ))->getStatusBarData();
    }
}
