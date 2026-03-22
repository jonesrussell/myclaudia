<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Report;

use Claudriel\Eval\Report\JsonReporter;
use Claudriel\Eval\Report\ValidationResult;
use PHPUnit\Framework\TestCase;

final class JsonReporterTest extends TestCase
{
    public function test_passing_report_structure(): void
    {
        $reporter = new JsonReporter;
        $results = [
            ValidationResult::warning('f.yaml', 'TagConsistencyRule', 'Unknown tag'),
        ];
        $coverage = [
            'commitment' => ['create', 'list', 'update', 'delete'],
        ];

        $json = $reporter->render($results, filesScanned: 1, testsScanned: 10, skillsCovered: ['commitment'], operationCoverage: $coverage);
        $data = json_decode($json, true);

        self::assertSame('1.0', $data['schema_version']);
        self::assertSame('pass', $data['status']);
        self::assertSame(0, $data['summary']['errors']);
        self::assertSame(1, $data['summary']['warnings']);
        self::assertSame(1, $data['summary']['files_scanned']);
        self::assertSame(10, $data['summary']['tests_scanned']);
        self::assertCount(1, $data['results']);
    }

    public function test_failing_report_when_errors_present(): void
    {
        $reporter = new JsonReporter;
        $results = [
            ValidationResult::error('f.yaml', 'TestCaseSchema', 'Missing field'),
        ];

        $json = $reporter->render($results, filesScanned: 1, testsScanned: 5, skillsCovered: ['x'], operationCoverage: []);
        $data = json_decode($json, true);

        self::assertSame('fail', $data['status']);
        self::assertSame(1, $data['summary']['errors']);
    }
}
