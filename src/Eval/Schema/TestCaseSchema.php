<?php

declare(strict_types=1);

namespace Claudriel\Eval\Schema;

use Claudriel\Eval\Report\ValidationResult;

final class TestCaseSchema
{
    private const VALID_OPERATIONS = ['create', 'list', 'update', 'delete'];

    private const REQUIRED_FIELDS = ['name', 'operation', 'input', 'assertions'];

    private const KEBAB_CASE_PATTERN = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    /**
     * @param  array<string, mixed>  $test
     * @return list<ValidationResult>
     */
    public function validate(array $test, string $file): array
    {
        $results = [];
        $testName = $test['name'] ?? '(unnamed)';

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $test)) {
                $results[] = ValidationResult::error($file, 'TestCaseSchema', "Missing required field: $field", $testName);
            }
        }

        if (isset($test['name']) && is_string($test['name']) && ! preg_match(self::KEBAB_CASE_PATTERN, $test['name'])) {
            $results[] = ValidationResult::error($file, 'TestCaseSchema', "Test name '{$test['name']}' must be kebab-case", $testName);
        }

        if (isset($test['operation']) && ! in_array($test['operation'], self::VALID_OPERATIONS, true)) {
            $results[] = ValidationResult::error($file, 'TestCaseSchema', "Invalid operation '{$test['operation']}', must be one of: ".implode(', ', self::VALID_OPERATIONS), $testName);
        }

        if (isset($test['assertions'])) {
            if (! is_array($test['assertions']) || count($test['assertions']) === 0) {
                $results[] = ValidationResult::error($file, 'TestCaseSchema', 'Must have at least one assertion', $testName);
            } else {
                foreach ($test['assertions'] as $i => $assertion) {
                    if (! is_array($assertion)) {
                        $results[] = ValidationResult::error($file, 'TestCaseSchema', "Assertion #$i must be an object", $testName);

                        continue;
                    }
                    if (! isset($assertion['type'])) {
                        $results[] = ValidationResult::error($file, 'TestCaseSchema', "Assertion #$i missing required field: type", $testName);

                        continue;
                    }
                    $def = AssertionRegistry::get($assertion['type']);
                    if ($def === null) {
                        $results[] = ValidationResult::error($file, 'TestCaseSchema', "Unknown assertion type: {$assertion['type']}", $testName);

                        continue;
                    }
                    $fields = array_diff_key($assertion, ['type' => true]);
                    foreach (AssertionRegistry::validateFields($assertion['type'], $fields) as $fieldError) {
                        $results[] = ValidationResult::error($file, 'TestCaseSchema', $fieldError, $testName);
                    }
                }
            }
        }

        if (isset($test['tags']) && is_array($test['tags'])) {
            foreach ($test['tags'] as $tag) {
                if (! is_string($tag) || ! preg_match(self::KEBAB_CASE_PATTERN, $tag)) {
                    $results[] = ValidationResult::warning($file, 'TestCaseSchema', "Tag '$tag' must be lowercase kebab-case", $testName);
                }
            }
        }

        return $results;
    }
}
