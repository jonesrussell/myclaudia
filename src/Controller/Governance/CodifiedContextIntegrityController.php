<?php

declare(strict_types=1);

namespace Claudriel\Controller\Governance;

use Claudriel\Controller\Platform\ObservabilityDashboardController;
use Claudriel\Service\Governance\CodifiedContextIntegrityScanner;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class CodifiedContextIntegrityController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
        private readonly ?string $projectRoot = null,
        private readonly ?string $batchStorageDirectory = null,
    ) {}

    public function index(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $payload = $this->buildPayload();

        if ($this->twig !== null) {
            $html = $this->twig->render('governance/integrity/index.twig', $payload);

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
        return $this->json($this->buildPayload());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(): array
    {
        $scanner = new CodifiedContextIntegrityScanner($this->projectRoot ?? __DIR__.'/../../..');
        $scan = $scanner->scan();
        $classifications = $scanner->classifyIssues($scan['issues']);

        return [
            'generated_at' => $scan['generated_at'],
            'statusBarData' => $this->getStatusBarData(),
            'issues' => $scan['issues'],
            'classifications' => $classifications,
            'summary' => $scanner->summarize($scan['issues']),
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
