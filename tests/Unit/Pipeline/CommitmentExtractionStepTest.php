<?php
declare(strict_types=1);
namespace MyClaudia\Tests\Unit\Pipeline;
use MyClaudia\Ingestion\Pipeline\CommitmentExtractionStep;
use Waaseyaa\AI\Pipeline\PipelineContext;
use PHPUnit\Framework\TestCase;

final class CommitmentExtractionStepTest extends TestCase
{
    public function testExtractsCommitmentsFromBody(): void
    {
        $aiClient = new class {
            public function complete(string $prompt): string
            {
                return json_encode([['title' => 'Send report by Friday', 'confidence' => 0.92]]);
            }
        };
        $step    = new CommitmentExtractionStep($aiClient);
        $context = new PipelineContext(pipelineId: 'test', startedAt: time());
        $result  = $step->process(
            ['body' => 'Can you send the report by Friday?', 'from_email' => 'jane@example.com'],
            $context
        );
        self::assertTrue($result->success);
        self::assertCount(1, $result->output['commitments']);
        self::assertSame('Send report by Friday', $result->output['commitments'][0]['title']);
        self::assertSame(0.92, $result->output['commitments'][0]['confidence']);
    }

    public function testReturnsEmptyForNonCommitmentBody(): void
    {
        $aiClient = new class {
            public function complete(string $prompt): string { return json_encode([]); }
        };
        $step    = new CommitmentExtractionStep($aiClient);
        $context = new PipelineContext(pipelineId: 'test', startedAt: time());
        $result  = $step->process(['body' => 'Just saying hi!', 'from_email' => 'jane@example.com'], $context);
        self::assertTrue($result->success);
        self::assertCount(0, $result->output['commitments']);
    }

    public function testFailsOnInvalidJsonFromAiClient(): void
    {
        $aiClient = new class {
            public function complete(string $prompt): string { return 'not valid json'; }
        };
        $step    = new CommitmentExtractionStep($aiClient);
        $context = new PipelineContext(pipelineId: 'test', startedAt: time());
        $result  = $step->process(['body' => 'Send the report by Friday.', 'from_email' => 'jane@example.com'], $context);
        self::assertFalse($result->success);
        self::assertStringContainsString('invalid JSON', $result->message);
    }
}
