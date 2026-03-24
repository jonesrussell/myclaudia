<?php

declare(strict_types=1);

namespace Claudriel\Controller\Pipeline;

use Claudriel\Entity\Prospect;
use Claudriel\Pipeline\LeadQualificationStep;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class PipelineQualifyController
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly object $aiClient,
    ) {}

    public function qualify(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): JsonResponse
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

        $step = new LeadQualificationStep($this->aiClient);
        $result = $step->process([
            'title' => (string) ($prospect->get('name') ?? ''),
            'description' => (string) ($prospect->get('description') ?? ''),
            'sector' => (string) ($prospect->get('sector') ?? ''),
            'company_profile' => '',
        ], new PipelineContext('qualify-'.$uuid, time()));

        if (! $result->success) {
            return new JsonResponse(['error' => $result->message], 500);
        }

        $data = $result->output;
        $prospect->set('qualify_rating', $data['rating'] ?? 0);
        $prospect->set('qualify_keywords', json_encode($data['keywords'] ?? [], JSON_THROW_ON_ERROR));
        $prospect->set('qualify_confidence', $data['confidence'] ?? 0.0);
        $prospect->set('qualify_notes', $data['summary'] ?? '');
        $prospect->set('qualify_raw', json_encode($data, JSON_THROW_ON_ERROR));

        if (($data['sector'] ?? '') !== '') {
            $prospect->set('sector', $data['sector']);
        }

        $storage->save($prospect);

        return new JsonResponse([
            'uuid' => $prospect->uuid(),
            'qualify_rating' => $data['rating'] ?? 0,
            'qualify_confidence' => $data['confidence'] ?? 0.0,
            'sector' => $data['sector'] ?? '',
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
