<?php

declare(strict_types=1);

namespace Claudriel\Service\Ai;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeInterface;
use Waaseyaa\Entity\EntityTypeManager;

final class TrainingExportService
{
    /** @var array<string, McEvent|null> */
    private array $eventCache = [];

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    /**
     * @return array{
     *   window_days: int,
     *   generated_at: string,
     *   days: list<array{
     *     date: string,
     *     samples: list<array<string, mixed>>
     *   }>
     * }
     */
    public function exportDailySamples(int $days = 7): array
    {
        $days = max(1, $days);
        $end = new DateTimeImmutable('today');
        $start = $end->sub(new DateInterval(sprintf('P%dD', $days - 1)));

        $grouped = [];
        foreach (new DatePeriod($start, new DateInterval('P1D'), $end->add(new DateInterval('P1D'))) as $date) {
            $grouped[$date->format('Y-m-d')] = [];
        }

        foreach ($this->getNormalizedSamples() as $sample) {
            $occurredAt = $this->parseDateTime($sample['occurred_at']);
            if ($occurredAt === null) {
                continue;
            }

            $key = $occurredAt->format('Y-m-d');
            if (! array_key_exists($key, $grouped)) {
                continue;
            }

            $grouped[$key][] = $this->serializeSample($sample);
        }

        $results = [];
        foreach ($grouped as $date => $samples) {
            $results[] = [
                'date' => $date,
                'samples' => $samples,
            ];
        }

        return [
            'window_days' => $days,
            'generated_at' => gmdate(DateTimeInterface::ATOM),
            'days' => $results,
        ];
    }

    /**
     * @return array{
     *   sender: string,
     *   window_days: int,
     *   generated_at: string,
     *   samples: list<array<string, mixed>>
     * }
     */
    public function exportSenderSamples(string $email, int $days = 30): array
    {
        $sender = $this->normalizeSender($email) ?? strtolower(trim($email));
        $days = max(1, $days);
        $end = new DateTimeImmutable('today');
        $start = $end->sub(new DateInterval(sprintf('P%dD', $days - 1)));
        $samples = [];

        foreach ($this->getNormalizedSamples() as $sample) {
            if ($sample['sender'] !== $sender) {
                continue;
            }

            $occurredAt = $this->parseDateTime($sample['occurred_at']);
            if ($occurredAt === null) {
                continue;
            }

            $occurredDate = $occurredAt->setTime(0, 0);
            if ($occurredDate < $start || $occurredDate > $end) {
                continue;
            }

            $samples[] = $this->serializeSample($sample);
        }

        usort($samples, fn (array $a, array $b): int => [$b['occurred_at'], $b['mc_event_id']] <=> [$a['occurred_at'], $a['mc_event_id']]);

        return [
            'sender' => $sender,
            'window_days' => $days,
            'generated_at' => gmdate(DateTimeInterface::ATOM),
            'samples' => $samples,
        ];
    }

    /**
     * @return array{
     *   window_days: int,
     *   generated_at: string,
     *   samples: list<array<string, mixed>>
     * }
     */
    public function exportAllFailures(int $days = 90): array
    {
        $days = max(1, $days);
        $end = new DateTimeImmutable('today');
        $start = $end->sub(new DateInterval(sprintf('P%dD', $days - 1)));
        $samples = [];

        foreach ($this->getNormalizedSamples() as $sample) {
            if ($sample['label'] !== 'failure') {
                continue;
            }

            $occurredAt = $this->parseDateTime($sample['occurred_at']);
            if ($occurredAt === null) {
                continue;
            }

            $occurredDate = $occurredAt->setTime(0, 0);
            if ($occurredDate < $start || $occurredDate > $end) {
                continue;
            }

            $samples[] = $this->serializeSample($sample);
        }

        usort($samples, fn (array $a, array $b): int => [$b['occurred_at'], $b['mc_event_id']] <=> [$a['occurred_at'], $a['mc_event_id']]);

        return [
            'window_days' => $days,
            'generated_at' => gmdate(DateTimeInterface::ATOM),
            'samples' => $samples,
        ];
    }

    /**
     * @return list<array{
     *   mc_event_id: int|string|null,
     *   raw_event_payload: string,
     *   extracted_commitment_payload: string|null,
     *   confidence: float,
     *   failure_category: string|null,
     *   label: string,
     *   occurred_at: string|null,
     *   sender: string|null
     * }>
     */
    private function getNormalizedSamples(): array
    {
        $samples = [];

        foreach ($this->getCommitments() as $commitment) {
            $eventId = $commitment->get('source_event_id');
            $event = $eventId !== null ? $this->loadEvent((string) $eventId) : null;
            if ($event === null) {
                continue;
            }

            $samples[] = [
                'mc_event_id' => $event->id(),
                'raw_event_payload' => $this->normalizePayload($event->get('payload')),
                'extracted_commitment_payload' => $this->normalizePayload($this->buildCommitmentPayload($commitment)),
                'confidence' => (float) ($commitment->get('confidence') ?? 0.0),
                'failure_category' => null,
                'label' => 'success',
                'occurred_at' => $this->resolveOccurredAtForEvent($event),
                'sender' => $this->resolveSenderFromPayload($event->get('payload')) ?? $this->normalizeSender($event->get('from_email')),
            ];
        }

        foreach ($this->getLogs() as $log) {
            $samples[] = [
                'mc_event_id' => $log->get('mc_event_id'),
                'raw_event_payload' => $this->normalizePayload($log->get('raw_event_payload')),
                'extracted_commitment_payload' => $this->normalizeNullablePayload($log->get('extracted_commitment_payload')),
                'confidence' => (float) ($log->get('confidence') ?? 0.0),
                'failure_category' => $this->normalizeFailureCategory($log->get('failure_category')),
                'label' => 'failure',
                'occurred_at' => $this->resolveOccurredAtForLog($log),
                'sender' => $this->resolveSenderForLog($log),
            ];
        }

        return $samples;
    }

