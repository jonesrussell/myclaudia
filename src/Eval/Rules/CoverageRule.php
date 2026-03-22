<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;

final class CoverageRule implements CrossFileRule
{
    private const REQUIRED_OPERATIONS = ['create', 'list', 'update', 'delete'];

    /** @return list<ValidationResult> */
    public function validate(array $allFilesBySkill): array
    {
        $results = [];

        foreach ($allFilesBySkill as $skill => $files) {
            $operations = [];
            $hasErrorHandling = false;
            $hasEdgeCase = false;

            foreach ($files as $fileData) {
                foreach ($fileData['tests'] ?? [] as $test) {
                    if (isset($test['operation'])) {
                        $operations[$test['operation']] = true;
                    }

                    // Check for error-handling coverage
                    $tags = $test['tags'] ?? [];
                    if (in_array('error-handling', $tags, true)) {
                        $hasErrorHandling = true;
                    }
                    foreach ($test['assertions'] ?? [] as $assertion) {
                        if (is_array($assertion) && ($assertion['type'] ?? null) === 'error_surfaced') {
                            $hasErrorHandling = true;
                        }
                    }

                    // Check for edge-case coverage
                    if (in_array('edge-case', $tags, true) || in_array('regression', $tags, true)) {
                        $hasEdgeCase = true;
                    }
                }
            }

            // Check operation coverage
            foreach (self::REQUIRED_OPERATIONS as $op) {
                if (! isset($operations[$op])) {
                    $results[] = ValidationResult::error(
                        "(cross-file:$skill)",
                        'CoverageRule',
                        "Skill '$skill' missing operation coverage: $op",
                    );
                }
            }

            // Check error-handling coverage
            if (! $hasErrorHandling) {
                $results[] = ValidationResult::warning(
                    "(cross-file:$skill)",
                    'CoverageRule',
                    "Skill '$skill' has no error-handling tests (tag 'error-handling' or assertion 'error_surfaced')",
                );
            }

            // Check edge-case coverage
            if (! $hasEdgeCase) {
                $results[] = ValidationResult::warning(
                    "(cross-file:$skill)",
                    'CoverageRule',
                    "Skill '$skill' has no edge-case tests (tag 'edge-case' or 'regression')",
                );
            }
        }

        return $results;
    }
}
