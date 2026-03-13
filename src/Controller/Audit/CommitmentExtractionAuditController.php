<?php

declare(strict_types=1);

namespace Claudriel\Controller\Audit;

use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class CommitmentExtractionAuditController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
    ) {}

    public function index(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $service = new CommitmentExtractionAuditService($this->entityTypeManager);
        $page = max(1, (int) ($query['page'] ?? $httpRequest?->query->get('page', 1) ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? $httpRequest?->query->get('per_page', 25) ?? 25));

        $payload = [
            'metrics' => $service->getSummaryMetrics(),
            'confidence_distribution' => $service->getConfidenceDistribution(),
            'top_senders' => $service->getTopSenders(),
            'logs' => $service->getPaginatedLogs($page, $perPage),
        ];

        if ($this->twig !== null) {
            $html = $this->twig->render('audit/commitment-extraction/index.twig', $payload);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return new SsrResponse(
            content: json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    public function show(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $service = new CommitmentExtractionAuditService($this->entityTypeManager);
        $id = $params['id'] ?? null;
        $log = $id !== null ? $service->getLogDetail((string) $id) : null;

        if ($log === null) {
            return new SsrResponse(
                content: json_encode(['message' => 'Commitment extraction log not found.'], JSON_THROW_ON_ERROR),
                statusCode: 404,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        if ($this->twig !== null) {
            $html = $this->twig->render('audit/commitment-extraction/show.twig', ['log' => $log]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return new SsrResponse(
            content: json_encode(['log' => $log], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
