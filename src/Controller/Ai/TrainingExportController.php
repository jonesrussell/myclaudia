<?php

declare(strict_types=1);

namespace Claudriel\Controller\Ai;

use Claudriel\Service\Ai\TrainingExportService;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class TrainingExportController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function daily(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $service = new TrainingExportService($this->entityTypeManager);
        $days = max(1, (int) ($query['days'] ?? 7));

        return $this->json($service->exportDailySamples($days));
    }

    public function sender(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $service = new TrainingExportService($this->entityTypeManager);
        $days = max(1, (int) ($query['days'] ?? 30));
        $email = rawurldecode((string) ($params['email'] ?? ''));

        return $this->json($service->exportSenderSamples($email, $days));
    }

    public function failures(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $service = new TrainingExportService($this->entityTypeManager);
        $days = max(1, (int) ($query['days'] ?? 90));

        return $this->json($service->exportAllFailures($days));
    }

    private function json(mixed $payload, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($payload, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
