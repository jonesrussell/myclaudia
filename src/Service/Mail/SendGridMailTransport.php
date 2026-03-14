<?php

declare(strict_types=1);

namespace Claudriel\Service\Mail;

final class SendGridMailTransport implements MailTransportInterface
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $fromEmail,
        private readonly string $fromName,
        private readonly ?MailTransportInterface $fallback = null,
    ) {}

    public function send(array $message): array
    {
        if (trim($this->apiKey) === '') {
            return $this->fallback?->send($message) ?? [
                'transport' => 'sendgrid',
                'status' => 'skipped',
            ];
        }

        $payload = [
            'personalizations' => [[
                'to' => [[
                    'email' => (string) ($message['to_email'] ?? ''),
                    'name' => (string) ($message['to_name'] ?? ''),
                ]],
            ]],
            'from' => [
                'email' => $this->fromEmail,
                'name' => $this->fromName,
            ],
            'subject' => (string) ($message['subject'] ?? ''),
            'content' => [[
                'type' => 'text/plain',
                'value' => (string) ($message['text'] ?? ''),
            ]],
        ];

        $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer '.$this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            throw new \RuntimeException($error !== '' ? $error : 'SendGrid delivery failed.');
        }

        return [
            'transport' => 'sendgrid',
            'status' => 'sent',
            'status_code' => $status,
        ];
    }
}
