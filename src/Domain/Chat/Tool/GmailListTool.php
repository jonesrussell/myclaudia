<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Support\OAuthTokenManagerInterface;

final class GmailListTool implements AgentToolInterface
{
    use GoogleApiTrait;

    public function __construct(
        private readonly OAuthTokenManagerInterface $tokenManager,
        private readonly string $accountId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'gmail_list',
            'description' => 'List Gmail messages matching a query.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Gmail search query (default: is:unread)',
                    ],
                    'max_results' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of messages to return (default: 10)',
                    ],
                ],
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

        $q = $args['query'] ?? 'is:unread';
        $maxResults = min((int) ($args['max_results'] ?? 10), 50);

        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages?'
            .http_build_query(['q' => $q, 'maxResults' => $maxResults]);

        return $this->googleApiGet($url, $accessToken);
    }
}
