<?php

declare(strict_types=1);

namespace Claudriel\Ingestion;

use Claudriel\Support\AutomatedSenderDetector;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class EventCategorizer
{
    private const JOB_KEYWORDS = [
        'application', 'interview', 'job', 'position', 'hiring',
        'recruiter', 'resume', 'offer', 'salary', 'applied',
    ];

    public function __construct(
        private readonly AutomatedSenderDetector $automatedDetector = new AutomatedSenderDetector,
        private readonly ?EntityRepositoryInterface $personRepo = null,
    ) {}

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

        if ($source === 'github') {
            return $this->categorizeGitHub($type);
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
     * Three-tier Gmail categorization:
     * 1. Job keywords → job_hunt (highest priority)
     * 2. Automated sender → notification
     * 3. Known person (exists in personRepo) → people
     * 4. Unknown sender → triage
     *
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

        $fromEmail = $payload['from_email'] ?? '';
        $fromName = $payload['from_name'] ?? '';

        if ($fromEmail !== '' && $this->automatedDetector->isAutomated($fromEmail, $fromName)) {
            return 'notification';
        }

        if ($this->personRepo !== null && $fromEmail !== '') {
            $existing = $this->personRepo->findBy(['email' => $fromEmail]);
            if ($existing !== []) {
                return 'people';
            }
        }

        return 'triage';
    }

    private function categorizeGitHub(string $type): string
    {
        return match ($type) {
            'mention' => 'github_mention',
            'review_requested' => 'github_review_request',
            'assign' => 'github_assignment',
            'ci_activity' => 'github_ci',
            default => 'github_activity',
        };
    }
}
