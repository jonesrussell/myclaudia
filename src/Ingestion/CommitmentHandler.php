<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CommitmentHandler
{
    private const CONFIDENCE_THRESHOLD = 0.7;

    public function __construct(private readonly EntityRepositoryInterface $repo) {}

    /** @param array<int, array{title: string, confidence: float}> $candidates */
    public function handle(array $candidates, McEvent $event, string $personId, string $tenantId): void
    {
        foreach ($candidates as $candidate) {
            if (($candidate['confidence'] ?? 0.0) < self::CONFIDENCE_THRESHOLD) {
                continue;
            }
            $this->repo->save(new Commitment([
                'title'           => $candidate['title'],
                'confidence'      => $candidate['confidence'],
                'status'          => 'pending',
                'source_event_id' => $event->id(),
                'person_id'       => $personId,
                'tenant_id'       => $tenantId,
            ]));
        }
    }
}
