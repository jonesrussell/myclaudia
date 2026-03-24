<?php

declare(strict_types=1);

namespace Claudriel\Controller\Pipeline;

use Claudriel\Domain\Pipeline\Pdf\BrandedResponseBuilder;
use Claudriel\Domain\Pipeline\Pdf\HtmlPdfGenerator;
use Claudriel\Domain\Pipeline\Pdf\LatexPdfGenerator;
use Claudriel\Entity\PipelineConfig;
use Claudriel\Entity\Prospect;
use Claudriel\Entity\ProspectAttachment;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class PipelinePdfController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function generate(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): JsonResponse
    {
        $authError = $this->requireApiKey($httpRequest);
        if ($authError !== null) {
            return $authError;
        }

        $uuid = $params['uuid'] ?? '';
        $prospect = $this->loadProspect($uuid);
        if (! $prospect instanceof Prospect) {
            return new JsonResponse(['error' => 'Prospect not found'], 404);
        }

        $workspaceUuid = (string) ($prospect->get('workspace_uuid') ?? '');
        $config = $this->loadPipelineConfig($workspaceUuid);
        if (! $config instanceof PipelineConfig) {
            return new JsonResponse(['error' => 'No PipelineConfig found'], 404);
        }

        $latex = new LatexPdfGenerator;
        $html = new HtmlPdfGenerator;

        $generator = $latex->isAvailable() ? $latex : $html;

        try {
            $pdfPath = $generator->generate($prospect, $config);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 500);
        }

        $filename = sprintf('response-%s-%s.pdf', preg_replace('/[^a-z0-9]+/i', '-', (string) ($prospect->get('name') ?? 'untitled')), date('Ymd'));

        // Store as ProspectAttachment
        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 3).'/storage';
        $attachmentDir = $storageDir.'/pipeline-attachments';
        if (! is_dir($attachmentDir)) {
            mkdir($attachmentDir, 0o755, true);
        }

        $storedPath = $attachmentDir.'/'.$filename;
        copy($pdfPath, $storedPath);

        $attachmentStorage = $this->entityTypeManager->getStorage('prospect_attachment');
        $attachment = new ProspectAttachment([
            'prospect_uuid' => $prospect->uuid(),
            'filename' => $filename,
            'storage_path' => $storedPath,
            'content_type' => 'application/pdf',
            'workspace_uuid' => $workspaceUuid,
            'tenant_id' => $prospect->get('tenant_id'),
        ]);
        $attachmentStorage->save($attachment);

        return new JsonResponse([
            'uuid' => $attachment->uuid(),
            'filename' => $filename,
        ], 201);
    }

    public function preview(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): Response
    {
        $uuid = $params['uuid'] ?? '';
        $prospect = $this->loadProspect($uuid);
        if (! $prospect instanceof Prospect) {
            return new JsonResponse(['error' => 'Prospect not found'], 404);
        }

        $workspaceUuid = (string) ($prospect->get('workspace_uuid') ?? '');
        $config = $this->loadPipelineConfig($workspaceUuid);
        if (! $config instanceof PipelineConfig) {
            return new JsonResponse(['error' => 'No PipelineConfig found'], 404);
        }

        $builder = new BrandedResponseBuilder;
        $html = $builder->buildHtml($prospect, $config);

        return new Response($html, 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function tex(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): Response
    {
        $uuid = $params['uuid'] ?? '';
        $prospect = $this->loadProspect($uuid);
        if (! $prospect instanceof Prospect) {
            return new JsonResponse(['error' => 'Prospect not found'], 404);
        }

        $workspaceUuid = (string) ($prospect->get('workspace_uuid') ?? '');
        $config = $this->loadPipelineConfig($workspaceUuid);
        if (! $config instanceof PipelineConfig) {
            return new JsonResponse(['error' => 'No PipelineConfig found'], 404);
        }

        $builder = new BrandedResponseBuilder;
        $latex = $builder->buildLatex($prospect, $config);

        return new Response($latex, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
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

    private function loadProspect(string $uuid): ?Prospect
    {
        if ($uuid === '') {
            return null;
        }
        $storage = $this->entityTypeManager->getStorage('prospect');
        $entityQuery = $storage->getQuery();
        $entityQuery->accessCheck(false);
        $entityQuery->condition('uuid', $uuid);
        $ids = $entityQuery->execute();

        $entity = $ids !== [] ? $storage->load(reset($ids)) : null;

        return $entity instanceof Prospect ? $entity : null;
    }

    private function loadPipelineConfig(string $workspaceUuid): ?PipelineConfig
    {
        if ($workspaceUuid === '') {
            return null;
        }
        $storage = $this->entityTypeManager->getStorage('pipeline_config');
        $entityQuery = $storage->getQuery();
        $entityQuery->accessCheck(false);
        $entityQuery->condition('workspace_uuid', $workspaceUuid);
        $ids = $entityQuery->execute();

        $entity = $ids !== [] ? $storage->load(reset($ids)) : null;

        return $entity instanceof PipelineConfig ? $entity : null;
    }
}
