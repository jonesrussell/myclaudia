<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

use Waaseyaa\Foundation\Ingestion\Envelope;

final class GitHubNotificationNormalizer
{
    public function normalize(array $raw, string $tenantId): Envelope
    {
        $repo = $raw['repository']['full_name'] ?? 'unknown';
        $reason = $raw['reason'] ?? 'unknown';
        $title = $raw['subject']['title'] ?? '';
        $subjectType = $raw['subject']['type'] ?? 'Unknown';
        $subjectUrl = $raw['subject']['url'] ?? '';
        $actor = $raw['actor']['login'] ?? null;
        $updatedAt = $raw['updated_at'] ?? (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM);

        return new Envelope(
            source: 'github',
            type: $reason,
            payload: [
                'notification_id' => $raw['id'] ?? '',
                'repo' => $repo,
                'title' => $title,
                'subject_type' => $subjectType,
                'subject_url' => $subjectUrl,
                'from_name' => $actor,
                'from_email' => null,
                'github_username' => $actor,
                'date' => $updatedAt,
            ],
            timestamp: $updatedAt,
            traceId: uniqid('github-', true),
            tenantId: $tenantId,
        );
    }
}
