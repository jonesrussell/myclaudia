<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;

final class TagConsistencyRule implements EvalRule
{
    private const KEBAB_CASE_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /** @return list<ValidationResult> */
    public function validate(array $data, string $file): array
    {
        $results = [];

        foreach ($data['tests'] ?? [] as $test) {
            $testName = $test['name'] ?? '(unnamed)';
            foreach ($test['tags'] ?? [] as $tag) {
                if (! is_string($tag) || ! preg_match(self::KEBAB_CASE_PATTERN, $tag)) {
                    $results[] = ValidationResult::warning(
                        $file,
                        'TagConsistencyRule',
                        "Tag '$tag' must be lowercase kebab-case",
                        $testName,
                    );
                }
            }
        }

        return $results;
    }
}
