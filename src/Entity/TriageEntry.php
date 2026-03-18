<?php

declare(strict_types=1);

namespace Claudriel\Entity;

use Waaseyaa\Entity\ContentEntityBase;

/**
 * Unprocessed message buffer entry, consumed by the ingestion pipeline.
 *
 * Internal-only: no CRUD surfaces (admin UI, REST, or GraphQL) are needed.
 * Triage entries are created by ingest endpoints and consumed by pipeline processing.
 */
final class TriageEntry extends ContentEntityBase
{
    protected string $entityTypeId = 'triage_entry';

    protected array $entityKeys = [
        'id' => 'teid',
        'uuid' => 'uuid',
        'label' => 'sender_name',
    ];

    public function __construct(array $values = [])
    {
        parent::__construct($values, $this->entityTypeId, $this->entityKeys);

        if ($this->get('status') === null) {
            $this->set('status', 'open');
        }
        if ($this->get('source') === null) {
            $this->set('source', 'gmail');
        }
        if ($this->get('tenant_id') === null) {
            $this->set('tenant_id', null);
        }
        if ($this->get('raw_payload') === null) {
            $this->set('raw_payload', '{}');
        }
    }
}
