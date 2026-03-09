<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityStorageInterface;

/**
 * Adapts EntityStorageInterface to EntityRepositoryInterface.
 *
 * Controllers receive EntityTypeManager (which provides storage objects),
 * but the DayBriefAssembler expects EntityRepositoryInterface. This adapter
 * bridges the two without requiring full repository wiring.
 */
final class StorageRepositoryAdapter implements EntityRepositoryInterface
{
    public function __construct(
        private readonly EntityStorageInterface $storage,
    ) {}

    public function find(string $id, ?string $langcode = null, bool $fallback = false): ?EntityInterface
    {
        return $this->storage->load($id);
    }

    public function findBy(array $criteria, ?array $orderBy = null, ?int $limit = null): array
    {
        $query = $this->storage->getQuery();
        foreach ($criteria as $field => $value) {
            $query->condition($field, $value);
        }
        $ids = $query->execute();
        $entities = $this->storage->loadMultiple($ids);

        if ($limit !== null) {
            $entities = array_slice($entities, 0, $limit);
        }

        return array_values($entities);
    }

    public function save(EntityInterface $entity): int
    {
        return $this->storage->save($entity);
    }

    public function delete(EntityInterface $entity): void
    {
        $this->storage->delete([$entity]);
    }

    public function exists(string $id): bool
    {
        return $this->storage->load($id) !== null;
    }

    public function count(array $criteria = []): int
    {
        return count($this->findBy($criteria));
    }
}
