<?php

declare(strict_types=1);

namespace Claudriel\Controller\Audit;

use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Service\Audit\CommitmentExtractionAuditService;
use Claudriel\Service\Audit\CommitmentExtractionDriftDetector;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class CommitmentExtractionAuditController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
        private readonly ?string $projectRoot = null,
        private readonly ?string $batchStorageDirectory = null,
    ) {}

    public function index(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $service = new CommitmentExtractionAuditService($this->entityTypeManager);
        $page = max(1, (int) ($query['page'] ?? $httpRequest->query->get('page', 1)));
        $perPage = max(1, (int) ($query['per_page'] ?? $httpRequest->query->get('per_page', 25)));

        $payload = [
            'statusBarData' => $this->getStatusBarData(),
            'metrics' => $service->getSummaryMetrics(),
            'confidence_distribution' => $service->getConfidenceDistribution(),
            'failure_category_counts' => $service->getFailureCategoryCounts(),
            'failure_category_distribution' => $service->getFailureCategoryDistribution(),
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

    public function show(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
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

    public function trends(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $payload = $this->buildTrendsPayload($query, $httpRequest);

        if ($this->twig !== null) {
            $html = $this->twig->render('audit/commitment-extraction/trends.twig', $payload);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return $this->json($payload);
    }

    public function trendsJson(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        return $this->json($this->buildTrendsPayload($query, $httpRequest));
    }

    public function sender(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $service = new CommitmentExtractionAuditService($this->entityTypeManager);
        $senderEmail = rawurldecode((string) ($params['email'] ?? ''));
        $senderTrends = $service->getSenderTrends($senderEmail, 30);
        $senderFailureCategories = $service->getSenderFailureCategories($senderEmail);

        if ($this->twig !== null) {
            $html = $this->twig->render('audit/commitment-extraction/sender-trends.twig', [
                'sender_trends' => $senderTrends,
                'sender_failure_categories' => $senderFailureCategories,
            ]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return $this->json([
            'sender_trends' => $senderTrends,
            'sender_failure_categories' => $senderFailureCategories,
        ]);
    }

    public function drift(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $payload = $this->buildDriftPayload($query, $httpRequest);

        if ($this->twig !== null) {
            $html = $this->twig->render('audit/commitment-extraction/drift.twig', $payload);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return $this->json($payload);
    }

    public function driftJson(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        return $this->json($this->buildDriftPayload($query, $httpRequest));
    }

    public function senderDrift(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $service = new CommitmentExtractionAuditService($this->entityTypeManager);
        $detector = new CommitmentExtractionDriftDetector($service);
        $senderEmail = rawurldecode((string) ($params['email'] ?? ''));
        $senderDrift = $detector->detectSenderDrift($senderEmail, 30);

        if ($this->twig !== null) {
            $html = $this->twig->render('audit/commitment-extraction/sender-drift.twig', [
                'sender_drift' => $senderDrift,
            ]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return $this->json(['sender_drift' => $senderDrift]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildTrendsPayload(array $query = [], ?Request $httpRequest = null): array
    {
        $service = new CommitmentExtractionAuditService($this->entityTypeManager);
        $senderLookup = trim((string) ($query['sender_email'] ?? $httpRequest?->query->get('sender_email', '')));
        $senderLookup = $senderLookup !== '' ? strtolower($senderLookup) : '';

        return [
            'daily_trends_7' => $service->getDailyTrends(7),
            'daily_trends_30' => $service->getDailyTrends(30),
            'monthly_trends' => $service->getMonthlyTrends(3),
            'failure_category_counts' => $service->getFailureCategoryCounts(),
            'failure_category_distribution' => $service->getFailureCategoryDistribution(),
            'sender_lookup' => $senderLookup,
            'sender_lookup_url' => $senderLookup !== ''
                ? sprintf('/audit/commitment-extraction/sender/%s', rawurlencode($senderLookup))
                : null,
            'sender_preview' => $senderLookup !== ''
                ? $service->getSenderTrends($senderLookup, 30)
                : null,
            'sender_failure_categories_preview' => $senderLookup !== ''
                ? $service->getSenderFailureCategories($senderLookup)
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildDriftPayload(array $query = [], ?Request $httpRequest = null): array
    {
        $service = new CommitmentExtractionAuditService($this->entityTypeManager);
        $detector = new CommitmentExtractionDriftDetector($service);
        $senderLookup = trim((string) ($query['sender_email'] ?? $httpRequest?->query->get('sender_email', '')));
        $senderLookup = $senderLookup !== '' ? strtolower($senderLookup) : '';

        return [
            'drift' => $detector->detectDailyDrift(14),
            'sender_lookup' => $senderLookup,
            'sender_lookup_url' => $senderLookup !== ''
                ? sprintf('/audit/commitment-extraction/drift/sender/%s', rawurlencode($senderLookup))
                : null,
            'sender_drift_preview' => $senderLookup !== ''
                ? $detector->detectSenderDrift($senderLookup, 30)
                : null,
        ];
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
