<?php

declare(strict_types=1);

namespace Claudriel\Ingestion\Handler;

use Claudriel\Entity\Person;
use Claudriel\Ingestion\IngestHandlerInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * Upserts a Person entity from person.created events.
 */
final class PersonIngestHandler implements IngestHandlerInterface
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
    ) {}

    public function supports(string $type): bool
    {
        return $type === 'person.created';
    }

    public function handle(array $data): array
    {
        $payload = $data['payload'];
        $email = $payload['email'] ?? '';

        if ($email === '') {
            return [
                'status'  => 'error',
                'message' => 'Missing required field: email',
            ];
        }

        $storage = $this->entityTypeManager->getStorage('person');

        // Check for existing person by email.
        $query = $storage->getQuery();
        $query->accessCheck(false);
        $query->condition('email', $email);
        $ids = $query->execute();

        if ($ids !== []) {
            // Update existing person.
            $person = $storage->load(reset($ids));
            if ($person !== null) {
                $name = $payload['name'] ?? null;
                if (is_string($name) && $name !== '') {
                    $person->set('name', $name);
                    $storage->save($person);
                }

                return [
                    'status'      => 'updated',
                    'entity_type' => 'person',
                    'uuid'        => $person->uuid(),
                ];
            }
        }

        $person = new Person([
            'email' => $email,
            'name'  => $payload['name'] ?? $email,
        ]);
        $storage->save($person);

        return [
            'status'      => 'created',
            'entity_type' => 'person',
            'uuid'        => $person->uuid(),
        ];
    }
}
