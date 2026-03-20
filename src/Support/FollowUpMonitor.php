<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class FollowUpMonitor
{
    private const DEFAULT_DAYS_THRESHOLD = 3;

    public function __construct(
        private readonly EntityRepositoryInterface $eventRepo,
    ) {}

    /**
     * Find sent emails older than $daysThreshold days that have no reply in their thread.
     *
     * @return list<array{thread_id: string, subject: string, sent_at: string, recipient: string}>
     */
    public function findUnanswered(string $tenantId, int $daysThreshold = self::DEFAULT_DAYS_THRESHOLD): array
    {
        $cutoff = (new \DateTimeImmutable("-{$daysThreshold} days"))->format(\DateTimeInterface::ATOM);

        // Load all events and filter in memory (same pattern as DriftDetector)
        /** @var ContentEntityInterface[] $all */
        $all = $this->eventRepo->findBy([]);

        $sent = array_filter($all, static fn (ContentEntityInterface $e) =>
            $e->get('type') === 'message.sent' && $e->get('tenant_id') === $tenantId);

        $received = array_filter($all, static fn (ContentEntityInterface $e) =>
            $e->get('type') === 'message.received' && $e->get('tenant_id') === $tenantId);

        // Build set of thread IDs that have replies
        $repliedThreads = [];
        foreach ($received as $event) {
            $payload = json_decode($event->get('payload') ?? '{}', true);
            $threadId = $payload['thread_id'] ?? null;
            if ($threadId !== null) {
                $repliedThreads[$threadId] = true;
            }
        }

        // Filter: older than cutoff + no reply in thread
        $unanswered = [];
        foreach ($sent as $event) {
            $payload = json_decode($event->get('payload') ?? '{}', true);
            $threadId = $payload['thread_id'] ?? null;
            $sentAt = $event->get('occurred') ?? '';

            if ($threadId === null || $sentAt > $cutoff) {
                continue;
            }
            if (isset($repliedThreads[$threadId])) {
                continue;
            }

            $unanswered[] = [
                'thread_id' => $threadId,
                'subject' => $payload['subject'] ?? '(no subject)',
                'sent_at' => $sentAt,
                'recipient' => $payload['to_email'] ?? $payload['to'] ?? '',
            ];
        }

        return $unanswered;
    }
}
