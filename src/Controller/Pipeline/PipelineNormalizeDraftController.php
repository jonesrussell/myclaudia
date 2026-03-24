<?php

declare(strict_types=1);

namespace Claudriel\Controller\Pipeline;

use Claudriel\Entity\Prospect;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class PipelineNormalizeDraftController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly object $aiClient,
    ) {}

    public function normalize(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): JsonResponse
    {
        $authError = $this->requireApiKey($httpRequest);
        if ($authError !== null) {
            return $authError;
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return new JsonResponse(['error' => 'prospect uuid is required'], 400);
        }

        $storage = $this->entityTypeManager->getStorage('prospect');
        $entityQuery = $storage->getQuery();
        $entityQuery->accessCheck(false);
        $entityQuery->condition('uuid', $uuid);
        $ids = $entityQuery->execute();

        $prospect = $ids !== [] ? $storage->load(reset($ids)) : null;
        if (! $prospect instanceof Prospect) {
            return new JsonResponse(['error' => 'Prospect not found'], 404);
        }

        $draftMarkdown = (string) ($prospect->get('draft_pdf_markdown') ?? '');
        if ($draftMarkdown === '') {
            return new JsonResponse(['error' => 'No draft markdown to normalize'], 400);
        }

        $prompt = <<<PROMPT
        You are a technical writing assistant. Clean up and normalize the following draft text into well-structured markdown suitable for a professional PDF response letter.

        Rules:
        - Fix formatting, spelling, and grammar
        - Organize into clear paragraphs
        - Use markdown: **bold** for emphasis, - for bullet lists
        - Keep {{contact_name}} placeholder intact
        - Preserve all factual content

        Draft text:
        {$draftMarkdown}

        Respond with ONLY the cleaned markdown, no explanations.
        PROMPT;

        $normalized = trim($this->aiClient->complete($prompt));
        if ($normalized === '') {
            return new JsonResponse(['error' => 'AI returned empty response'], 500);
        }

        $prospect->set('draft_pdf_markdown', $normalized);
        $storage->save($prospect);

        return new JsonResponse([
            'uuid' => $prospect->uuid(),
            'draft_pdf_markdown' => $normalized,
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
}
