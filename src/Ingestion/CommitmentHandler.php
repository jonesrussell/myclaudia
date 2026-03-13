<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CommitmentHandler
{
    private const CONFIDENCE_THRESHOLD = 0.7;

    public function __construct(
        private readonly EntityRepositoryInterface $repo,
        private readonly EntityRepositoryInterface $logRepo,
    ) {}

    /** @param array<int, array{title: string, confidence: float}> $candidates */
    public function handle(array $candidates, McEvent $event, string $personId, string $tenantId): void
    {
        foreach ($candidates as $candidate) {
            if (($candidate['confidence'] ?? 0.0) < self::CONFIDENCE_THRESHOLD) {
                $this->logLowConfidenceCandidate($candidate, $event);

                continue;
            }
            $this->repo->save(new Commitment([
                'title' => $candidate['title'],
                'confidence' => $candidate['confidence'],
                'status' => 'pending',
                'source_event_id' => $event->id(),
                'person_id' => $personId,
                'tenant_id' => $tenantId,
            ]));
        }
    }

    /** @param array{title?: string, confidence?: float} $candidate */
    private function logLowConfidenceCandidate(array $candidate, McEvent $event): void
    {
        $this->logRepo->save(new CommitmentExtractionLog([
            'mc_event_id' => is_int($event->id()) ? $event->id() : null,
            'raw_event_payload' => $this->normalizePayload($event->get('payload')),
            'extracted_commitment_payload' => $this->normalizePayload($candidate),
            'confidence' => (float) ($candidate['confidence'] ?? 0.0),
        ]));
    }

    private function normalizePayload(mixed $payload): string
    {
        if (is_string($payload)) {
            return $payload;
        }

        $encodedPayload = json_encode($payload);

        return $encodedPayload === false ? '' : $encodedPayload;
    }
}
