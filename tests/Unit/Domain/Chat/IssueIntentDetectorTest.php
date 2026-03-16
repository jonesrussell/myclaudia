<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\IssueIntentDetector;
use Claudriel\Domain\Chat\OrchestratorIntent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueIntentDetector::class)]
#[CoversClass(OrchestratorIntent::class)]
final class IssueIntentDetectorTest extends TestCase
{
    #[Test]
    public function detectRunIssueIntent(): void
    {
        $intent = IssueIntentDetector::detect('run issue #123');
        self::assertNotNull($intent);
        self::assertSame('run_issue', $intent->action);
        self::assertSame(123, $intent->params['issueNumber']);
    }

    #[Test]
    public function detectWorkOnIssueVariant(): void
    {
        $intent = IssueIntentDetector::detect('work on issue #45');
        self::assertNotNull($intent);
        self::assertSame('run_issue', $intent->action);
        self::assertSame(45, $intent->params['issueNumber']);
    }

    #[Test]
    public function detectStartIssueVariant(): void
    {
        $intent = IssueIntentDetector::detect('start issue #7');
        self::assertNotNull($intent);
        self::assertSame('run_issue', $intent->action);
        self::assertSame(7, $intent->params['issueNumber']);
    }

    #[Test]
    public function detectShowRunIntent(): void
    {
        $intent = IssueIntentDetector::detect('show run abc-123-def');
        self::assertNotNull($intent);
        self::assertSame('show_run', $intent->action);
        self::assertSame('abc-123-def', $intent->params['runId']);
    }

    #[Test]
    public function detectStatusOfRunVariant(): void
    {
        $intent = IssueIntentDetector::detect('status of run abc-123');
        self::assertNotNull($intent);
        self::assertSame('show_run', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detectListRunsIntent(): void
    {
        $intent = IssueIntentDetector::detect('list runs');
        self::assertNotNull($intent);
        self::assertSame('list_runs', $intent->action);
        self::assertSame([], $intent->params);
    }

    #[Test]
    public function detectShowAllRunsVariant(): void
    {
        $intent = IssueIntentDetector::detect('show all runs');
        self::assertNotNull($intent);
        self::assertSame('list_runs', $intent->action);
        self::assertSame([], $intent->params);
    }

    #[Test]
    public function detectActiveRunsVariant(): void
    {
        $intent = IssueIntentDetector::detect('active runs');
        self::assertNotNull($intent);
        self::assertSame('list_runs', $intent->action);
        self::assertSame([], $intent->params);
    }

    #[Test]
    public function detectShowDiffIntent(): void
    {
        $intent = IssueIntentDetector::detect('diff for run abc-123');
        self::assertNotNull($intent);
        self::assertSame('show_diff', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detectShowDiffVariant(): void
    {
        $intent = IssueIntentDetector::detect('show diff abc-123');
        self::assertNotNull($intent);
        self::assertSame('show_diff', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detectPauseRunIntent(): void
    {
        $intent = IssueIntentDetector::detect('pause run abc-123');
        self::assertNotNull($intent);
        self::assertSame('pause_run', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detectResumeRunIntent(): void
    {
        $intent = IssueIntentDetector::detect('resume run abc-123');
        self::assertNotNull($intent);
        self::assertSame('resume_run', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function detectAbortRunIntent(): void
    {
        $intent = IssueIntentDetector::detect('abort run abc-123');
        self::assertNotNull($intent);
        self::assertSame('abort_run', $intent->action);
        self::assertSame('abc-123', $intent->params['runId']);
    }

    #[Test]
    public function unrecognizedMessageReturnsNull(): void
    {
        self::assertNull(IssueIntentDetector::detect('hello, how are you?'));
        self::assertNull(IssueIntentDetector::detect('what is the weather like?'));
        self::assertNull(IssueIntentDetector::detect('tell me about issue 123'));
    }

    #[Test]
    public function caseInsensitiveDetection(): void
    {
        $intent = IssueIntentDetector::detect('Run Issue #123');
        self::assertNotNull($intent);
        self::assertSame('run_issue', $intent->action);
        self::assertSame(123, $intent->params['issueNumber']);
    }
}
