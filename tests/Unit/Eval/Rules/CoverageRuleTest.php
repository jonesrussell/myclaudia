<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\CoverageRule;
use PHPUnit\Framework\TestCase;

final class CoverageRuleTest extends TestCase
{
    public function test_full_coverage_passes(): void
    {
        $allFiles = [
            'commitment' => [
                ['tests' => [
                    ['name' => 'c', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                    ['name' => 'l', 'operation' => 'list', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                    ['name' => 'u', 'operation' => 'update', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                    ['name' => 'd', 'operation' => 'delete', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                    ['name' => 'err', 'operation' => 'create', 'input' => 'x', 'tags' => ['error-handling'], 'assertions' => [['type' => 'error_surfaced']]],
                    ['name' => 'edge', 'operation' => 'create', 'input' => 'x', 'tags' => ['edge-case'], 'assertions' => [['type' => 'confirmation_shown']]],
                ]],
            ],
        ];

        $results = (new CoverageRule)->validate($allFiles);

        self::assertEmpty($results);
    }

    public function test_missing_operation_produces_error(): void
    {
        $allFiles = [
            'commitment' => [
                ['tests' => [
                    ['name' => 'c', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                    ['name' => 'l', 'operation' => 'list', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
                ]],
            ],
        ];

        $results = (new CoverageRule)->validate($allFiles);

        // 2 missing operations (update, delete) + 2 warnings (error-handling, edge-case)
        self::assertCount(4, $results);
        $errors = array_filter($results, fn ($r) => $r->isError());
        self::assertCount(2, $errors);
    }
}
