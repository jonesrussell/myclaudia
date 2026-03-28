<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Support\OAuthTokenManagerInterface;

final class CalendarListTool implements AgentToolInterface
{
    use GoogleApiTrait;

    public function __construct(
        private readonly OAuthTokenManagerInterface $tokenManager,
        private readonly string $accountId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'calendar_list',
            'description' => 'List upcoming calendar events.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'days_ahead' => [
                        'type' => 'integer',
                        'description' => 'Number of days to look ahead (default: 7)',
                    ],
                    'max_results' => [
                        'type' => 'integer',
                        'description' => 'Maximum number of events to return (default: 20)',
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

        $daysAhead = min((int) ($args['days_ahead'] ?? 7), 30);
        $maxResults = min((int) ($args['max_results'] ?? 20), 100);

        $timeMin = (new \DateTimeImmutable)->format(\DateTimeInterface::RFC3339);
        $timeMax = (new \DateTimeImmutable("+{$daysAhead} days"))->format(\DateTimeInterface::RFC3339);

        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events?'
            .http_build_query([
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
                'maxResults' => $maxResults,
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
            ]);

        return $this->googleApiGet($url, $accessToken);
    }
}
