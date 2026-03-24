<?php

declare(strict_types=1);

namespace Claudriel\Pipeline;

use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\AI\Pipeline\PipelineStepInterface;
use Waaseyaa\AI\Pipeline\StepResult;

/**
 * AI-driven lead filter and qualification in a single pass.
 *
 * Input keys: title, description, sector, allowed_sectors, company_profile
 * Output keys: relevant, reject_reason, rating, keywords, sector, confidence, summary
 */
final class LeadFilterStep implements PipelineStepInterface
{
    public function __construct(private readonly object $aiClient) {}

    public function process(array $input, PipelineContext $context): StepResult
    {
        $title = (string) ($input['title'] ?? '');
        $description = substr((string) ($input['description'] ?? ''), 0, 2000);
        $sector = (string) ($input['sector'] ?? '');
        $allowedSectors = $input['allowed_sectors'] ?? [];
        $companyProfile = (string) ($input['company_profile'] ?? '');

        $sectorList = is_array($allowedSectors) && $allowedSectors !== []
            ? implode(', ', $allowedSectors)
            : 'IT, Networks, Security, Cloud, Telecom, Software, Infrastructure';

        $prompt = <<<PROMPT
        You are evaluating an RFP lead for relevance and quality.

        Company profile: {$companyProfile}
        Relevant sectors: {$sectorList}

        Lead title: {$title}
        Lead sector: {$sector}
        Lead description (first 2000 chars): {$description}

        Respond in JSON with exactly these fields:
        {
          "relevant": true/false,
          "reject_reason": "reason if not relevant, null otherwise",
          "rating": 0-100,
          "keywords": ["keyword1", "keyword2"],
          "sector": "canonical sector name",
          "confidence": 0.0-1.0,
          "summary": "one sentence summary"
        }
        PROMPT;

        $raw = trim($this->aiClient->complete($prompt));
        if ($raw === '') {
            return StepResult::failure('AI client returned empty response for lead filter.');
        }

        $result = json_decode($raw, true);
        if (! is_array($result)) {
            // Try to extract JSON from markdown code blocks
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $raw, $matches)) {
                $result = json_decode($matches[1], true);
            }
        }

        if (! is_array($result)) {
            return StepResult::failure('Failed to parse AI response as JSON.');
        }

        return StepResult::success([
            'relevant' => (bool) ($result['relevant'] ?? true),
            'reject_reason' => $result['reject_reason'] ?? null,
            'rating' => (int) ($result['rating'] ?? 0),
            'keywords' => is_array($result['keywords'] ?? null) ? $result['keywords'] : [],
            'sector' => (string) ($result['sector'] ?? $sector),
            'confidence' => (float) ($result['confidence'] ?? 0.0),
            'summary' => (string) ($result['summary'] ?? ''),
        ]);
    }

    public function describe(): string
    {
        return 'AI-driven lead relevance filter and qualification in a single pass.';
    }
}
