<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Schema;

use Claudriel\Eval\Report\ValidationResult;
use Claudriel\Eval\Schema\EvalFileSchema;
use PHPUnit\Framework\TestCase;

final class EvalFileSchemaTest extends TestCase
{
    private EvalFileSchema $schema;

    protected function setUp(): void
    {
        $this->schema = new EvalFileSchema;
    }

    public function test_valid_file_produces_no_errors(): void
    {
        $data = [
            'schema_version' => '1.0',
            'skill' => 'commitment',
            'entity_type' => 'commitment',
            'tests' => [
                ['name' => 'test-1', 'operation' => 'create', 'input' => 'test', 'assertions' => [['type' => 'confirmation_shown']]],
            ],
        ];

        $results = $this->schema->validate($data, 'commitment/evals/basic.yaml', 'commitment');

        self::assertEmpty($results);
    }

    public function test_missing_schema_version(): void
    {
        $data = ['skill' => 'x', 'entity_type' => 'x', 'tests' => []];

        $results = $this->schema->validate($data, 'f.yaml', 'x');

        self::assertCount(1, array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'schema_version')));
    }

    public function test_wrong_schema_version(): void
    {
        $data = ['schema_version' => '2.0', 'skill' => 'x', 'entity_type' => 'x', 'tests' => [['name' => 'a', 'operation' => 'create', 'input' => 'b', 'assertions' => [['type' => 'confirmation_shown']]]]];

        $results = $this->schema->validate($data, 'f.yaml', 'x');

        self::assertCount(1, array_filter($results, fn (ValidationResult $r) => str_contains($r->message, '1.0')));
    }

    public function test_skill_directory_mismatch(): void
    {
        $data = ['schema_version' => '1.0', 'skill' => 'workspace', 'entity_type' => 'workspace', 'tests' => [['name' => 'a', 'operation' => 'create', 'input' => 'b', 'assertions' => [['type' => 'confirmation_shown']]]]];

        $results = $this->schema->validate($data, 'f.yaml', 'new-workspace');

        self::assertCount(1, array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'directory')));
    }

    public function test_empty_tests_array(): void
    {
        $data = ['schema_version' => '1.0', 'skill' => 'x', 'entity_type' => 'x', 'tests' => []];

        $results = $this->schema->validate($data, 'f.yaml', 'x');

        self::assertCount(1, array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'at least one test')));
    }

    public function test_missing_entity_type(): void
    {
        $data = ['schema_version' => '1.0', 'skill' => 'x', 'tests' => [['name' => 'a', 'operation' => 'create', 'input' => 'b', 'assertions' => [['type' => 'confirmation_shown']]]]];

        $results = $this->schema->validate($data, 'f.yaml', 'x');

        self::assertCount(1, array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'entity_type')));
    }
}
