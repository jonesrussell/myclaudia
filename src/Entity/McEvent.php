<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Immutable ingested fact.
 * Named McEvent to avoid collision with PHP's reserved 'Event' keyword context.
 */
final class McEvent extends ContentEntityBase
{
    protected string $entityTypeId = 'mc_event';

    protected array $entityKeys = [
        'id'           => 'eid',
        'uuid'         => 'uuid',
        'content_hash' => 'content_hash',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, 'mc_event', $this->entityKeys);

        if ($this->get('category') === null) {
            $this->set('category', 'notification');
        }
    }

    /**
     * @return array<string, string>
     */
    public function getEntityKeys(): array
    {
        return $this->entityKeys;
    }
}
