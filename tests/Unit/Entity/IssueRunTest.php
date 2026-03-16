<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Entity;

use Claudriel\Entity\IssueRun;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueRun::class)]
final class IssueRunTest extends TestCase
{
    #[Test]
    public function constructorSetsDefaults(): void
    {
        $run = new IssueRun();
        $this->assertSame('pending', $run->get('status'));
        $this->assertSame('[]', $run->get('event_log'));
    }

    #[Test]
    public function constructorAcceptsValues(): void
    {
        $run = new IssueRun([
            'issue_number' => 42,
            'issue_title' => 'Fix the widget',
            'status' => 'running',
        ]);
        $this->assertSame(42, $run->get('issue_number'));
        $this->assertSame('Fix the widget', $run->get('issue_title'));
        $this->assertSame('running', $run->get('status'));
    }

    #[Test]
    public function entityTypeIdIsCorrect(): void
    {
        $run = new IssueRun();
        $this->assertSame('issue_run', $run->getEntityTypeId());
    }

    #[Test]
    public function labelKeyIsIssueTitle(): void
    {
        $run = new IssueRun(['issue_title' => 'My Issue']);
        $this->assertSame('My Issue', $run->label());
    }

    #[Test]
    public function eventLogDefaultsToEmptyJsonArray(): void
    {
        $run = new IssueRun();
        $decoded = json_decode($run->get('event_log'), true);
        $this->assertSame([], $decoded);
    }
}
