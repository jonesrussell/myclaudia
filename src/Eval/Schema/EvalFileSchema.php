<?php

declare(strict_types=1);

namespace Claudriel\Eval\Schema;

use Claudriel\Eval\Report\ValidationResult;

final class EvalFileSchema
{
    private const REQUIRED_FIELDS = ['schema_version', 'skill', 'entity_type', 'tests'];

    /**
     * Validate top-level file structure.
     *
     * @param  array<string, mixed>  $data  Parsed YAML data
     * @param  string  $file  File path (for error reporting)
     * @param  string  $skillDir  The parent directory name of the eval file
     * @return list<ValidationResult>
     */
    public function validate(array $data, string $file, string $skillDir): array
    {
        $results = [];

        foreach (self::REQUIRED_FIELDS as $field) {
            if (! array_key_exists($field, $data)) {
                $results[] = ValidationResult::error($file, 'EvalFileSchema', "Missing required top-level field: $field");
            }
        }

        if (isset($data['schema_version']) && $data['schema_version'] !== '1.0') {
            $results[] = ValidationResult::error($file, 'EvalFileSchema', "schema_version must be '1.0', got '{$data['schema_version']}'");
        }

        if (isset($data['skill']) && $data['skill'] !== $skillDir) {
            $results[] = ValidationResult::error($file, 'EvalFileSchema', "skill field '{$data['skill']}' does not match directory '$skillDir'");
        }

        if (isset($data['tests']) && is_array($data['tests']) && count($data['tests']) === 0) {
            $results[] = ValidationResult::error($file, 'EvalFileSchema', 'tests array must contain at least one test');
        }

        return $results;
    }
}
