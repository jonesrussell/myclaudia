<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;
use Claudriel\Eval\Schema\AssertionRegistry;

final class AssertionCompatibilityRule implements EvalRule
{
    /** @return list<ValidationResult> */
    public function validate(array $data, string $file): array
    {
        $results = [];

        foreach ($data['tests'] ?? [] as $test) {
            $operation = $test['operation'] ?? null;
            $testName = $test['name'] ?? '(unnamed)';

            if ($operation === null) {
                continue;
            }

            foreach ($test['assertions'] ?? [] as $assertion) {
                if (! is_array($assertion) || ! isset($assertion['type'])) {
                    continue;
                }

                $type = $assertion['type'];
                if (AssertionRegistry::get($type) !== null && ! AssertionRegistry::isValidForOperation($type, $operation)) {
                    $results[] = ValidationResult::error(
                        $file,
                        'AssertionCompatibilityRule',
                        "Assertion type '$type' is not valid for operation '$operation'",
                        $testName,
                    );
                }
            }
        }

        return $results;
    }
}
