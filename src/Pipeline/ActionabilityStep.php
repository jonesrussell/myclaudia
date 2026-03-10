<?php

declare(strict_types=1);

namespace Claudriel\Pipeline;

use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\AI\Pipeline\PipelineStepInterface;
use Waaseyaa\AI\Pipeline\StepResult;

final class ActionabilityStep implements PipelineStepInterface
{
    private const KEYWORDS = [
        'deadline', 'due', 'rsvp', 'confirm', 'action required',
        'respond', 'review', 'approve', 'submit', 'expires', 'urgent',
    ];

    public function process(array $input, PipelineContext $context): StepResult
    {
        $text = strtolower(
            ($input['subject'] ?? '') . ' ' . ($input['body'] ?? '')
        );

        $matched = [];
        foreach (self::KEYWORDS as $keyword) {
            if (str_contains($text, $keyword)) {
                $matched[] = $keyword;
            }
        }

        return StepResult::success([
            'is_actionable' => $matched !== [],
            'matched_keywords' => $matched,
        ]);
    }

    public function describe(): string
    {
        return 'Detect actionability via keyword matching on event text.';
    }
}
