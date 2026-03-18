<?php

declare(strict_types=1);

namespace Claudriel\AI;

use Claudriel\Domain\Chat\SubprocessChatClient;
use Claudriel\Domain\Git\GitOperator;
use Claudriel\Entity\Operation;
use Claudriel\Entity\Workspace;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CodexExecutionPipeline
{
    public function __construct(
        private readonly PromptBuilder $promptBuilder,
        private readonly GitOperator $gitOperator,
        private readonly EntityRepositoryInterface $workspaceRepository,
        private readonly EntityRepositoryInterface $operationRepository,
        private readonly ?SubprocessChatClient $subprocessChatClient = null,
    ) {}

    public function execute(Workspace $workspace, string $instruction): void
    {
        $prompt = $this->buildPrompt($workspace, $instruction);
        $operation = new Operation([
            'workspace_id' => $workspace->get('wid'),
            'input_instruction' => $instruction,
            'generated_prompt' => $prompt,
            'status' => 'pending',
        ]);
        $this->operationRepository->save($operation);

        $patch = $this->callCodexModel($workspace, $prompt);
        $this->applyPatch($workspace, $patch);
        $commitHash = $this->commitAndPushChanges($workspace, $instruction);

        $workspace->set('last_commit_hash', $commitHash);
        $this->workspaceRepository->save($workspace);

        $operation->set('model_response', $patch);
        $operation->set('applied_patch', $patch);
        $operation->set('commit_hash', $commitHash);
        $operation->set('status', 'complete');
        $this->operationRepository->save($operation);
    }

    public function buildPrompt(Workspace $workspace, string $instruction): string
    {
        return $this->promptBuilder->build($workspace, $instruction);
    }

    public function callCodexModel(Workspace $workspace, string $prompt): string
    {
        $client = $this->subprocessChatClient ?? $this->createSubprocessChatClient();
        $response = null;
        $streamed = '';
        $error = null;

        $client->stream(
            systemPrompt: '',
            messages: [['role' => 'user', 'content' => $prompt]],
            accountId: 'codex',
            tenantId: 'default',
            apiBase: $_ENV['CLAUDRIEL_API_URL'] ?? getenv('CLAUDRIEL_API_URL') ?: 'http://localhost:8088',
            apiToken: '',
            onToken: function (string $token) use (&$streamed): void {
                $streamed .= $token;
            },
            onDone: function (string $fullResponse) use (&$response): void {
                $response = $fullResponse;
            },
            onError: function (string $message) use (&$error): void {
                $error = $message;
            },
        );

        if (is_string($error) && $error !== '') {
            throw new \RuntimeException($error);
        }

        return $response ?? $streamed;
    }

    public function applyPatch(Workspace $workspace, string $patch): void
    {
        $repoPath = (string) ($workspace->get('repo_path') ?? '');
        $this->gitOperator->applyPatch($repoPath, $patch);
    }

    public function commitAndPushChanges(Workspace $workspace, string $instruction): string
    {
        $repoPath = (string) ($workspace->get('repo_path') ?? '');
        $branch = (string) ($workspace->get('branch') ?? 'main');

        $commitHash = $this->gitOperator->commit($repoPath, $instruction);
        $this->gitOperator->push($repoPath, $branch);

        return $commitHash;
    }

    private function createSubprocessChatClient(): SubprocessChatClient
    {
        $dockerImage = $_ENV['AGENT_DOCKER_IMAGE'] ?? getenv('AGENT_DOCKER_IMAGE') ?: '';

        if ($dockerImage !== '') {
            $apiKey = $_ENV['ANTHROPIC_API_KEY'] ?? getenv('ANTHROPIC_API_KEY') ?: '';

            return new SubprocessChatClient(['docker', 'run', '--rm', '-i', '--network=host', '-e', 'ANTHROPIC_API_KEY='.$apiKey, $dockerImage, 'python', '/srv/agent/main.py']);
        }

        $projectRoot = getcwd() ?: '/srv';
        $venv = $_ENV['AGENT_VENV'] ?? getenv('AGENT_VENV') ?: $projectRoot.'/agent/.venv';
        $agentPath = $_ENV['AGENT_PATH'] ?? getenv('AGENT_PATH') ?: $projectRoot.'/agent/main.py';

        return new SubprocessChatClient([$venv.'/bin/python', $agentPath]);
    }
}
