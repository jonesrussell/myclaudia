<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

final class EventCategorizer
{
    private const JOB_KEYWORDS = [
        'application', 'interview', 'job', 'position', 'hiring',
        'recruiter', 'resume', 'offer', 'salary', 'applied',
    ];

    public function __construct() {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function categorize(string $source, string $type, array $payload = []): string
    {
        if ($source === 'google-calendar') {
            return $this->categorizeCalendar($payload);
        }

        if ($source === 'gmail') {
            return $this->categorizeGmail($type, $payload);
        }

        return 'notification';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function categorizeCalendar(array $payload): string
    {
        $title = strtolower($payload['title'] ?? $payload['subject'] ?? '');
        foreach (self::JOB_KEYWORDS as $keyword) {
            if (str_contains($title, $keyword)) {
                return 'job_hunt';
            }
        }

        return 'schedule';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function categorizeGmail(string $type, array $payload): string
    {
        $subject = strtolower($payload['subject'] ?? '');
        $body = strtolower($payload['body'] ?? '');
        $combined = $subject.' '.$body;

        foreach (self::JOB_KEYWORDS as $keyword) {
            if (str_contains($combined, $keyword)) {
                return 'job_hunt';
            }
        }

        return 'people';
    }
}
