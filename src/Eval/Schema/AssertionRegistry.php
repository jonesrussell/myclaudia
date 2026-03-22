<?php

declare(strict_types=1);

namespace Claudriel\Eval\Schema;

final class AssertionRegistry
{
    /** @var array<string, array{required: list<string>, optional: list<string>, operations: list<string>}> */
    private const TYPES = [
        'field_extraction' => [
            'required' => ['field'],
            'optional' => ['must_not_equal', 'should_match', 'must_not_contain'],
            'operations' => ['create', 'update'],
        ],
        'graphql_operation' => [
            'required' => ['operation'],
            'optional' => ['mutation'],
            'operations' => ['create', 'list', 'update', 'delete'],
        ],
        'confirmation_shown' => [
            'required' => [],
            'optional' => [],
            'operations' => ['create', 'update', 'delete'],
        ],
        'no_file_operations' => [
            'required' => [],
            'optional' => [],
            'operations' => ['create', 'list', 'update', 'delete'],
        ],
        'resolve_first' => [
            'required' => [],
            'optional' => [],
            'operations' => ['update', 'delete'],
        ],
        'error_surfaced' => [
            'required' => [],
            'optional' => ['contains'],
            'operations' => ['create', 'list', 'update', 'delete'],
        ],
        'offers_alternative' => [
            'required' => ['alternative'],
            'optional' => [],
            'operations' => ['update', 'delete'],
        ],
        'disambiguation' => [
            'required' => [],
            'optional' => [],
            'operations' => ['update', 'delete'],
        ],
        'echo_back_required' => [
            'required' => ['field'],
            'optional' => [],
            'operations' => ['delete'],
        ],
        'secondary_intent_queued' => [
            'required' => [],
            'optional' => ['intent'],
            'operations' => ['create', 'list', 'update', 'delete'],
        ],
        'asks_for_field' => [
            'required' => ['field'],
            'optional' => [],
            'operations' => ['create', 'update'],
        ],
        'direction_detected' => [
            'required' => ['direction'],
            'optional' => [],
            'operations' => ['create'],
        ],
        'no_conjunction_split' => [
            'required' => [],
            'optional' => [],
            'operations' => ['create', 'list', 'update', 'delete'],
        ],
        'filter_applied' => [
            'required' => ['field', 'value'],
            'optional' => [],
            'operations' => ['list'],
        ],
        'table_presented' => [
            'required' => ['columns'],
            'optional' => [],
            'operations' => ['list'],
        ],
        'before_after_shown' => [
            'required' => [],
            'optional' => [],
            'operations' => ['update'],
        ],
    ];

    /** @return array{required: list<string>, optional: list<string>, operations: list<string>}|null */
    public static function get(string $type): ?array
    {
        return self::TYPES[$type] ?? null;
    }

    public static function isValidForOperation(string $type, string $operation): bool
    {
        $def = self::TYPES[$type] ?? null;

        return $def !== null && in_array($operation, $def['operations'], true);
    }

    /** @return list<string> */
    public static function allTypes(): array
    {
        return array_keys(self::TYPES);
    }

    /**
     * Validate that an assertion's fields match the registry definition.
     *
     * @param  array<string, mixed>  $fields  The assertion fields (excluding 'type')
     * @return list<string> Error messages (empty = valid)
     */
    public static function validateFields(string $type, array $fields): array
    {
        $def = self::TYPES[$type] ?? null;
        if ($def === null) {
            return ["Unknown assertion type: $type"];
        }

        $errors = [];
        foreach ($def['required'] as $req) {
            if (! array_key_exists($req, $fields)) {
                $errors[] = "Missing required field '$req' for assertion type '$type'";
            }
        }

        $allowed = array_merge($def['required'], $def['optional']);
        foreach (array_keys($fields) as $key) {
            if (! in_array($key, $allowed, true)) {
                $errors[] = "Unknown field '$key' for assertion type '$type'";
            }
        }

        return $errors;
    }
}
