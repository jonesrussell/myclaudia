<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\AssertionCompatibilityRule;
use PHPUnit\Framework\TestCase;

final class AssertionCompatibilityRuleTest extends TestCase
{
    public function test_compatible_assertion_passes(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'update', 'input' => 'x', 'assertions' => [['type' => 'resolve_first']]],
        ]];

        $results = (new AssertionCompatibilityRule)->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_incompatible_assertion_produces_error(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'resolve_first']]],
        ]];

        $results = (new AssertionCompatibilityRule)->validate($data, 'f.yaml');

        self::assertCount(1, $results);
        self::assertStringContainsString('resolve_first', $results[0]->message);
        self::assertStringContainsString('create', $results[0]->message);
    }
}
