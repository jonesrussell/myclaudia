<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Eval\Command;

use Claudriel\Eval\Command\EvalValidateCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class EvalValidateCommandTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir().'/eval_cmd_test_'.uniqid('', true);
        mkdir($this->tempDir.'/test-skill/evals', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
    }

    public function test_valid_evals_exit_zero(): void
    {
        $yaml = <<<'YAML'
schema_version: "1.0"
skill: "test-skill"
entity_type: "test"

tests:
  - name: create-basic
    operation: create
    input: "create a thing"
    assertions:
      - type: confirmation_shown
  - name: list-basic
    operation: list
    input: "show things"
    assertions:
      - type: graphql_operation
        operation: testList
        mutation: false
  - name: update-basic
    operation: update
    input: "rename it"
    context:
      existing_entities:
        - uuid: "a"
          fields: { name: "X" }
    assertions:
      - type: resolve_first
  - name: delete-basic
    operation: delete
    input: "remove it"
    context:
      existing_entities:
        - uuid: "a"
          fields: { name: "X" }
    assertions:
      - type: echo_back_required
        field: name
YAML;
        file_put_contents($this->tempDir.'/test-skill/evals/basic.yaml', $yaml);

        $command = new EvalValidateCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('pass', $tester->getDisplay());
    }

    public function test_invalid_evals_exit_one(): void
    {
        $yaml = <<<'YAML'
schema_version: "1.0"
skill: "test-skill"
entity_type: "test"

tests:
  - name: BAD_NAME
    operation: create
    input: "x"
    assertions:
      - type: confirmation_shown
YAML;
        file_put_contents($this->tempDir.'/test-skill/evals/basic.yaml', $yaml);

        $command = new EvalValidateCommand($this->tempDir);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
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
