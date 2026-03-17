<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\AI;

use Claudriel\AI\CodexExecutionPipeline;
use Claudriel\AI\PromptBuilder;
use Claudriel\Domain\Chat\SubprocessChatClient;
use Claudriel\Entity\Operation;
use Claudriel\Entity\Workspace;
use Claudriel\Service\GitOperator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class CodexExecutionPipelineTest extends TestCase
{
    private ?string $repoPath = null;

    protected function tearDown(): void
    {
        if ($this->repoPath !== null && is_dir($this->repoPath)) {
            rmdir($this->repoPath);
        }
    }

    public function test_execute_creates_completed_operation(): void
    {
        $dispatcher = new EventDispatcher;
        $workspaceRepo = new EntityRepository(
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
        $operationRepo = new EntityRepository(
            new EntityType(id: 'operation', label: 'Operation', class: Operation::class, keys: ['id' => 'opid', 'uuid' => 'uuid']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $this->repoPath = sys_get_temp_dir().'/claudriel-pipeline-'.bin2hex(random_bytes(4));
        mkdir($this->repoPath, 0755, true);

        $workspace = new Workspace([
            'name' => 'Claudriel System',
            'repo_path' => $this->repoPath,
            'branch' => 'main',
        ]);
        $workspaceRepo->save($workspace);

        $gitOperator = new GitOperator(
            function (string $command): array {
                if (str_contains($command, 'status --porcelain')) {
                    return ['exit_code' => 0, 'output' => " M src/AI/CodexExecutionPipeline.php\n"];
                }
                if (str_contains($command, 'rev-parse HEAD')) {
                    return ['exit_code' => 0, 'output' => "abc123\n"];
                }

                return ['exit_code' => 0, 'output' => ''];
            },
            fn (string $command, string $input): array => ['exit_code' => 0, 'output' => ''],
        );
        // Create a mock PHP script that mimics the Python agent
        $mockScript = sys_get_temp_dir().'/mock_codex_agent_'.uniqid().'.php';
        file_put_contents($mockScript, <<<'PHP'
        <?php
        file_get_contents('php://stdin');
        $patch = "--- a/file.txt\n+++ b/file.txt\n@@ -0,0 +1 @@\n+patched\n";
        echo json_encode(['event' => 'message', 'content' => $patch]) . "\n";
        echo json_encode(['event' => 'done']) . "\n";
        PHP);

        $subprocessClient = new SubprocessChatClient(
            pythonBinary: PHP_BINARY,
            agentPath: $mockScript,
            timeoutSeconds: 10,
        );

        $pipeline = new CodexExecutionPipeline(
            new PromptBuilder,
            $gitOperator,
            $workspaceRepo,
            $operationRepo,
            $subprocessClient,
        );
        $pipeline->execute($workspace, 'Prepare a placeholder patch.');

        $operations = $operationRepo->findBy(['workspace_id' => $workspace->get('wid')]);
        $operation = $operations[0] ?? null;

        self::assertCount(1, $operations);
        self::assertInstanceOf(Operation::class, $operation);
        self::assertSame('complete', $operation->get('status'));
        self::assertSame('abc123', $operation->get('commit_hash'));
        self::assertSame('abc123', $workspace->get('last_commit_hash'));
        self::assertStringContainsString('patched', $operation->get('model_response'));
    }
}
