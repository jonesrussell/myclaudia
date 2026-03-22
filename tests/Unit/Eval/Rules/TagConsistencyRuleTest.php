<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Rules;

use Claudriel\Eval\Rules\TagConsistencyRule;
use PHPUnit\Framework\TestCase;

final class TagConsistencyRuleTest extends TestCase
{
    public function test_valid_tags_pass(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'tags' => ['happy-path', 'regression']],
        ]];

        $results = (new TagConsistencyRule)->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }

    public function test_invalid_tag_produces_warning(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'tags' => ['Happy_Path']],
        ]];

        $results = (new TagConsistencyRule)->validate($data, 'f.yaml');

        self::assertCount(1, $results);
        self::assertSame('warning', $results[0]->severity);
    }

    public function test_no_tags_is_fine(): void
    {
        $data = ['tests' => [
            ['name' => 'test-1', 'operation' => 'create'],
        ]];

        $results = (new TagConsistencyRule)->validate($data, 'f.yaml');

        self::assertEmpty($results);
    }
}
