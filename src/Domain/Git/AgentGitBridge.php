<?php

declare(strict_types=1);

namespace Claudriel\Domain\Git;

use Claudriel\Entity\Workspace;
use Claudriel\Support\WorkspaceRepoResolver;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class AgentGitBridge
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly GitPipeline $gitPipeline,
        private readonly ?WorkspaceRepoResolver $repoResolver = null,
    ) {}

    /**
     * Commit all changes in the workspace repo on behalf of the agent.
     *
     * @return array{commit_hash: string, pushed: bool}
     */
    public function commitForAgent(string $workspaceUuid, string $message): array
    {
        $repoPath = $this->resolveRepoPath($workspaceUuid);

        return $this->gitPipeline->commitAndPush($repoPath, $message);
    }

    /**
     * Generate a diff for the workspace repo.
     */
    public function diffForAgent(string $workspaceUuid): string
    {
        return $this->gitPipeline->generateDiff($this->resolveRepoPath($workspaceUuid));
    }

    /**
     * Push the workspace repo to its remote branch.
     *
     * @return array{branch: string, pushed: bool}
     */
    public function pushForAgent(string $workspaceUuid): array
    {
        $repoPath = $this->resolveRepoPath($workspaceUuid);

        $repo = $this->repoResolver?->findLinkedRepo($workspaceUuid);
        $branch = trim((string) ($repo?->get('default_branch') ?? 'main'));
        if ($branch === '') {
            $branch = 'main';
        }

        $this->gitPipeline->commitAndPush($repoPath, '', $branch);

        return [
            'branch' => $branch,
            'pushed' => true,
        ];
    }

    private function resolveRepoPath(string $workspaceUuid): string
    {
        $results = $this->workspaceRepo->findBy(['uuid' => $workspaceUuid]);
        if (! ($results[0] ?? null) instanceof Workspace) {
            throw new \RuntimeException(sprintf('Workspace not found: %s', $workspaceUuid));
        }

        $repo = $this->repoResolver?->findLinkedRepo($workspaceUuid);
        if ($repo === null) {
            throw new \RuntimeException(sprintf('Workspace %s has no repository connected', $workspaceUuid));
        }

        $repoPath = trim((string) ($repo->get('local_path') ?? ''));
        if ($repoPath === '') {
            throw new \RuntimeException(sprintf('Workspace %s has no local repo path configured', $workspaceUuid));
        }

        return $repoPath;
    }
}
