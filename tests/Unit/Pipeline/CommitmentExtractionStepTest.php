<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Pipeline;

use Claudriel\Ingestion\Pipeline\CommitmentExtractionStep;
use PHPUnit\Framework\TestCase;
use Waaseyaa\AI\Pipeline\PipelineContext;

final class CommitmentExtractionStepTest extends TestCase
{
    public function test_extracts_commitments_from_body(): void
    {
        $aiClient = new class
        {
            public function complete(string $prompt): string
            {
                return json_encode([['title' => 'Send report by Friday', 'confidence' => 0.92]]);
            }
        };
        $step = new CommitmentExtractionStep($aiClient);
        $context = new PipelineContext(pipelineId: 'test', startedAt: time());
        $result = $step->process(
            ['body' => 'Can you send the report by Friday?', 'from_email' => 'jane@example.com'],
            $context
        );
        self::assertTrue($result->success);
        self::assertCount(1, $result->output['commitments']);
        self::assertSame('Send report by Friday', $result->output['commitments'][0]['title']);
        self::assertSame(0.92, $result->output['commitments'][0]['confidence']);
    }

    public function test_returns_empty_for_non_commitment_body(): void
    {
        $aiClient = new class
        {
            public function complete(string $prompt): string
            {
                return json_encode([]);
            }
        };
        $step = new CommitmentExtractionStep($aiClient);
        $context = new PipelineContext(pipelineId: 'test', startedAt: time());
        $result = $step->process(['body' => 'Just saying hi!', 'from_email' => 'jane@example.com'], $context);
        self::assertTrue($result->success);
        self::assertCount(0, $result->output['commitments']);
    }

    public function test_extraction_returns_direction_for_inbound_commitment(): void
    {
        $aiClient = new class
        {
            public function complete(string $prompt): string
            {
                return json_encode([['title' => 'Send the signed contract', 'confidence' => 0.85, 'direction' => 'inbound']]);
            }
        };
        $step = new CommitmentExtractionStep($aiClient);
        $context = new PipelineContext(pipelineId: 'test', startedAt: time());
        $result = $step->process(
            ['body' => 'I will send you the signed contract by Friday.', 'from_email' => 'client@example.com'],
            $context
        );
        self::assertTrue($result->success);
        self::assertCount(1, $result->output['commitments']);
        self::assertArrayHasKey('direction', $result->output['commitments'][0]);
        self::assertSame('inbound', $result->output['commitments'][0]['direction']);
    }

    public function test_fails_on_invalid_json_from_ai_client(): void
    {
        $aiClient = new class
        {
            public function complete(string $prompt): string
            {
                return 'not valid json';
            }
        };
        $step = new CommitmentExtractionStep($aiClient);
        $context = new PipelineContext(pipelineId: 'test', startedAt: time());
        $result = $step->process(['body' => 'Send the report by Friday.', 'from_email' => 'jane@example.com'], $context);
        self::assertFalse($result->success);
        self::assertStringContainsString('invalid JSON', $result->message);
    }
}
