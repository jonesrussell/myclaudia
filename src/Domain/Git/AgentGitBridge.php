<?php

declare(strict_types=1);

namespace Claudriel\Domain\Git;

use Claudriel\Entity\Workspace;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class AgentGitBridge
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly GitPipeline $gitPipeline,
    ) {}

    /**
     * Commit all changes in the workspace repo on behalf of the agent.
     *
     * @return array{commit_hash: string, pushed: bool}
     */
    public function commitForAgent(string $workspaceUuid, string $message): array
    {
        $workspace = $this->loadWorkspace($workspaceUuid);
        $repoPath = $this->resolveRepoPath($workspace);

        $result = $this->gitPipeline->commitAndPush($repoPath, $message);

        $workspace->set('last_commit_hash', $result['commit_hash']);
        $this->workspaceRepo->save($workspace);

        return $result;
    }

    /**
     * Generate a diff for the workspace repo.
     */
    public function diffForAgent(string $workspaceUuid): string
    {
        $workspace = $this->loadWorkspace($workspaceUuid);
        $repoPath = $this->resolveRepoPath($workspace);

        return $this->gitPipeline->generateDiff($repoPath);
    }

    /**
     * Push the workspace repo to its remote branch.
     *
     * @return array{branch: string, pushed: bool}
     */
    public function pushForAgent(string $workspaceUuid): array
    {
        $workspace = $this->loadWorkspace($workspaceUuid);
        $repoPath = $this->resolveRepoPath($workspace);
        $branch = (string) ($workspace->get('branch') ?: 'main');

        $this->gitPipeline->commitAndPush($repoPath, '', $branch);

        return [
            'branch' => $branch,
            'pushed' => true,
        ];
    }

    private function loadWorkspace(string $workspaceUuid): Workspace
    {
        $results = $this->workspaceRepo->findBy(['uuid' => $workspaceUuid]);
        $workspace = $results[0] ?? null;

        if (! $workspace instanceof Workspace) {
            throw new \RuntimeException(sprintf('Workspace not found: %s', $workspaceUuid));
        }

        return $workspace;
    }

    private function resolveRepoPath(Workspace $workspace): string
    {
        $repoPath = (string) $workspace->get('repo_path');

        if ($repoPath === '') {
            throw new \RuntimeException(sprintf(
                'Workspace %s has no repo_path configured',
                (string) $workspace->get('uuid'),
            ));
        }

        return $repoPath;
    }
}
