<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Support\ContentHasher;
use Claudriel\Support\PersonTierClassifier;
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
        $payload = $envelope->payload;
        $contentHash = ContentHasher::hash(array_merge($payload, [
            'source' => $envelope->source,
            'type'   => $envelope->type,
        ]));

        $existing = $this->eventRepo->findBy(['content_hash' => $contentHash]);
        if ($existing !== []) {
            return $existing[0];
        }

        $this->upsertPerson($payload['from_email'] ?? '', $payload['from_name'] ?? '', $envelope->tenantId ?? '');

        $category = EventCategorizer::categorize($envelope->source, $envelope->type, $payload);

        $event = new McEvent([
            'source'       => $envelope->source,
            'type'         => $envelope->type,
            'payload'      => json_encode($payload, JSON_THROW_ON_ERROR),
            'content_hash' => $contentHash,
            'category'     => $category,
            'tenant_id'    => $envelope->tenantId,
            'trace_id'     => $envelope->traceId,
            'occurred'     => $envelope->timestamp,
        ]);
        $this->eventRepo->save($event);

        return $event;
    }

    private function upsertPerson(string $email, string $name, string $tenantId): void
    {
        if ($this->personRepo->count(['email' => $email]) > 0) {
            return;
        }
        $tier = PersonTierClassifier::classify($email);
        $this->personRepo->save(new Person([
            'email'     => $email,
            'name'      => $name ?: $email,
            'tier'      => $tier,
            'tenant_id' => $tenantId,
        ]));
    }
}
