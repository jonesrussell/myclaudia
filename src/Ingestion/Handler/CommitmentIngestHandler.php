<?php

declare(strict_types=1);

namespace Claudriel\Ingestion\Handler;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use Claudriel\Ingestion\IngestHandlerInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Creates a Commitment entity and upserts a Person from commitment.detected events.
 */
final class CommitmentIngestHandler implements IngestHandlerInterface
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function supports(string $type): bool
    {
        return $type === 'commitment.detected';
    }

    public function handle(array $data): array
    {
        $payload = $data['payload'];

        // Upsert person if email is provided.
        $personUuid = null;
        $email = $payload['person_email'] ?? null;
        if (is_string($email) && $email !== '') {
            $personUuid = $this->upsertPerson(
                $email,
                $payload['person_name'] ?? $email,
            );
        }

        $commitment = new Commitment([
            'title'      => $payload['title'] ?? 'Untitled commitment',
            'confidence' => $payload['confidence'] ?? 1.0,
            'status'     => 'pending',
            'due_date'   => $payload['due_date'] ?? null,
            'source'     => $data['source'],
        ]);

        $storage = $this->entityTypeManager->getStorage('commitment');
        $storage->save($commitment);

        return [
            'status'      => 'created',
            'entity_type' => 'commitment',
            'uuid'        => $commitment->uuid(),
            'person_uuid' => $personUuid,
        ];
    }

    private function upsertPerson(string $email, string $name): ?string
    {
        $storage = $this->entityTypeManager->getStorage('person');
        $query = $storage->getQuery();
        $query->accessCheck(false);
        $query->condition('email', $email);
        $ids = $query->execute();

        if ($ids !== []) {
            $person = $storage->load(reset($ids));
            return $person?->uuid();
        }

        $person = new Person([
            'email' => $email,
            'name'  => $name,
        ]);
        $storage->save($person);

        return $person->uuid();
    }
}
