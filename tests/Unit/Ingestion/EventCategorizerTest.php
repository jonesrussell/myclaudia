<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ingestion;

use Claudriel\Ingestion\EventCategorizer;
use PHPUnit\Framework\TestCase;

final class EventCategorizerTest extends TestCase
{
    private EventCategorizer $categorizer;

    protected function setUp(): void
    {
        $this->categorizer = new EventCategorizer;
    }

    public function test_calendar_event_categorized_as_schedule(): void
    {
        $result = $this->categorizer->categorize('google-calendar', 'calendar.event', ['title' => 'Team standup']);
        $this->assertSame('schedule', $result);
    }

    public function test_calendar_event_with_job_keyword_categorized_as_job_hunt(): void
    {
        $result = $this->categorizer->categorize('google-calendar', 'calendar.event', ['title' => 'Interview with Acme Corp']);
        $this->assertSame('job_hunt', $result);
    }

    public function test_gmail_with_job_subject_categorized_as_job_hunt(): void
    {
        $result = $this->categorizer->categorize('gmail', 'message.received', ['subject' => 'Your application was received']);
        $this->assertSame('job_hunt', $result);
    }

    public function test_gmail_with_job_body_categorized_as_job_hunt(): void
    {
        $result = $this->categorizer->categorize('gmail', 'message.received', ['subject' => 'Hello', 'body' => 'We have a position for you']);
        $this->assertSame('job_hunt', $result);
    }

    public function test_gmail_without_job_keywords_categorized_as_people(): void
    {
        $result = $this->categorizer->categorize('gmail', 'message.received', ['subject' => 'Lunch tomorrow?', 'body' => 'Want to grab lunch?']);
        $this->assertSame('people', $result);
    }

    public function test_unknown_source_categorized_as_notification(): void
    {
        $result = $this->categorizer->categorize('webhook', 'alert', []);
        $this->assertSame('notification', $result);
    }

    public function test_categorization_is_case_insensitive(): void
    {
        $result = $this->categorizer->categorize('gmail', 'message.received', ['subject' => 'JOB APPLICATION Update']);
        $this->assertSame('job_hunt', $result);
    }
}
