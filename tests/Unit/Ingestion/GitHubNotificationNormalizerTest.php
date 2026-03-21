<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ingestion;

use Claudriel\Ingestion\GitHubNotificationNormalizer;
use PHPUnit\Framework\TestCase;

final class GitHubNotificationNormalizerTest extends TestCase
{
    private GitHubNotificationNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new GitHubNotificationNormalizer;
    }

    public function test_normalizer_produces_envelope(): void
    {
        $raw = $this->buildNotification();
        $envelope = $this->normalizer->normalize($raw, 'tenant-1');

        $this->assertSame('github', $envelope->source);
        $this->assertSame('mention', $envelope->type);
        $this->assertSame('tenant-1', $envelope->tenantId);
        $this->assertStringStartsWith('github-', $envelope->traceId);
        $this->assertSame('2026-03-20T10:00:00+00:00', $envelope->timestamp);
    }

    public function test_envelope_payload_contains_notification_fields(): void
    {
        $raw = $this->buildNotification();
        $envelope = $this->normalizer->normalize($raw, 'tenant-1');

        $this->assertSame('jonesrussell/claudriel', $envelope->payload['repo']);
        $this->assertSame('Fix login bug', $envelope->payload['title']);
        $this->assertSame('octocat', $envelope->payload['github_username']);
        $this->assertSame('PullRequest', $envelope->payload['subject_type']);
        $this->assertSame('https://api.github.com/repos/jonesrussell/claudriel/pulls/42', $envelope->payload['subject_url']);
        $this->assertSame('notif-123', $envelope->payload['notification_id']);
    }

    public function test_null_from_email_for_github_users(): void
    {
        $raw = $this->buildNotification();
        $envelope = $this->normalizer->normalize($raw, 'tenant-1');

        $this->assertNull($envelope->payload['from_email']);
        $this->assertSame('octocat', $envelope->payload['from_name']);
    }

    public function test_handles_missing_fields_gracefully(): void
    {
        $raw = ['id' => 'notif-456'];
        $envelope = $this->normalizer->normalize($raw, 'tenant-2');

        $this->assertSame('unknown', $envelope->payload['repo']);
        $this->assertSame('', $envelope->payload['title']);
        $this->assertNull($envelope->payload['github_username']);
        $this->assertNull($envelope->payload['from_name']);
        $this->assertSame('unknown', $envelope->type);
    }

    private function buildNotification(): array
    {
        return [
            'id' => 'notif-123',
            'reason' => 'mention',
            'updated_at' => '2026-03-20T10:00:00+00:00',
            'repository' => ['full_name' => 'jonesrussell/claudriel'],
            'subject' => [
                'title' => 'Fix login bug',
                'type' => 'PullRequest',
                'url' => 'https://api.github.com/repos/jonesrussell/claudriel/pulls/42',
            ],
            'actor' => ['login' => 'octocat'],
        ];
    }
}
