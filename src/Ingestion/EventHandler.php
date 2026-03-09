<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Foundation\Ingestion\Envelope;

final class EventHandler
{
    public function __construct(
        private readonly EntityRepositoryInterface $eventRepo,
        private readonly EntityRepositoryInterface $personRepo,
    ) {}

    public function handle(Envelope $envelope): McEvent
    {
        $event = new McEvent([
            'source'    => $envelope->source,
            'type'      => $envelope->type,
            'payload'   => json_encode($envelope->payload, JSON_THROW_ON_ERROR),
            'tenant_id' => $envelope->tenantId,
            'trace_id'  => $envelope->traceId,
            'occurred'  => $envelope->timestamp,
        ]);
        $this->eventRepo->save($event);
        $this->upsertPerson($envelope->payload['from_email'] ?? '', $envelope->payload['from_name'] ?? '', $envelope->tenantId ?? '');
        return $event;
    }

    private function upsertPerson(string $email, string $name, string $tenantId): void
    {
        if ($this->personRepo->count(['email' => $email]) > 0) {
            return;
        }
        $this->personRepo->save(new Person([
            'email'     => $email,
            'name'      => $name ?: $email,
            'tenant_id' => $tenantId,
        ]));
    }
}
