<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Support\OAuthTokenManagerInterface;

final class CalendarCreateTool implements AgentToolInterface
{
    use GoogleApiTrait;

    public function __construct(
        private readonly OAuthTokenManagerInterface $tokenManager,
        private readonly string $accountId,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'calendar_create',
            'description' => 'Create a new calendar event.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'title' => [
                        'type' => 'string',
                        'description' => 'Event title',
                    ],
                    'start_time' => [
                        'type' => 'string',
                        'description' => 'Start time in ISO 8601 format (e.g., 2026-03-18T09:00:00-04:00)',
                    ],
                    'end_time' => [
                        'type' => 'string',
                        'description' => 'End time in ISO 8601 format',
                    ],
                    'description' => [
                        'type' => 'string',
                        'description' => 'Optional event description',
                    ],
                    'attendees' => [
                        'type' => 'string',
                        'description' => 'Comma-separated email addresses of attendees (optional)',
                    ],
                ],
                'required' => ['title', 'start_time', 'end_time'],
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

        $eventData = [
            'summary' => $args['title'] ?? '',
            'start' => ['dateTime' => $args['start_time'] ?? ''],
            'end' => ['dateTime' => $args['end_time'] ?? ''],
        ];

        $description = $args['description'] ?? '';
        if ($description !== '') {
            $eventData['description'] = $description;
        }

        $attendees = $args['attendees'] ?? '';
        if ($attendees !== '') {
            $eventData['attendees'] = array_map(
                static fn (string $email) => ['email' => trim($email)],
                explode(',', $attendees),
            );
        }

        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';

        return $this->googleApiPost($url, $accessToken, $eventData);
    }
}
