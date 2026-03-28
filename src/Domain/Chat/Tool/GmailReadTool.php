<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Support\OAuthTokenManagerInterface;

final class GmailReadTool implements AgentToolInterface
{
    use GoogleApiTrait;

    public function __construct(
        private readonly OAuthTokenManagerInterface $tokenManager,
        private readonly string $accountId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'gmail_read',
            'description' => 'Read a specific Gmail message by ID.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'message_id' => [
                        'type' => 'string',
                        'description' => 'The Gmail message ID to read',
                    ],
                ],
                'required' => ['message_id'],
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

        $messageId = $args['message_id'] ?? '';
        if ($messageId === '') {
            return ['error' => 'Message ID required'];
        }

        $url = "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}?format=full";

        return $this->googleApiGet($url, $accessToken);
    }
}
