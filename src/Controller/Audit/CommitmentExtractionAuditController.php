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

    public function trends(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
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

    public function trendsJson(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        return $this->json($this->buildTrendsPayload($query, $httpRequest));
    }

    public function sender(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $service = new CommitmentExtractionAuditService($this->entityTypeManager);
        $senderEmail = rawurldecode((string) ($params['email'] ?? ''));
        $senderTrends = $service->getSenderTrends($senderEmail, 30);

        if ($this->twig !== null) {
            $html = $this->twig->render('audit/commitment-extraction/sender-trends.twig', [
                'sender_trends' => $senderTrends,
            ]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return $this->json(['sender_trends' => $senderTrends]);
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
            'sender_lookup' => $senderLookup,
            'sender_lookup_url' => $senderLookup !== ''
                ? sprintf('/audit/commitment-extraction/sender/%s', rawurlencode($senderLookup))
                : null,
            'sender_preview' => $senderLookup !== ''
                ? $service->getSenderTrends($senderLookup, 30)
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
}
