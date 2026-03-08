<?php

declare(strict_types=1);

namespace MyClaudia\DayBrief;

use MyClaudia\DriftDetector;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class DayBriefAssembler
{
    public function __construct(
        private readonly EntityRepositoryInterface $eventRepo,
        private readonly EntityRepositoryInterface $commitmentRepo,
        private readonly DriftDetector $driftDetector,
    ) {}

    /** @return array{recent_events: array, pending_commitments: array, drifting_commitments: array} */
    public function assemble(string $tenantId, \DateTimeImmutable $since): array
    {
        $recentEvents = array_filter(
            $this->eventRepo->findBy(['tenant_id' => $tenantId]),
            fn ($e) => new \DateTimeImmutable($e->get('occurred') ?? 'now') >= $since,
        );

        $pendingCommitments  = $this->commitmentRepo->findBy(['status' => 'pending', 'tenant_id' => $tenantId]);
        $driftingCommitments = $this->driftDetector->findDrifting($tenantId);

        return [
            'recent_events'        => array_values($recentEvents),
            'pending_commitments'  => $pendingCommitments,
            'drifting_commitments' => $driftingCommitments,
        ];
    }
}
