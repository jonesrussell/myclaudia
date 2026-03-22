<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\UniqueNameRule;
use PHPUnit\Framework\TestCase;

final class UniqueNameRuleTest extends TestCase
{
    public function test_unique_names_pass(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
            ['name' => 'test-2', 'operation' => 'list', 'input' => 'y', 'assertions' => [['type' => 'confirmation_shown']]],
        ]];

        $results = (new UniqueNameRule)->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_duplicate_names_produce_error(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
            ['name' => 'test-1', 'operation' => 'list', 'input' => 'y', 'assertions' => [['type' => 'confirmation_shown']]],
        ]];

        $results = (new UniqueNameRule)->validate($data, 'f.yaml');

        self::assertCount(1, $results);
        self::assertStringContainsString('test-1', $results[0]->message);
    }
}
