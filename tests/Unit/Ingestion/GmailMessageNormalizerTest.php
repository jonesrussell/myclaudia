<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ingestion;

use Claudriel\Ingestion\GmailMessageNormalizer;
use PHPUnit\Framework\TestCase;

final class GmailMessageNormalizerTest extends TestCase
{
    public function testNormalizesGmailMessage(): void
    {
        $raw = [
            'id'       => 'msg123',
            'threadId' => 'thread456',
            'payload'  => [
                'headers' => [
                    ['name' => 'From',    'value' => 'Jane <jane@example.com>'],
                    ['name' => 'Subject', 'value' => 'Quick question'],
                    ['name' => 'Date',    'value' => 'Sun, 08 Mar 2026 09:00:00 +0000'],
                ],
                'body' => ['data' => base64_encode('Can you send the report by Friday?')],
            ],
        ];

        $normalizer = new GmailMessageNormalizer();
        $envelope   = $normalizer->normalize($raw, tenantId: 'user-1');

        self::assertSame('gmail', $envelope->source);
        self::assertSame('message.received', $envelope->type);
        self::assertSame('msg123', $envelope->payload['message_id']);
        self::assertSame('jane@example.com', $envelope->payload['from_email']);
        self::assertSame('Quick question', $envelope->payload['subject']);
        self::assertSame('Can you send the report by Friday?', $envelope->payload['body']);
        self::assertSame('user-1', $envelope->tenantId);
    }
}
