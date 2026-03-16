<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain;

use Claudriel\Domain\IssueInstructionBuilder;
use Claudriel\Entity\IssueRun;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IssueInstructionBuilder::class)]
final class IssueInstructionBuilderTest extends TestCase
{
    private IssueInstructionBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new IssueInstructionBuilder();
    }

    #[Test]
    public function instructionIncludesIssueTitle(): void
    {
        $run = $this->makeRun(['issue_title' => 'Add login page']);
        $result = $this->builder->build($run, $this->makeWorkspace());
        $this->assertStringContainsString('Add login page', $result);
    }

    #[Test]
    public function instructionIncludesIssueBody(): void
    {
        $run = $this->makeRun(['issue_body' => 'We need a login page with OAuth support.']);
        $result = $this->builder->build($run, $this->makeWorkspace());
        $this->assertStringContainsString('We need a login page with OAuth support.', $result);
    }

    #[Test]
    public function instructionIncludesMilestoneContext(): void
    {
        $run = $this->makeRun(['milestone_title' => 'v1.0-alpha']);
        $result = $this->builder->build($run, $this->makeWorkspace());
        $this->assertStringContainsString('**Milestone:** v1.0-alpha', $result);
    }

    #[Test]
    public function instructionIncludesRunUuid(): void
    {
        $run = $this->makeRun(['uuid' => 'abc-123-def']);
        $result = $this->builder->build($run, $this->makeWorkspace());
        $this->assertStringContainsString('## Issue Run: abc-123-def', $result);
    }

    #[Test]
    public function instructionIncludesResumeContext(): void
    {
        $run = $this->makeRun(['last_agent_output' => 'Created 3 files, tests passing.']);
        $result = $this->builder->build($run, $this->makeWorkspace());
        $this->assertStringContainsString('## Previous progress', $result);
        $this->assertStringContainsString('Created 3 files, tests passing.', $result);
    }

    #[Test]
    public function instructionExcludesResumeContextOnFirstRun(): void
    {
        $run = $this->makeRun(['last_agent_output' => null]);
        $result = $this->builder->build($run, $this->makeWorkspace());
        $this->assertStringNotContainsString('Previous progress', $result);
    }

    private function makeRun(array $overrides = []): IssueRun
    {
        return new IssueRun(array_merge([
            'issue_number' => 42,
            'issue_title' => 'Test Issue',
            'issue_body' => 'Test body content.',
            'milestone_title' => 'v1.0',
            'workspace_id' => 1,
        ], $overrides));
    }

    private function makeWorkspace(): Workspace
    {
        return new Workspace([
            'name' => 'test-workspace',
            'repo_path' => '/tmp/test-repo',
            'branch' => 'issue-42',
        ]);
    }
}
