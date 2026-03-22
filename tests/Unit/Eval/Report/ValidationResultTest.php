<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Report;

use Claudriel\Eval\Report\ValidationResult;
use PHPUnit\Framework\TestCase;

final class ValidationResultTest extends TestCase
{
    public function test_error_result_exposes_all_fields(): void
    {
        $result = ValidationResult::error(
            file: 'skills/workspace/evals/basic.yaml',
            rule: 'TestCaseSchema',
            message: 'Missing required field: input',
            test: 'create-basic',
        );

        self::assertSame('skills/workspace/evals/basic.yaml', $result->file);
        self::assertSame('create-basic', $result->test);
        self::assertSame('error', $result->severity);
        self::assertSame('TestCaseSchema', $result->rule);
        self::assertSame('Missing required field: input', $result->message);
    }

    public function test_warning_result_with_null_test(): void
    {
        $result = ValidationResult::warning(
            file: 'skills/workspace/evals/basic.yaml',
            rule: 'TagConsistencyRule',
            message: 'Unknown tag: experimental',
        );

        self::assertSame('warning', $result->severity);
        self::assertNull($result->test);
    }

    public function test_is_error_returns_true_for_errors(): void
    {
        $error = ValidationResult::error('f', 'r', 'm');
        $warning = ValidationResult::warning('f', 'r', 'm');

        self::assertTrue($error->isError());
        self::assertFalse($warning->isError());
    }

    public function test_to_array_produces_expected_shape(): void
    {
        $result = ValidationResult::error('f.yaml', 'Rule', 'msg', 'test-1');

        $array = $result->toArray();

        self::assertSame([
            'file' => 'f.yaml',
            'severity' => 'error',
            'rule' => 'Rule',
            'test' => 'test-1',
            'message' => 'msg',
        ], $array);
    }
}
