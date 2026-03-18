<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Service integration configuration record (e.g. Google OAuth tokens).
 *
 * Internal-only: no CRUD surfaces (admin UI, REST, or GraphQL) are needed.
 * Integrations are managed via OAuth flows and programmatic upserts, not user-facing forms.
 */
final class Integration extends ContentEntityBase
{
    protected string $entityTypeId = 'integration';

    protected array $entityKeys = [
        'id' => 'iid',
        'uuid' => 'uuid',
        'label' => 'name',
    ];

    public function __construct(array $values = [])
    {
        if (! isset($values['status'])) {
            $values['status'] = 'pending';
        }

        parent::__construct($values, $this->entityTypeId, $this->entityKeys);
    }
}
