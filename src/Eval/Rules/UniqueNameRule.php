<?php

declare(strict_types=1);

namespace Claudriel\Eval\Rules;

use Claudriel\Eval\Report\ValidationResult;

final class UniqueNameRule implements EvalRule
{
    /** @return list<ValidationResult> */
    public function validate(array $data, string $file): array
    {
        $results = [];
        $seen = [];

        foreach ($data['tests'] ?? [] as $test) {
            $name = $test['name'] ?? null;
            if ($name === null) {
                continue;
            }
            if (isset($seen[$name])) {
                $results[] = ValidationResult::error($file, 'UniqueNameRule', "Duplicate test name: '$name'", $name);
            }
            $seen[$name] = true;
        }

        return $results;
    }
}
