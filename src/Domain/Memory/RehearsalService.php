<?php

declare(strict_types=1);

namespace Claudriel\Domain\Memory;

use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class RehearsalService
{
    private const float REHEARSAL_BOOST = 0.05;

    private const float MAX_SCORE = 1.0;

    /**
     * @param  array<string, EntityRepositoryInterface>  $repositories
     */
    public function __construct(
        private readonly array $repositories,
    ) {}

    public function recordAccess(string $entityType, string $entityUuid): void
    {
        $repo = $this->repositories[$entityType] ?? null;
        if ($repo === null) {
            return;
        }

        $entities = $repo->findBy(['uuid' => $entityUuid]);
        if ($entities === []) {
            return;
        }

        $entity = $entities[0];
        assert($entity instanceof ContentEntityInterface);

        $accessCount = $entity->get('access_count');
        $entity->set('access_count', (is_int($accessCount) ? $accessCount : 0) + 1);
        $entity->set('last_accessed_at', (new \DateTimeImmutable)->format('c'));

        $currentScore = $entity->get('importance_score');
        $score = is_numeric($currentScore) ? (float) $currentScore : 1.0;
        $entity->set('importance_score', min(self::MAX_SCORE, $score + self::REHEARSAL_BOOST));

        $repo->save($entity);
    }
}
