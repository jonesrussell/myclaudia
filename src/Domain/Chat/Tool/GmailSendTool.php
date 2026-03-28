<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Support\OAuthTokenManagerInterface;

final class GmailSendTool implements AgentToolInterface
{
    use GoogleApiTrait;

    public function __construct(
        private readonly OAuthTokenManagerInterface $tokenManager,
        private readonly string $accountId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'gmail_send',
            'description' => 'Send an email or reply to an existing message.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'to' => [
                        'type' => 'string',
                        'description' => 'Recipient email address',
                    ],
                    'subject' => [
                        'type' => 'string',
                        'description' => 'Email subject line',
                    ],
                    'body' => [
                        'type' => 'string',
                        'description' => 'Email body text',
                    ],
                ],
                'required' => ['to', 'subject', 'body'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        try {
            $accessToken = $this->tokenManager->getValidAccessToken($this->accountId);
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }

        $to = $args['to'] ?? '';
        $subject = $args['subject'] ?? '';
        $bodyText = $args['body'] ?? '';

        if ($to === '' || $subject === '') {
            return ['error' => 'to and subject are required'];
        }

        if (preg_match('/[\r\n]/', $to) || preg_match('/[\r\n]/', $subject)) {
            return ['error' => 'Invalid characters in to or subject'];
        }

        $rawMessage = "To: {$to}\r\nSubject: {$subject}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$bodyText}";
        $encoded = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';

        return $this->googleApiPost($url, $accessToken, ['raw' => $encoded]);
    }
}
