<?php

declare(strict_types=1);

namespace Claudriel\Controller\Ai;

use Claudriel\Service\Ai\ExtractionImprovementSuggestionService;
use Claudriel\Service\Ai\ExtractionSelfAssessmentService;
use Claudriel\Service\Ai\ModelUpdateBatchGenerator;
use Claudriel\Service\Ai\TrainingExportService;
use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Claudriel\Service\Audit\CommitmentExtractionDriftDetector;
use Claudriel\Service\Audit\CommitmentExtractionFailureClassifier;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class ModelUpdateBatchController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?string $storageDirectory = null,
    ) {}

    public function create(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $days = max(1, (int) ($query['days'] ?? $httpRequest->request->get('days', $httpRequest->query->get('days', 14)) ?? 14));
        $service = $this->buildService();
        $batch = $service->generateBatch($days);
        $service->saveBatch($batch);

        return $this->json([
            'batch_id' => $batch['batch_id'],
            'path' => $service->getBatchPath($batch['batch_id']),
            'metadata' => $batch['metadata'],
        ]);
    }

    public function show(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $batchId = rawurldecode((string) ($params['batchId'] ?? ''));
        $batch = $this->buildService()->loadBatch($batchId);

        if ($batch === null) {
            return $this->json(['error' => 'Batch not found.'], 404);
        }

        return $this->json($batch);
    }

    private function buildService(): ModelUpdateBatchGenerator
    {
        $auditService = new CommitmentExtractionAuditService($this->entityTypeManager);
        $driftDetector = new CommitmentExtractionDriftDetector($auditService);
        $failureClassifier = new CommitmentExtractionFailureClassifier;
        $selfAssessment = new ExtractionSelfAssessmentService($auditService, $driftDetector, $failureClassifier);
        $trainingExport = new TrainingExportService($this->entityTypeManager);

        return new ModelUpdateBatchGenerator(
            $trainingExport,
            $auditService,
            $driftDetector,
            $selfAssessment,
            new ExtractionImprovementSuggestionService($selfAssessment, $driftDetector, $auditService, $trainingExport),
            $this->storageDirectory ?? __DIR__.'/../../../var/model-updates',
        );
    }

    private function json(mixed $payload, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
