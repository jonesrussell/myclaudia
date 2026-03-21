<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Ingestion;

use Claudriel\Ingestion\EventCategorizer;
use Claudriel\Support\AutomatedSenderDetector;
use PHPUnit\Framework\TestCase;

final class EventCategorizerGitHubTest extends TestCase
{
    private EventCategorizer $categorizer;

    protected function setUp(): void
    {
        $this->categorizer = new EventCategorizer(new AutomatedSenderDetector);
    }

    public function test_mention_categorizes_as_github_mention(): void
    {
        $result = $this->categorizer->categorize('github', 'mention');
        $this->assertSame('github_mention', $result);
    }

    public function test_review_requested_categorizes_as_github_review_request(): void
    {
        $result = $this->categorizer->categorize('github', 'review_requested');
        $this->assertSame('github_review_request', $result);
    }

    public function test_assign_categorizes_as_github_assignment(): void
    {
        $result = $this->categorizer->categorize('github', 'assign');
        $this->assertSame('github_assignment', $result);
    }

    public function test_ci_activity_categorizes_as_github_ci(): void
    {
        $result = $this->categorizer->categorize('github', 'ci_activity');
        $this->assertSame('github_ci', $result);
    }

    public function test_state_change_categorizes_as_github_activity(): void
    {
        $result = $this->categorizer->categorize('github', 'state_change');
        $this->assertSame('github_activity', $result);
    }

    public function test_unknown_reason_categorizes_as_github_activity(): void
    {
        $result = $this->categorizer->categorize('github', 'some_unknown_reason');
        $this->assertSame('github_activity', $result);
    }
}
