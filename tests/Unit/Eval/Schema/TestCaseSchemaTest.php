<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Schema;

use Claudriel\Eval\Report\ValidationResult;
use Claudriel\Eval\Schema\TestCaseSchema;
use PHPUnit\Framework\TestCase;

final class TestCaseSchemaTest extends TestCase
{
    private TestCaseSchema $schema;

    protected function setUp(): void
    {
        $this->schema = new TestCaseSchema;
    }

    public function test_valid_test_case_produces_no_errors(): void
    {
        $test = [
            'name' => 'create-basic',
            'operation' => 'create',
            'input' => 'create a workspace for Acme',
            'assertions' => [['type' => 'confirmation_shown']],
        ];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_missing_name(): void
    {
        $test = ['operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'name')));
    }

    public function test_invalid_name_format(): void
    {
        $test = ['name' => 'Create_Basic', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'kebab-case')));
    }

    public function test_invalid_operation(): void
    {
        $test = ['name' => 'test-1', 'operation' => 'upsert', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'operation')));
    }

    public function test_empty_assertions(): void
    {
        $test = ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => []];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'assertion')));
    }

    public function test_assertion_missing_type(): void
    {
        $test = ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['field' => 'name']]];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'type')));
    }

    public function test_unknown_assertion_type(): void
    {
        $test = ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'bogus']]];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertNotEmpty(array_filter($results, fn (ValidationResult $r) => str_contains($r->message, 'bogus')));
    }

    public function test_valid_tags(): void
    {
        $test = ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']], 'tags' => ['happy-path', 'regression']];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_valid_context_with_existing_entities(): void
    {
        $test = [
            'name' => 'update-basic',
            'operation' => 'update',
            'input' => 'rename it',
            'context' => [
                'existing_entities' => [
                    ['uuid' => 'abc-123', 'fields' => ['name' => 'Old Name']],
                ],
            ],
            'assertions' => [['type' => 'resolve_first']],
        ];

        $results = $this->schema->validate($test, 'f.yaml');

        self::assertEmpty($results);
    }
}
