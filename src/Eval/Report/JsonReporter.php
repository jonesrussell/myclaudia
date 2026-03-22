<?php

declare(strict_types=1);

namespace Claudriel\Eval\Report;

final class JsonReporter
{
    /**
     * @param  list<ValidationResult>  $results
     * @param  list<string>  $skillsCovered
     * @param  array<string, list<string>>  $operationCoverage
     */
    public function render(
        array $results,
        int $filesScanned,
        int $testsScanned,
        array $skillsCovered,
        array $operationCoverage,
    ): string {
        $errors = count(array_filter($results, fn (ValidationResult $r) => $r->isError()));
        $warnings = count($results) - $errors;

        $report = [
            'schema_version' => '1.0',
            'timestamp' => date('c'),
            'status' => $errors > 0 ? 'fail' : 'pass',
            'summary' => [
                'files_scanned' => $filesScanned,
                'tests_scanned' => $testsScanned,
                'errors' => $errors,
                'warnings' => $warnings,
                'skills_covered' => $skillsCovered,
                'operation_coverage' => $operationCoverage,
            ],
            'results' => array_map(fn (ValidationResult $r) => $r->toArray(), $results),
        ];

        return json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    }
}
