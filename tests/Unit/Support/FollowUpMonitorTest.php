<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Entity\McEvent;
use Claudriel\Support\FollowUpMonitor;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class FollowUpMonitorTest extends TestCase
{
    private function makeRepo(): EntityRepository
    {
        return new EntityRepository(
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
    }

    public function test_finds_sent_emails_without_replies(): void
    {
        $repo = $this->makeRepo();
        $monitor = new FollowUpMonitor($repo);

        // thread-1: sent 4 days ago, no reply — should be returned
        $sentAt1 = (new \DateTimeImmutable('-4 days'))->format(\DateTimeInterface::ATOM);
        $repo->save(new McEvent([
            'eid' => 1,
            'type' => 'message.sent',
            'tenant_id' => 'user-1',
            'occurred' => $sentAt1,
            'payload' => json_encode([
                'thread_id' => 'thread-1',
                'subject' => 'Proposal for you',
                'to_email' => 'alice@example.com',
            ]),
        ]));

        // thread-2: sent 4 days ago, but has a reply — should NOT be returned
        $sentAt2 = (new \DateTimeImmutable('-4 days'))->format(\DateTimeInterface::ATOM);
        $repo->save(new McEvent([
            'eid' => 2,
            'type' => 'message.sent',
            'tenant_id' => 'user-1',
            'occurred' => $sentAt2,
            'payload' => json_encode([
                'thread_id' => 'thread-2',
                'subject' => 'Follow up',
                'to_email' => 'bob@example.com',
            ]),
        ]));
        $repo->save(new McEvent([
            'eid' => 3,
            'type' => 'message.received',
            'tenant_id' => 'user-1',
            'occurred' => (new \DateTimeImmutable('-2 days'))->format(\DateTimeInterface::ATOM),
            'payload' => json_encode([
                'thread_id' => 'thread-2',
                'subject' => 'Re: Follow up',
            ]),
        ]));

        $result = $monitor->findUnanswered('user-1', daysThreshold: 3);

        self::assertCount(1, $result);
        self::assertSame('thread-1', $result[0]['thread_id']);
        self::assertSame('Proposal for you', $result[0]['subject']);
        self::assertSame($sentAt1, $result[0]['sent_at']);
        self::assertSame('alice@example.com', $result[0]['recipient']);
    }

    public function test_ignores_recent_sent_emails(): void
    {
        $repo = $this->makeRepo();
        $monitor = new FollowUpMonitor($repo);

        // sent only 1 day ago, threshold is 2 days — too recent, should not appear
        $repo->save(new McEvent([
            'eid' => 4,
            'type' => 'message.sent',
            'tenant_id' => 'user-1',
            'occurred' => (new \DateTimeImmutable('-1 day'))->format(\DateTimeInterface::ATOM),
            'payload' => json_encode([
                'thread_id' => 'thread-3',
                'subject' => 'Recent email',
                'to_email' => 'carol@example.com',
            ]),
        ]));

        $result = $monitor->findUnanswered('user-1', daysThreshold: 2);

        self::assertCount(0, $result);
    }
}
