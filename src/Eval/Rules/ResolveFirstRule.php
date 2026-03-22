<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;

final class ResolveFirstRule implements EvalRule
{
    /** @return list<ValidationResult> */
    public function validate(array $data, string $file): array
    {
        $results = [];

        foreach ($data['tests'] ?? [] as $test) {
            $operation = $test['operation'] ?? null;
            $testName = $test['name'] ?? '(unnamed)';

            if (! in_array($operation, ['update', 'delete'], true)) {
                continue;
            }

            $existingEntities = $test['context']['existing_entities'] ?? null;
            if ($existingEntities === null || (is_array($existingEntities) && count($existingEntities) === 0)) {
                $results[] = ValidationResult::warning(
                    $file,
                    'ResolveFirstRule',
                    "Test '$testName' ($operation) should have context.existing_entities",
                    $testName,
                );
            }
        }

        return $results;
    }
}
