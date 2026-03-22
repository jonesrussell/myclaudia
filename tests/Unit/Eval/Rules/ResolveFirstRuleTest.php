<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\ResolveFirstRule;
use PHPUnit\Framework\TestCase;

final class ResolveFirstRuleTest extends TestCase
{
    public function test_update_with_existing_entities_passes(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'update', 'input' => 'x', 'context' => ['existing_entities' => [['uuid' => 'a', 'fields' => ['name' => 'X']]]], 'assertions' => [['type' => 'resolve_first']]],
        ]];

        $results = (new ResolveFirstRule)->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_update_without_context_produces_warning(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'update', 'input' => 'x', 'assertions' => [['type' => 'resolve_first']]],
        ]];

        $results = (new ResolveFirstRule)->validate($data, 'f.yaml');

        self::assertCount(1, $results);
        self::assertSame('warning', $results[0]->severity);
    }

    public function test_create_without_context_is_fine(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'create', 'input' => 'x', 'assertions' => [['type' => 'confirmation_shown']]],
        ]];

        $results = (new ResolveFirstRule)->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }
}
