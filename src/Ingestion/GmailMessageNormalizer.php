<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

use Waaseyaa\Foundation\Ingestion\Envelope;

final class GmailMessageNormalizer
{
    public function normalize(array $raw, string $tenantId): Envelope
    {
        $headers = [];
        foreach ($raw['payload']['headers'] ?? [] as $header) {
            $headers[strtolower($header['name'])] = $header['value'];
        }

        $fromRaw   = $headers['from'] ?? '';
        $fromEmail = $this->extractEmail($fromRaw);
        $body      = base64_decode(strtr($raw['payload']['body']['data'] ?? '', '-_', '+/'));

        return new Envelope(
            source:    'gmail',
            type:      'message.received',
            payload:   [
                'message_id' => $raw['id'],
                'thread_id'  => $raw['threadId'],
                'from_email' => $fromEmail,
                'from_name'  => $this->extractName($fromRaw),
                'subject'    => $headers['subject'] ?? '(no subject)',
                'date'       => $headers['date'] ?? '',
                'body'       => $body,
            ],
            timestamp: (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            traceId:   uniqid('gmail-', true),
            tenantId:  $tenantId,
        );
    }

    private function extractEmail(string $from): string
    {
        if (preg_match('/<([^>]+)>/', $from, $m)) {
            return $m[1];
        }

        return trim($from);
    }

    private function extractName(string $from): string
    {
        if (preg_match('/^(.+?)\s*</', $from, $m)) {
            return trim($m[1]);
        }

        return '';
    }
}
