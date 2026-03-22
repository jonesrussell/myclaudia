<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Schema;

use Claudriel\Eval\Schema\AssertionRegistry;
use PHPUnit\Framework\TestCase;

final class AssertionRegistryTest extends TestCase
{
    public function test_known_type_returns_definition(): void
    {
        $def = AssertionRegistry::get('field_extraction');

        self::assertNotNull($def);
        self::assertContains('field', $def['required']);
        self::assertContains('must_not_contain', $def['optional']);
    }

    public function test_unknown_type_returns_null(): void
    {
        self::assertNull(AssertionRegistry::get('nonexistent_type'));
    }

    public function test_is_valid_for_operation_checks_compatibility(): void
    {
        self::assertTrue(AssertionRegistry::isValidForOperation('resolve_first', 'update'));
        self::assertTrue(AssertionRegistry::isValidForOperation('resolve_first', 'delete'));
        self::assertFalse(AssertionRegistry::isValidForOperation('resolve_first', 'create'));
        self::assertFalse(AssertionRegistry::isValidForOperation('resolve_first', 'list'));
    }

    public function test_all_types_returns_complete_list(): void
    {
        $types = AssertionRegistry::allTypes();

        self::assertContains('field_extraction', $types);
        self::assertContains('graphql_operation', $types);
        self::assertContains('confirmation_shown', $types);
        self::assertContains('no_file_operations', $types);
        self::assertContains('resolve_first', $types);
        self::assertContains('error_surfaced', $types);
        self::assertContains('offers_alternative', $types);
        self::assertContains('disambiguation', $types);
        self::assertContains('echo_back_required', $types);
        self::assertContains('secondary_intent_queued', $types);
        self::assertContains('asks_for_field', $types);
        self::assertContains('direction_detected', $types);
        self::assertContains('no_conjunction_split', $types);
        self::assertContains('filter_applied', $types);
        self::assertContains('table_presented', $types);
        self::assertContains('before_after_shown', $types);
        self::assertCount(16, $types);
    }

    public function test_graphql_operation_valid_for_all_operations(): void
    {
        foreach (['create', 'list', 'update', 'delete'] as $op) {
            self::assertTrue(
                AssertionRegistry::isValidForOperation('graphql_operation', $op),
                "graphql_operation should be valid for $op",
            );
        }
    }

    public function test_validate_fields_catches_missing_required(): void
    {
        $errors = AssertionRegistry::validateFields('field_extraction', []);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('field', $errors[0]);
    }

    public function test_validate_fields_passes_with_required_present(): void
    {
        $errors = AssertionRegistry::validateFields('field_extraction', ['field' => 'name']);

        self::assertEmpty($errors);
    }

    public function test_validate_fields_ignores_optional_fields(): void
    {
        $errors = AssertionRegistry::validateFields('field_extraction', [
            'field' => 'name',
            'must_not_equal' => 'full sentence',
            'must_not_contain' => ['filler'],
        ]);

        self::assertEmpty($errors);
    }

    public function test_validate_fields_catches_unknown_fields(): void
    {
        $errors = AssertionRegistry::validateFields('field_extraction', [
            'field' => 'name',
            'bogus_field' => 'value',
        ]);

        self::assertNotEmpty($errors);
        self::assertStringContainsString('bogus_field', $errors[0]);
    }
}
