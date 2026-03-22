<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval;

use Claudriel\Eval\EvalSchemaValidator;
use PHPUnit\Framework\TestCase;

final class EvalSchemaValidatorTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/eval_test_'.uniqid('', true);
        mkdir($this->tempDir.'/skill-a/evals', 0777, true);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        $this->removeDir($this->tempDir);
    }

    public function test_valid_file_produces_pass(): void
    {
        $yaml = <<<'YAML'
schema_version: "1.0"
skill: "skill-a"
entity_type: "skill_a"

tests:
  - name: create-basic
    operation: create
    input: "create a thing"
    assertions:
      - type: confirmation_shown
  - name: list-all
    operation: list
    input: "show things"
    assertions:
      - type: graphql_operation
        operation: skillAList
        mutation: false
  - name: update-basic
    operation: update
    input: "rename it"
    context:
      existing_entities:
        - uuid: "abc"
          fields: { name: "Old" }
    assertions:
      - type: resolve_first
  - name: delete-basic
    operation: delete
    input: "remove it"
    context:
      existing_entities:
        - uuid: "abc"
          fields: { name: "Old" }
    assertions:
      - type: echo_back_required
        field: name
YAML;
        file_put_contents($this->tempDir.'/skill-a/evals/basic.yaml', $yaml);

        $validator = new EvalSchemaValidator($this->tempDir);
        $report = $validator->validate();

        self::assertSame('pass', $report['status']);
        self::assertSame(0, $report['summary']['errors']);
    }

    public function test_invalid_file_produces_fail(): void
    {
        $yaml = <<<'YAML'
schema_version: "1.0"
skill: "skill-a"
entity_type: "skill_a"

tests:
  - name: bad_name
    operation: create
    input: "create a thing"
    assertions:
      - type: confirmation_shown
YAML;
        file_put_contents($this->tempDir.'/skill-a/evals/basic.yaml', $yaml);

        $validator = new EvalSchemaValidator($this->tempDir);
        $report = $validator->validate();

        self::assertSame('fail', $report['status']);
        self::assertGreaterThan(0, $report['summary']['errors']);
    }

    public function test_file_without_schema_version_is_skipped(): void
    {
        $yaml = <<<'YAML'
prompts:
  - prompt: "something"
    expectations:
      - "does a thing"
YAML;
        file_put_contents($this->tempDir.'/skill-a/evals/basic.yaml', $yaml);

        $validator = new EvalSchemaValidator($this->tempDir);
        $report = $validator->validate();

        self::assertSame(0, $report['summary']['files_scanned']);
    }

    public function test_skill_filter_limits_scope(): void
    {
        mkdir($this->tempDir.'/skill-b/evals', 0777, true);

        $yaml = <<<'YAML'
schema_version: "1.0"
skill: "skill-a"
entity_type: "skill_a"

tests:
  - name: create-basic
    operation: create
    input: "x"
    assertions:
      - type: confirmation_shown
YAML;
        file_put_contents($this->tempDir.'/skill-a/evals/basic.yaml', $yaml);
        file_put_contents($this->tempDir.'/skill-b/evals/basic.yaml', str_replace(['skill-a', 'skill_a'], ['skill-b', 'skill_b'], $yaml));

        $validator = new EvalSchemaValidator($this->tempDir);
        $report = $validator->validate(skillFilter: 'skill-a');

        self::assertSame(1, $report['summary']['files_scanned']);
        self::assertSame(['skill-a'], $report['summary']['skills_covered']);
    }

    private function removeDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = "$dir/$item";
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
