<?php

declare(strict_types=1);

namespace Claudriel\Controller\Ai;

use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Service\Ai\ExtractionSelfAssessmentService;
use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Claudriel\Service\Audit\CommitmentExtractionDriftDetector;
use Claudriel\Service\Audit\CommitmentExtractionFailureClassifier;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ExtractionSelfAssessmentController
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
            $html = $this->twig->render('ai/self-assessment/index.twig', $payload);

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
     * @return array<string, mixed>
     */
    private function buildPayload(array $query = [], ?Request $httpRequest = null): array
    {
        $days = max(1, (int) ($query['days'] ?? $httpRequest?->query->get('days', 7) ?? 7));
        $service = $this->buildService();
        $assessment = $service->generateAssessment($days);

        return [
            'assessment' => $assessment,
            'focus_summary' => $service->generateFocusSummary(),
            'statusBarData' => $this->getStatusBarData(),
        ];
    }

    private function buildService(): ExtractionSelfAssessmentService
    {
        $auditService = new CommitmentExtractionAuditService($this->entityTypeManager);
        $driftDetector = new CommitmentExtractionDriftDetector($auditService);

        return new ExtractionSelfAssessmentService(
            $auditService,
            $driftDetector,
            new CommitmentExtractionFailureClassifier,
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
