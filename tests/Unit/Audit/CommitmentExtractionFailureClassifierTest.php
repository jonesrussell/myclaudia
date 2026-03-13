<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Audit;

use Claudriel\Service\Audit\CommitmentExtractionFailureClassifier;
use PHPUnit\Framework\TestCase;

final class CommitmentExtractionFailureClassifierTest extends TestCase
{
    private CommitmentExtractionFailureClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new CommitmentExtractionFailureClassifier;
    }

    public function test_returns_model_parse_error_when_commitment_is_missing(): void
    {
        self::assertSame(
            'model_parse_error',
            $this->classifier->classify(['subject' => 'Broken response'], null, 0.4),
        );
    }

    public function test_returns_ambiguous_for_very_low_confidence_results(): void
    {
        self::assertSame(
            'ambiguous',
            $this->classifier->classify(
                ['subject' => 'Maybe something'],
                ['title' => 'Reply about this', 'person_email' => 'alex@example.com', 'due_date' => '2026-03-15'],
                0.2,
            ),
        );
    }

    public function test_returns_insufficient_context_when_action_lacks_person_or_date(): void
    {
        self::assertSame(
            'insufficient_context',
            $this->classifier->classify(
                ['subject' => 'Need follow-up'],
                ['title' => 'Send the draft'],
                0.55,
            ),
        );
    }

    public function test_returns_non_actionable_when_text_has_no_actionable_verb(): void
    {
        self::assertSame(
            'non_actionable',
            $this->classifier->classify(
                ['subject' => 'Status update'],
                ['title' => 'Team sync recap', 'person_email' => 'alex@example.com', 'due_date' => '2026-03-15'],
                0.58,
            ),
        );
    }

    public function test_returns_unknown_when_result_has_context_but_no_other_rule_matches(): void
    {
        self::assertSame(
            'unknown',
            $this->classifier->classify(
                ['subject' => 'Please follow up'],
                ['title' => 'Follow up with Alex', 'person_email' => 'alex@example.com', 'due_date' => '2026-03-15'],
                0.65,
            ),
        );
    }
}
