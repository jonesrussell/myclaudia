<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Ingestion\EventCategorizer;
use Claudriel\Ingestion\Handler\CalendarEventIngestHandler;
use Claudriel\Ingestion\Handler\CommitmentIngestHandler;
use Claudriel\Ingestion\Handler\GenericEventHandler;
use Claudriel\Ingestion\Handler\PersonIngestHandler;
use Claudriel\Ingestion\IngestHandlerRegistry;
use Claudriel\Support\AutomatedSenderDetector;
use Claudriel\Support\BriefSignal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;

/**
 * POST /api/ingest — accepts external ingestion payloads.
 *
 * HttpKernel calls: new $class($entityTypeManager, $twig)
 * then: $instance->handle($params, $query, $account, $httpRequest)
 */
final class IngestController
{
    private readonly IngestHandlerRegistry $registry;

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        mixed $twig = null,
    ) {
        $personRepo = new StorageRepositoryAdapter($this->entityTypeManager->getStorage('person'));
        $categorizer = new EventCategorizer(new AutomatedSenderDetector, $personRepo);
        $fallback = new GenericEventHandler($this->entityTypeManager, $categorizer);
        $this->registry = new IngestHandlerRegistry($fallback);
        $this->registry->addHandler(new CalendarEventIngestHandler($this->entityTypeManager, $categorizer));
        $this->registry->addHandler(new CommitmentIngestHandler($this->entityTypeManager));
        $this->registry->addHandler(new PersonIngestHandler($this->entityTypeManager));
    }

    public function handle(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): JsonResponse
    {
        // Validate bearer token.
        $token = $this->extractBearerToken($httpRequest);
        $validKeys = $this->getValidApiKeys();

        if ($token === null || $validKeys === [] || ! in_array($token, $validKeys, true)) {
            return new JsonResponse(['error' => 'Unauthorized'], 401);
        }

        $raw = $httpRequest instanceof Request ? $httpRequest->getContent() : '';
        $data = json_decode($raw, true);

        if (! is_array($data) || ! isset($data['source'], $data['type'], $data['payload'])) {
            return new JsonResponse([
                'error' => 'Invalid payload',
                'required' => ['source', 'type', 'payload'],
            ], 422);
        }

        if (! is_array($data['payload'])) {
            return new JsonResponse([
                'error' => 'Invalid payload: "payload" must be an object',
            ], 422);
        }

        if (! isset($data['timestamp']) || ! is_string($data['timestamp']) || trim($data['timestamp']) === '') {
            $data['timestamp'] = (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM);
        }
        $data['tenant_id'] = $data['tenant_id'] ?? null;
        $data['trace_id'] = $data['trace_id'] ?? null;

        $result = $this->registry->handle($data);

        $this->touchBriefSignal();

        return new JsonResponse($result, 201);
    }

    private function extractBearerToken(mixed $httpRequest): ?string
    {
        if (! $httpRequest instanceof Request) {
            return null;
        }

        $header = $httpRequest->headers->get('Authorization', '');
        if (! is_string($header) || ! str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = substr($header, 7);

        return $token !== '' ? $token : null;
    }

    private function touchBriefSignal(): void
    {
        $storageDir = getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2).'/storage';
        $signal = new BriefSignal($storageDir.'/brief-signal.txt');
        $signal->touch();
    }

    /**
     * @return list<string>
     */
    private function getValidApiKeys(): array
    {
        $key = $_ENV['CLAUDRIEL_API_KEY']
            ?? getenv('CLAUDRIEL_API_KEY')
            ?: null;

        if ($key === null || $key === '' || $key === false) {
            return [];
        }

        return [(string) $key];
    }
}
