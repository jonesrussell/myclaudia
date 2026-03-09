<?php
declare(strict_types=1);
namespace Claudriel\Ingestion\Pipeline;
use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\AI\Pipeline\PipelineStepInterface;
use Waaseyaa\AI\Pipeline\StepResult;

final class CommitmentExtractionStep implements PipelineStepInterface
{
    public function __construct(private readonly object $aiClient) {}

    public function process(array $input, PipelineContext $context): StepResult
    {
        $body      = $input['body'] ?? '';
        $fromEmail = $input['from_email'] ?? 'unknown';
        $prompt    = <<<PROMPT
        You are an AI assistant extracting commitments from emails.
        Email body: "{$body}"
        Sender: {$fromEmail}
        Return a JSON array of commitments. Each item: {"title": "...", "confidence": 0.0-1.0}.
        Confidence > 0.7 means you are confident this is a real commitment.
        Return [] if no commitments found. Return only valid JSON, no commentary.
        PROMPT;
        $raw         = $this->aiClient->complete($prompt);
        $commitments = json_decode($raw, true);
        if (!is_array($commitments)) {
            return StepResult::failure('AI client returned invalid JSON for commitment extraction.');
        }
        return StepResult::success(['commitments' => $commitments]);
    }

    public function describe(): string
    {
        return 'Extract commitment candidates from a message body using AI.';
    }
}
