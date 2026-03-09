<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class DriftDetector
{
    private const DRIFT_HOURS = 48;

    public function __construct(private readonly EntityRepositoryInterface $repo) {}

    /** @return ContentEntityInterface[] */
    public function findDrifting(string $tenantId): array
    {
        $cutoff = new \DateTimeImmutable(sprintf('-%d hours', self::DRIFT_HOURS));
        // Load all commitments and filter in memory — status is stored in the _data JSON blob.
        $all = $this->repo->findBy([]);
        $active = array_filter(
            $all,
            fn (ContentEntityInterface $c) => $c->get('status') === 'active',
        );

        return array_values(array_filter(
            $active,
            fn (ContentEntityInterface $c) => new \DateTimeImmutable($c->get('updated_at') ?? 'now') < $cutoff,
        ));
    }
}
