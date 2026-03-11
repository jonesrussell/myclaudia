<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Support;

use Claudriel\Support\AutomatedSenderDetector;
use PHPUnit\Framework\TestCase;

final class AutomatedSenderDetectorTest extends TestCase
{
    private AutomatedSenderDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new AutomatedSenderDetector;
    }

    public function test_detects_noreply_prefix(): void
    {
        self::assertTrue($this->detector->isAutomated('noreply@example.com'));
    }

    public function test_detects_no_reply_prefix(): void
    {
        self::assertTrue($this->detector->isAutomated('no-reply@example.com'));
    }

    public function test_detects_notifications_prefix(): void
    {
        self::assertTrue($this->detector->isAutomated('notifications@example.com'));
    }

    public function test_detects_mailer_daemon_prefix(): void
    {
        self::assertTrue($this->detector->isAutomated('mailer-daemon@example.com'));
    }

    public function test_detects_alerts_prefix(): void
    {
        self::assertTrue($this->detector->isAutomated('alerts@example.com'));
    }

    public function test_detects_newsletter_prefix(): void
    {
        self::assertTrue($this->detector->isAutomated('newsletter@example.com'));
    }

    public function test_detects_github_domain(): void
    {
        self::assertTrue($this->detector->isAutomated('user@github.com'));
    }

    public function test_detects_stripe_domain(): void
    {
        self::assertTrue($this->detector->isAutomated('receipts@stripe.com'));
    }

    public function test_detects_linkedin_domain(): void
    {
        self::assertTrue($this->detector->isAutomated('someone@linkedin.com'));
    }

    public function test_regular_email_is_not_automated(): void
    {
        self::assertFalse($this->detector->isAutomated('jane@example.com'));
    }

    public function test_personal_domain_is_not_automated(): void
    {
        self::assertFalse($this->detector->isAutomated('chris@company.org'));
    }

    public function test_detection_is_case_insensitive(): void
    {
        self::assertTrue($this->detector->isAutomated('NoReply@Example.COM'));
    }

    public function test_noreply_at_github_matches_both_prefix_and_domain(): void
    {
        self::assertTrue($this->detector->isAutomated('noreply@github.com'));
    }
}
