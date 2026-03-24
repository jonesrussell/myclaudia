<?php

declare(strict_types=1);

namespace Claudriel\Ingestion\Handler;

use Claudriel\Domain\Pipeline\SectorNormalizer;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\Prospect;
use Claudriel\Ingestion\IngestHandlerInterface;
use Claudriel\Support\ContentHasher;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class ProspectIngestHandler implements IngestHandlerInterface
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function supports(string $type): bool
    {
        return $type === 'lead.imported';
    }

    /**
     * @param  array{source: string, type: string, payload: array<string, mixed>, timestamp?: string, tenant_id?: mixed, trace_id?: mixed}  $data
     * @return array<string, mixed>
     */
    public function handle(array $data): array
    {
        $payload = $data['payload'];
        $externalId = (string) ($payload['external_id'] ?? '');
        $workspaceUuid = (string) ($payload['workspace_uuid'] ?? '');

        // Create McEvent for audit trail
        $contentHash = ContentHasher::hash(array_merge($payload, [
            'source' => $data['source'],
            'type' => $data['type'],
        ]));

        $event = new McEvent([
            'source' => $data['source'],
            'type' => $data['type'],
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'occurred' => $data['timestamp'] ?? date('Y-m-d H:i:s'),
            'category' => 'pipeline',
            'content_hash' => $contentHash,
            'tenant_id' => $data['tenant_id'] ?? null,
            'trace_id' => $data['trace_id'] ?? null,
        ]);

        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $eventStorage->save($event);

        // Deduplicate prospect by external_id + workspace_uuid
        $prospect = $this->findExistingProspect($externalId, $workspaceUuid);
        $isNew = $prospect === null;

        if ($isNew) {
            $sector = (string) ($payload['sector'] ?? '');
            $prospect = new Prospect([
                'name' => (string) ($payload['name'] ?? ''),
                'description' => (string) ($payload['description'] ?? ''),
                'contact_name' => (string) ($payload['contact_name'] ?? ''),
                'contact_email' => (string) ($payload['contact_email'] ?? ''),
                'source_url' => (string) ($payload['source_url'] ?? ''),
                'closing_date' => (string) ($payload['closing_date'] ?? ''),
                'value' => (string) ($payload['value'] ?? ''),
                'sector' => $sector !== '' ? SectorNormalizer::normalize($sector) : '',
                'external_id' => $externalId,
                'workspace_uuid' => $workspaceUuid,
                'tenant_id' => $data['tenant_id'] ?? null,
            ]);

            // Upsert Person from contact info before first save
            $personUuid = null;
            $contactEmail = (string) ($payload['contact_email'] ?? '');
            if ($contactEmail !== '') {
                $personUuid = $this->upsertPerson($payload, $data);
                $prospect->set('person_uuid', $personUuid);
            }

            $prospectStorage = $this->entityTypeManager->getStorage('prospect');
            $prospectStorage->save($prospect);
        } else {
            $personUuid = null;
        }

        return array_filter([
            'status' => $isNew ? 'created' : 'skipped',
            'entity_type' => 'prospect',
            'uuid' => $prospect->uuid(),
            'event_uuid' => $event->uuid(),
            'person_uuid' => $personUuid,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function findExistingProspect(string $externalId, string $workspaceUuid): ?Prospect
    {
        if ($externalId === '') {
            return null;
        }

        $storage = $this->entityTypeManager->getStorage('prospect');
        $query = $storage->getQuery();
        $query->accessCheck(false);
        $query->condition('external_id', $externalId);
        $ids = $query->execute();

        foreach ($storage->loadMultiple($ids) as $entity) {
            if ($entity instanceof Prospect && (string) ($entity->get('workspace_uuid') ?? '') === $workspaceUuid) {
                return $entity;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $data
     */
    private function upsertPerson(array $payload, array $data): string
    {
        $email = (string) ($payload['contact_email'] ?? '');
        $personStorage = $this->entityTypeManager->getStorage('person');

        $query = $personStorage->getQuery();
        $query->accessCheck(false);
        $query->condition('email', $email);
        $ids = $query->execute();

        $person = $ids !== [] ? $personStorage->load(reset($ids)) : null;

        if (! $person instanceof Person) {
            $person = new Person([
                'email' => $email,
                'name' => (string) ($payload['contact_name'] ?? $email),
                'tier' => 'contact',
                'source' => 'northcloud',
            ]);
        }

        $person->set('name', (string) ($payload['contact_name'] ?? $person->get('name') ?? $email));
        $person->set('email', $email);
        $person->set('tenant_id', $data['tenant_id'] ?? $person->get('tenant_id'));
        $person->set('source', 'northcloud');
        $person->set('last_interaction_at', $data['timestamp'] ?? date(\DateTimeInterface::ATOM));
        $personStorage->save($person);

        return (string) $person->uuid();
    }
}
