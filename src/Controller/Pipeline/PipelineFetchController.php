<?php

declare(strict_types=1);

namespace Claudriel\Controller\Pipeline;

use Claudriel\Domain\Pipeline\NorthCloudLeadFetcher;
use Claudriel\Entity\PipelineConfig;
use Claudriel\Ingestion\Handler\ProspectIngestHandler;
use Claudriel\Ingestion\NorthCloudLeadNormalizer;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class PipelineFetchController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function fetch(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): JsonResponse
    {
        $authError = $this->requireApiKey($httpRequest);
        if ($authError !== null) {
            return $authError;
        }

        $workspaceUuid = $params['workspace_uuid'] ?? '';
        if ($workspaceUuid === '') {
            return new JsonResponse(['error' => 'workspace_uuid is required'], 400);
        }

        $config = $this->loadPipelineConfig($workspaceUuid);
        if (! $config instanceof PipelineConfig) {
            return new JsonResponse(['error' => 'No PipelineConfig found for workspace'], 404);
        }

        $tenantId = (string) ($config->get('tenant_id') ?? $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default');

        $fetcher = new NorthCloudLeadFetcher;
        $normalizer = new NorthCloudLeadNormalizer;
        $handler = new ProspectIngestHandler($this->entityTypeManager);

        $hits = $fetcher->fetch($config);

        $imported = 0;
        $skipped = 0;

        foreach ($hits as $hit) {
            $data = $normalizer->normalize($hit, $tenantId, $workspaceUuid);
            $result = $handler->handle($data);

            if (($result['status'] ?? '') === 'created') {
                $imported++;
            } else {
                $skipped++;
            }
        }

        return new JsonResponse([
            'imported' => $imported,
            'skipped' => $skipped,
            'total' => count($hits),
        ]);
    }

    private function requireApiKey(?Request $httpRequest): ?JsonResponse
    {
        if (! $httpRequest instanceof Request) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $header = $httpRequest->headers->get('Authorization', '');
        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $token = substr($header, 7);
        $validKey = $_ENV['CLAUDRIEL_API_KEY'] ?? getenv('CLAUDRIEL_API_KEY') ?: '';

        if ($token === '' || $validKey === '' || $token !== $validKey) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        return null;
    }

    private function loadPipelineConfig(string $workspaceUuid): ?PipelineConfig
    {
        $storage = $this->entityTypeManager->getStorage('pipeline_config');
        $query = $storage->getQuery();
        $query->accessCheck(false);
        $query->condition('workspace_uuid', $workspaceUuid);
        $ids = $query->execute();

        $entity = $ids !== [] ? $storage->load(reset($ids)) : null;

        return $entity instanceof PipelineConfig ? $entity : null;
    }
}
