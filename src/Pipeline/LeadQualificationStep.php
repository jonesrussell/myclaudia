<?php

declare(strict_types=1);

namespace Claudriel\Pipeline;

use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\AI\Pipeline\PipelineStepInterface;
use Waaseyaa\AI\Pipeline\StepResult;

/**
 * AI-driven lead qualification.
 *
 * Input keys: title, description, sector, company_profile
 * Output keys: rating, keywords, sector, confidence, summary
 */
final class LeadQualificationStep implements PipelineStepInterface
{
    public function __construct(private readonly object $aiClient) {}

    public function process(array $input, PipelineContext $context): StepResult
    {
        $title = (string) ($input['title'] ?? '');
        $description = substr((string) ($input['description'] ?? ''), 0, 2000);
        $sector = (string) ($input['sector'] ?? '');
        $companyProfile = (string) ($input['company_profile'] ?? '');

        $prompt = <<<PROMPT
        You are qualifying an RFP lead for a company.

        Company profile: {$companyProfile}

        Lead title: {$title}
        Lead sector: {$sector}
        Lead description (first 2000 chars): {$description}

        Rate this lead and respond in JSON with exactly these fields:
        {
          "rating": 0-100,
          "keywords": ["keyword1", "keyword2"],
          "sector": "canonical sector name",
          "confidence": 0.0-1.0,
          "summary": "one sentence summary"
        }
        PROMPT;

        $raw = trim($this->aiClient->complete($prompt));
        if ($raw === '') {
            return StepResult::failure('AI client returned empty response for lead qualification.');
        }

        $result = json_decode($raw, true);
        if (! is_array($result)) {
            if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $raw, $matches)) {
                $result = json_decode($matches[1], true);
            }
        }

        if (! is_array($result)) {
            return StepResult::failure('Failed to parse AI qualification response as JSON.');
        }

        return StepResult::success([
            'rating' => (int) ($result['rating'] ?? 0),
            'keywords' => is_array($result['keywords'] ?? null) ? $result['keywords'] : [],
            'sector' => (string) ($result['sector'] ?? $sector),
            'confidence' => (float) ($result['confidence'] ?? 0.0),
            'summary' => (string) ($result['summary'] ?? ''),
        ]);
    }

    public function describe(): string
    {
        return 'AI-driven lead qualification with rating, keywords, and sector classification.';
    }
}
