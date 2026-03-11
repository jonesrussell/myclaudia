<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Pipeline;

use Claudriel\Pipeline\WorkspaceClassificationStep;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Pipeline\PipelineContext;

final class WorkspaceClassificationStepTest extends TestCase
{
    private function makeContext(): PipelineContext
    {
        return new PipelineContext(pipelineId: 'test', startedAt: time());
    }

    private function workspaces(): array
    {
        return [
            ['uuid' => 'abc-123', 'name' => 'Acme Corp', 'description' => 'Client work for Acme Corporation'],
            ['uuid' => 'def-456', 'name' => 'Personal', 'description' => 'Personal tasks and notes'],
        ];
    }

    public function test_classifies_event_into_matching_workspace(): void
    {
        $aiClient = new class
        {
            public function complete(string $prompt): string
            {
                return 'Acme Corp';
            }
        };

        $step = new WorkspaceClassificationStep($aiClient);
        $result = $step->process([
            'workspaces' => $this->workspaces(),
            'source' => 'gmail',
            'type' => 'email',
            'subject' => 'Project update for Acme',
            'body' => 'Here is the latest update on the Acme project.',
        ], $this->makeContext());

        self::assertTrue($result->success);
        self::assertSame('abc-123', $result->output['workspace_id']);
    }

    public function test_returns_null_when_no_workspaces_exist(): void
    {
        $aiClient = new class
        {
            public function complete(string $prompt): string
            {
                return 'Acme Corp';
            }
        };

        $step = new WorkspaceClassificationStep($aiClient);
        $result = $step->process([
            'workspaces' => [],
            'source' => 'gmail',
            'type' => 'email',
            'subject' => 'Hello',
            'body' => 'Some body text.',
        ], $this->makeContext());

        self::assertTrue($result->success);
        self::assertNull($result->output['workspace_id']);
    }

    public function test_returns_null_when_ai_says_none(): void
    {
        $aiClient = new class
        {
            public function complete(string $prompt): string
            {
                return 'none';
            }
        };

        $step = new WorkspaceClassificationStep($aiClient);
        $result = $step->process([
            'workspaces' => $this->workspaces(),
            'source' => 'gmail',
            'type' => 'email',
            'subject' => 'Random newsletter',
            'body' => 'Unrelated content.',
        ], $this->makeContext());

        self::assertTrue($result->success);
        self::assertNull($result->output['workspace_id']);
    }

    public function test_fails_on_empty_ai_response(): void
    {
        $aiClient = new class
        {
            public function complete(string $prompt): string
            {
                return '';
            }
        };

        $step = new WorkspaceClassificationStep($aiClient);
        $result = $step->process([
            'workspaces' => $this->workspaces(),
            'source' => 'gmail',
            'type' => 'email',
            'subject' => 'Test',
            'body' => 'Test body.',
        ], $this->makeContext());

        self::assertFalse($result->success);
        self::assertStringContainsString('empty response', $result->message);
    }
}
