<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Entity\TriageEntry;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class TriageApiController
{
    private const VALID_STATUSES = ['open', 'resolved', 'ignored'];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        mixed $twig = null,
    ) {
        unset($twig);
    }

    public function list(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $statusFilter = $query['status'] ?? 'open';
        $entries = array_values(array_filter(
            $this->loadAll(),
            function (TriageEntry $entry) use ($query, $statusFilter): bool {
                if (is_string($statusFilter) && $statusFilter !== 'all' && $statusFilter !== (string) ($entry->get('status') ?? 'open')) {
                    return false;
                }
                if (isset($query['sender_email']) && (string) $query['sender_email'] !== (string) ($entry->get('sender_email') ?? '')) {
                    return false;
                }

                return true;
            },
        ));

        usort($entries, fn (TriageEntry $a, TriageEntry $b): int => ((string) ($b->get('occurred_at') ?? '')) <=> ((string) ($a->get('occurred_at') ?? '')));

        return $this->json([
            'triage' => array_map(fn (TriageEntry $entry) => $this->serialize($entry), $entries),
        ]);
    }

    public function create(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $body = json_decode($httpRequest?->getContent() ?? '', true) ?? [];
        $status = $this->normalizeStatus($body['status'] ?? 'open');
        if ($status === null) {
            return $this->json(['error' => 'Field "status" is invalid.'], 422);
        }

        $entry = new TriageEntry([
            'sender_name' => is_string($body['sender_name'] ?? null) && trim($body['sender_name']) !== '' ? trim($body['sender_name']) : 'Unknown sender',
            'sender_email' => is_string($body['sender_email'] ?? null) ? trim($body['sender_email']) : '',
            'summary' => is_string($body['summary'] ?? null) ? trim($body['summary']) : '',
            'status' => $status,
            'source' => is_string($body['source'] ?? null) ? $body['source'] : 'manual',
            'tenant_id' => $body['tenant_id'] ?? null,
            'occurred_at' => $this->normalizeDateTime($body['occurred_at'] ?? null) ?? (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            'external_id' => is_string($body['external_id'] ?? null) ? $body['external_id'] : null,
            'content_hash' => is_string($body['content_hash'] ?? null) ? $body['content_hash'] : null,
            'raw_payload' => json_encode($body['raw_payload'] ?? new \stdClass, JSON_THROW_ON_ERROR),
        ]);

        $this->entityTypeManager->getStorage('triage_entry')->save($entry);

        return $this->json(['triage' => $this->serialize($entry)], 201);
    }

    public function show(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $entry = $this->findByUuid((string) ($params['uuid'] ?? ''));
        if ($entry === null) {
            return $this->json(['error' => 'Triage entry not found.'], 404);
        }

        return $this->json(['triage' => $this->serialize($entry)]);
    }

    public function update(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $entry = $this->findByUuid((string) ($params['uuid'] ?? ''));
        if ($entry === null) {
            return $this->json(['error' => 'Triage entry not found.'], 404);
        }

        $body = json_decode($httpRequest?->getContent() ?? '', true) ?? [];
        foreach (['sender_name', 'sender_email', 'summary', 'source', 'tenant_id', 'external_id', 'content_hash'] as $field) {
            if (array_key_exists($field, $body)) {
                $entry->set($field, $body[$field]);
            }
        }

        if (array_key_exists('status', $body)) {
            $status = $this->normalizeStatus($body['status']);
            if ($status === null) {
                return $this->json(['error' => 'Field "status" is invalid.'], 422);
            }
            $entry->set('status', $status);
        }

        if (array_key_exists('occurred_at', $body)) {
            $normalized = $this->normalizeDateTime($body['occurred_at']);
            if ($normalized === null) {
                return $this->json(['error' => 'Field "occurred_at" must be a valid datetime.'], 422);
            }
            $entry->set('occurred_at', $normalized);
        }

        if (array_key_exists('raw_payload', $body)) {
            $entry->set('raw_payload', json_encode($body['raw_payload'], JSON_THROW_ON_ERROR));
        }

        $this->entityTypeManager->getStorage('triage_entry')->save($entry);

        return $this->json(['triage' => $this->serialize($entry)]);
    }

    public function delete(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        $entry = $this->findByUuid((string) ($params['uuid'] ?? ''));
        if ($entry === null) {
            return $this->json(['error' => 'Triage entry not found.'], 404);
        }

        $this->entityTypeManager->getStorage('triage_entry')->delete([$entry]);

        return $this->json(['deleted' => true]);
    }

    /**
     * @return list<TriageEntry>
     */
    private function loadAll(): array
    {
        $storage = $this->entityTypeManager->getStorage('triage_entry');
        $entries = $storage->loadMultiple($storage->getQuery()->execute());

        return array_values(array_filter($entries, fn ($entry): bool => $entry instanceof TriageEntry));
    }

    private function findByUuid(string $uuid): ?TriageEntry
    {
        if ($uuid === '') {
            return null;
        }

        $storage = $this->entityTypeManager->getStorage('triage_entry');
        $ids = $storage->getQuery()->condition('uuid', $uuid)->execute();
        if ($ids === []) {
            return null;
        }

        $entry = $storage->load(reset($ids));

        return $entry instanceof TriageEntry ? $entry : null;
    }

    private function normalizeStatus(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        return in_array($value, self::VALID_STATUSES, true) ? $value : null;
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(TriageEntry $entry): array
    {
        $rawPayload = $entry->get('raw_payload');
        $decoded = is_string($rawPayload) ? json_decode($rawPayload, true) : $rawPayload;

        return [
            'uuid' => $entry->get('uuid'),
            'sender_name' => $entry->get('sender_name') ?? 'Unknown sender',
            'sender_email' => $entry->get('sender_email') ?? '',
            'summary' => $entry->get('summary') ?? '',
            'status' => $entry->get('status') ?? 'open',
            'source' => $entry->get('source') ?? 'gmail',
            'tenant_id' => $entry->get('tenant_id'),
            'occurred_at' => $entry->get('occurred_at'),
            'external_id' => $entry->get('external_id'),
            'content_hash' => $entry->get('content_hash'),
            'raw_payload' => $decoded ?? new \stdClass,
            'created_at' => $entry->get('created_at'),
            'updated_at' => $entry->get('updated_at'),
        ];
    }

    private function json(mixed $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
