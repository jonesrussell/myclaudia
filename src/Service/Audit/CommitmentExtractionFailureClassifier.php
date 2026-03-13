<?php

declare(strict_types=1);

namespace Claudriel\Service\Audit;

use Claudriel\Entity\CommitmentExtractionLog;

final class CommitmentExtractionFailureClassifier
{
    private const ACTIONABLE_VERBS = [
        'call',
        'confirm',
        'deliver',
        'draft',
        'email',
        'follow',
        'meet',
        'prepare',
        'reply',
        'review',
        'schedule',
        'send',
        'share',
        'submit',
        'update',
    ];

    /**
     * @param  array<string, mixed>  $mcEvent
     * @param  array<string, mixed>|null  $extractedCommitment
     */
    public function classify(array $mcEvent, ?array $extractedCommitment, float $confidence): string
    {
        if ($extractedCommitment === null || $this->isEmptyCommitment($extractedCommitment)) {
            return 'model_parse_error';
        }

        if ($confidence < 0.3) {
            return 'ambiguous';
        }

        $text = $this->extractText($extractedCommitment);
        if ($text !== '' && $this->isMissingContext($extractedCommitment)) {
            return 'insufficient_context';
        }

        if ($text !== '' && ! $this->containsActionableVerb($text)) {
            return 'non_actionable';
        }

        return in_array($this->inferFallbackCategory($mcEvent, $extractedCommitment), CommitmentExtractionLog::FAILURE_CATEGORIES, true)
            ? $this->inferFallbackCategory($mcEvent, $extractedCommitment)
            : 'unknown';
    }

    /**
     * @param  array<string, mixed>  $extractedCommitment
     */
    private function isEmptyCommitment(array $extractedCommitment): bool
    {
        $fields = array_diff_key($extractedCommitment, ['confidence' => true]);
        foreach ($fields as $value) {
            if (is_string($value) && trim($value) !== '') {
                return false;
            }

            if (is_array($value) && $value !== []) {
                return false;
            }

            if ($value !== null && ! is_string($value) && ! is_array($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $extractedCommitment
     */
    private function extractText(array $extractedCommitment): string
    {
        foreach (['title', 'action', 'summary', 'text', 'description'] as $key) {
            $value = $extractedCommitment[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $extractedCommitment
     */
    private function isMissingContext(array $extractedCommitment): bool
    {
        $hasDate = $this->hasAnyField($extractedCommitment, ['date', 'due_date', 'deadline', 'scheduled_for']);
        $hasPerson = $this->hasAnyField($extractedCommitment, ['person', 'person_id', 'person_email', 'person_name', 'assignee']);

        return ! $hasDate || ! $hasPerson;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     */
    private function hasAnyField(array $payload, array $fields): bool
    {
        foreach ($fields as $field) {
            $value = $payload[$field] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return true;
            }

            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
    }

    private function containsActionableVerb(string $text): bool
    {
        $normalized = strtolower($text);
        foreach (self::ACTIONABLE_VERBS as $verb) {
            if (preg_match('/\b'.preg_quote($verb, '/').'\b/', $normalized) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $mcEvent
     * @param  array<string, mixed>  $extractedCommitment
     */
    private function inferFallbackCategory(array $mcEvent, array $extractedCommitment): string
    {
        return 'unknown';
    }
}
