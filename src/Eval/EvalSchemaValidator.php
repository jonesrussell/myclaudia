<?php

declare(strict_types=1);

namespace Claudriel\Eval;

use Claudriel\Eval\Report\JsonReporter;
use Claudriel\Eval\Report\ValidationResult;
use Claudriel\Eval\Rules\AssertionCompatibilityRule;
use Claudriel\Eval\Rules\CoverageRule;
use Claudriel\Eval\Rules\CrossFileRule;
use Claudriel\Eval\Rules\EvalRule;
use Claudriel\Eval\Rules\ResolveFirstRule;
use Claudriel\Eval\Rules\TagConsistencyRule;
use Claudriel\Eval\Rules\UniqueNameRule;
use Claudriel\Eval\Schema\EvalFileSchema;
use Claudriel\Eval\Schema\TestCaseSchema;
use Symfony\Component\Yaml\Yaml;

final class EvalSchemaValidator
{
    private readonly EvalFileSchema $fileSchema;

    private readonly TestCaseSchema $testCaseSchema;

    /** @var list<EvalRule> */
    private readonly array $fileRules;

    /** @var list<CrossFileRule> */
    private readonly array $crossFileRules;

    private readonly JsonReporter $reporter;

    public function __construct(
        private readonly string $skillsBasePath,
    ) {
        $this->fileSchema = new EvalFileSchema;
        $this->testCaseSchema = new TestCaseSchema;
        $this->fileRules = [
            new UniqueNameRule,
            new AssertionCompatibilityRule,
            new ResolveFirstRule,
            new TagConsistencyRule,
        ];
        $this->crossFileRules = [
            new CoverageRule,
        ];
        $this->reporter = new JsonReporter;
    }

    /**
     * @return array<string, mixed> The parsed JSON report
     */
    public function validate(?string $skillFilter = null, bool $strict = false): array
    {
        $results = [];
        $filesScanned = 0;
        $testsScanned = 0;
        $skillsCovered = [];
        $operationCoverage = [];
        $allFilesBySkill = [];

        $pattern = $skillFilter !== null
            ? $this->skillsBasePath."/$skillFilter/evals/*.yaml"
            : $this->skillsBasePath.'/*/evals/*.yaml';

        $files = glob($pattern) ?: [];

        foreach ($files as $filePath) {
            $parsed = Yaml::parseFile($filePath);
            if (! is_array($parsed) || ! isset($parsed['schema_version']) || $parsed['schema_version'] !== '1.0') {
                continue;
            }

            $filesScanned++;
            $relativePath = str_replace($this->skillsBasePath.'/', '', $filePath);
            $skillDir = basename(dirname(dirname($filePath)));

            if (! in_array($skillDir, $skillsCovered, true)) {
                $skillsCovered[] = $skillDir;
            }

            $results = array_merge($results, $this->fileSchema->validate($parsed, $relativePath, $skillDir));

            if (isset($parsed['tests']) && is_array($parsed['tests'])) {
                $testsScanned += count($parsed['tests']);

                foreach ($parsed['tests'] as $test) {
                    if (is_array($test)) {
                        $results = array_merge($results, $this->testCaseSchema->validate($test, $relativePath));
                    }
                }

                foreach ($this->fileRules as $rule) {
                    $results = array_merge($results, $rule->validate($parsed, $relativePath));
                }

                $allFilesBySkill[$skillDir][] = $parsed;

                $ops = array_unique(array_column($parsed['tests'], 'operation'));
                $operationCoverage[$skillDir] = array_values(array_unique(
                    array_merge($operationCoverage[$skillDir] ?? [], $ops),
                ));
            }
        }

        foreach ($this->crossFileRules as $rule) {
            $results = array_merge($results, $rule->validate($allFilesBySkill));
        }

        if ($strict) {
            $results = array_map(
                fn (ValidationResult $r) => $r->isError() ? $r : ValidationResult::error($r->file, $r->rule, $r->message, $r->test),
                $results,
            );
        }

        sort($skillsCovered);
        ksort($operationCoverage);

        $json = $this->reporter->render($results, $filesScanned, $testsScanned, $skillsCovered, $operationCoverage);

        return json_decode($json, true);
    }
}