    /**
     * @return list<Commitment>
     */
    private function getCommitments(): array
    {
        $storage = $this->entityTypeManager->getStorage('commitment');
        $ids = $storage->getQuery()->execute();

        /** @var list<Commitment> $commitments */
        $commitments = array_values($storage->loadMultiple($ids));

        return $commitments;
    }

    /**
     * @return list<CommitmentExtractionLog>
     */
    private function getLogs(): array
    {
        $storage = $this->entityTypeManager->getStorage('commitment_extraction_log');
        $ids = $storage->getQuery()->execute();

        /** @var list<CommitmentExtractionLog> $logs */
        $logs = array_values($storage->loadMultiple($ids));

        return $logs;
    }

    private function loadEvent(string $eventId): ?McEvent
    {
        if (! array_key_exists($eventId, $this->eventCache)) {
            /** @var McEvent|null $event */
            $event = $this->entityTypeManager->getStorage('mc_event')->load($eventId);
            $this->eventCache[$eventId] = $event;
        }

        return $this->eventCache[$eventId];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildCommitmentPayload(Commitment $commitment): array
    {
        return array_filter([
            'title' => $commitment->get('title'),
            'confidence' => (float) ($commitment->get('confidence') ?? 0.0),
            'status' => $commitment->get('status'),
            'person_id' => $commitment->get('person_id'),
            'person_email' => $commitment->get('person_email'),
            'person_name' => $commitment->get('person_name'),
            'due_date' => $commitment->get('due_date'),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function resolveOccurredAtForEvent(McEvent $event): ?string
    {
        foreach (['occurred', 'created_at'] as $field) {
            $value = $event->get($field);
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    private function resolveOccurredAtForLog(CommitmentExtractionLog $log): ?string
    {
        $value = $log->get('created_at');

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private function resolveSenderForLog(CommitmentExtractionLog $log): ?string
    {
        $sender = $this->resolveSenderFromPayload($log->get('raw_event_payload'));
        if ($sender !== null) {
            return $sender;
        }

        $eventId = $log->get('mc_event_id');
        if ($eventId === null) {
            return null;
        }

        $event = $this->loadEvent((string) $eventId);
        if ($event === null) {
            return null;
        }

        return $this->resolveSenderFromPayload($event->get('payload')) ?? $this->normalizeSender($event->get('from_email'));
    }

    private function resolveSenderFromPayload(mixed $payload): ?string
    {
        if (is_string($payload) && trim($payload) !== '') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $this->extractSenderFromArray($decoded) : null;
        }

        if (is_array($payload)) {
            return $this->extractSenderFromArray($payload);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function extractSenderFromArray(array $payload): ?string
    {
        foreach (['from_email', 'sender_email', 'sender'] as $key) {
            $sender = $this->normalizeSender($payload[$key] ?? null);
            if ($sender !== null) {
                return $sender;
            }
        }

        return null;
    }

    private function normalizeSender(mixed $sender): ?string
    {
        if (! is_string($sender)) {
            return null;
        }

        $sender = strtolower(trim($sender));

        return $sender !== '' ? $sender : null;
    }

    private function normalizePayload(mixed $payload): string
    {
        if (is_string($payload)) {
            return $payload;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    private function normalizeNullablePayload(mixed $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $normalized = $this->normalizePayload($payload);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeFailureCategory(mixed $category): ?string
    {
        if (! is_string($category) || $category === '') {
            return null;
        }

        return in_array($category, CommitmentExtractionLog::FAILURE_CATEGORIES, true) ? $category : 'unknown';
    }

    /**
     * @param array{
     *   mc_event_id: int|string|null,
     *   raw_event_payload: string,
     *   extracted_commitment_payload: string|null,
     *   confidence: float,
     *   failure_category: string|null,
     *   label: string,
     *   occurred_at: string|null,
     *   sender: string|null
     * } $sample
     * @return array<string, mixed>
     */
    private function serializeSample(array $sample): array
    {
        return [
            'mc_event_id' => $sample['mc_event_id'],
            'raw_event_payload' => $sample['raw_event_payload'],
            'extracted_commitment_payload' => $sample['extracted_commitment_payload'],
            'confidence' => $sample['confidence'],
            'failure_category' => $sample['failure_category'],
            'label' => $sample['label'],
            'occurred_at' => $sample['occurred_at'],
            'sender' => $sample['sender'],
        ];
    }

    private function parseDateTime(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
