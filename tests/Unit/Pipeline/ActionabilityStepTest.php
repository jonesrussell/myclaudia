<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Pipeline;

use Claudriel\Pipeline\ActionabilityStep;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Pipeline\PipelineContext;

final class ActionabilityStepTest extends TestCase
{
    private ActionabilityStep $step;

    private PipelineContext $context;

    protected function setUp(): void
    {
        $this->step = new ActionabilityStep();
        $this->context = new PipelineContext(pipelineId: 'test', startedAt: time());
    }

    public function test_detects_deadline_keyword(): void
    {
        $result = $this->step->process(['subject' => 'Project deadline tomorrow', 'body' => ''], $this->context);
        self::assertTrue($result->output['is_actionable']);
        self::assertContains('deadline', $result->output['matched_keywords']);
    }

    public function test_detects_action_required_in_body(): void
    {
        $result = $this->step->process(['subject' => 'Update', 'body' => 'Action required: please review'], $this->context);
        self::assertTrue($result->output['is_actionable']);
        self::assertContains('action required', $result->output['matched_keywords']);
        self::assertContains('review', $result->output['matched_keywords']);
    }

    public function test_non_actionable_text(): void
    {
        $result = $this->step->process(['subject' => 'Newsletter', 'body' => 'Here are this weeks updates'], $this->context);
        self::assertFalse($result->output['is_actionable']);
        self::assertEmpty($result->output['matched_keywords']);
    }

    public function test_case_insensitive_matching(): void
    {
        $result = $this->step->process(['subject' => 'URGENT: Review needed', 'body' => ''], $this->context);
        self::assertTrue($result->output['is_actionable']);
        self::assertContains('urgent', $result->output['matched_keywords']);
        self::assertContains('review', $result->output['matched_keywords']);
    }

    public function test_empty_input(): void
    {
        $result = $this->step->process([], $this->context);
        self::assertFalse($result->output['is_actionable']);
        self::assertEmpty($result->output['matched_keywords']);
    }

    public function test_describe(): void
    {
        self::assertNotEmpty($this->step->describe());
    }
}
