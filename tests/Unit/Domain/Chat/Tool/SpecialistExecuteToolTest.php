<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat\Tool;

use Claudriel\Domain\Chat\Tool\SpecialistExecuteTool;
use PHPUnit\Framework\TestCase;

final class SpecialistExecuteToolTest extends TestCase
{
    public function test_definition_has_required_fields(): void
    {
        $tool = new SpecialistExecuteTool('http://localhost:9999');
        $def = $tool->definition();

        self::assertSame('execute_specialist', $def['name']);
        self::assertArrayHasKey('description', $def);
        self::assertSame(['slug', 'task'], $def['input_schema']['required']);
    }

    public function test_execute_rejects_missing_slug(): void
    {
        $tool = new SpecialistExecuteTool('http://localhost:9999');
        $result = $tool->execute(['slug' => '', 'task' => 'Do something']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('slug', $result['error']);
    }

    public function test_execute_rejects_missing_task(): void
    {
        $tool = new SpecialistExecuteTool('http://localhost:9999');
        $result = $tool->execute(['slug' => 'code-reviewer', 'task' => '']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Task', $result['error']);
    }

    public function test_execute_posts_to_correct_url_with_correct_body(): void
    {
        $capturedUrl = null;
        $capturedBody = null;

        $httpPost = static function (string $url, array $body) use (&$capturedUrl, &$capturedBody): string {
            $capturedUrl = $url;
            $capturedBody = $body;

            return "event: summary\ndata: ".json_encode(['summary' => 'All good', 'metadata' => ['duration' => 1.2]]);
        };

        $tool = new SpecialistExecuteTool('http://agency:3000', $httpPost);
        $tool->execute(['slug' => 'code-reviewer', 'task' => 'Review this PR', 'context' => ['repo' => 'acme/app']]);

        self::assertSame('http://agency:3000/v1/agents/code-reviewer/execute', $capturedUrl);
        self::assertSame('Review this PR', $capturedBody['task']);
        self::assertSame(['repo' => 'acme/app'], $capturedBody['context']);
    }

    public function test_execute_extracts_summary_from_sse_response(): void
    {
        $sseResponse = "event: progress\ndata: {\"step\":1}\n\nevent: summary\ndata: ".json_encode([
            'summary' => 'Code looks clean',
            'metadata' => ['files_reviewed' => 3],
        ]);

        $tool = new SpecialistExecuteTool('http://agency:3000', static fn () => $sseResponse);
        $result = $tool->execute(['slug' => 'code-reviewer', 'task' => 'Review code']);

        self::assertSame('code-reviewer', $result['agent']);
        self::assertSame('Code looks clean', $result['result']);
        self::assertSame(['files_reviewed' => 3], $result['metadata']);
    }

    public function test_execute_returns_error_on_sse_error_event(): void
    {
        $sseResponse = "event: progress\ndata: {\"step\":1}\n\nevent: error\ndata: ".json_encode([
            'message' => 'Agent crashed',
        ]);

        $tool = new SpecialistExecuteTool('http://agency:3000', static fn () => $sseResponse);
        $result = $tool->execute(['slug' => 'code-reviewer', 'task' => 'Review code']);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('Agent crashed', $result['error']);
    }

    public function test_execute_returns_error_on_http_failure(): void
    {
        $tool = new SpecialistExecuteTool('http://agency:3000', static fn () => false);
        $result = $tool->execute(['slug' => 'code-reviewer', 'task' => 'Review code']);

        self::assertSame('Specialist service unavailable', $result['error']);
    }

    public function test_execute_rejects_invalid_slug_format(): void
    {
        $tool = new SpecialistExecuteTool('http://localhost:9999');

        $result = $tool->execute(['slug' => '../admin', 'task' => 'Do something']);
        self::assertSame('Invalid specialist slug format', $result['error']);

        $result = $tool->execute(['slug' => 'UPPERCASE', 'task' => 'Do something']);
        self::assertSame('Invalid specialist slug format', $result['error']);

        $result = $tool->execute(['slug' => 'has spaces', 'task' => 'Do something']);
        self::assertSame('Invalid specialist slug format', $result['error']);
    }

    public function test_execute_accepts_valid_slug_formats(): void
    {
        $tool = new SpecialistExecuteTool('http://agency:3000', static fn () => "event: summary\ndata: {\"summary\":\"ok\"}");

        $result = $tool->execute(['slug' => 'code-reviewer', 'task' => 'Review']);
        self::assertArrayNotHasKey('error', $result);

        $result = $tool->execute(['slug' => '0-starts-with-number', 'task' => 'Review']);
        self::assertArrayNotHasKey('error', $result);
    }

    public function test_execute_handles_multiline_sse_data(): void
    {
        $sseResponse = "event: summary\ndata: {\"summary\":\ndata: \"multi-line result\"}";

        $tool = new SpecialistExecuteTool('http://agency:3000', static fn () => $sseResponse);
        $result = $tool->execute(['slug' => 'code-reviewer', 'task' => 'Review']);

        self::assertSame('code-reviewer', $result['agent']);
        self::assertSame('multi-line result', $result['result']);
    }
}
