<?php

declare(strict_types=1);

namespace Claudriel\Domain\Workspace;

use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceRepo;
use Claudriel\Support\WorkspaceRepoResolver;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class RepoConnectionService
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly EntityRepositoryInterface $repoRepo,
        private readonly EntityRepositoryInterface $workspaceRepoJunctionRepo,
        private readonly GitRepositoryManager $gitRepositoryManager,
    ) {}

    /**
     * Connect a repository to a workspace by URL.
     *
     * Validates the URL format (https or ssh), creates a Repo entity,
     * links it via WorkspaceRepo junction, and triggers a clone via GitRepositoryManager.
     */
    public function connect(string $workspaceUuid, string $repoUrl, ?string $branch = 'main'): void
    {
        $branch = $branch ?? 'main';

        $this->validateRepoUrl($repoUrl);

        $this->loadWorkspace($workspaceUuid);

        $repoPath = $this->gitRepositoryManager->buildWorkspaceRepoPath($workspaceUuid);

        $repo = new Repo([
            'url' => $repoUrl,
            'name' => WorkspaceRepoResolver::extractRepoName($repoUrl),
            'default_branch' => $branch,
            'local_path' => $repoPath,
        ]);
        $this->repoRepo->save($repo);

        $junction = new WorkspaceRepo([
            'workspace_uuid' => $workspaceUuid,
            'repo_uuid' => (string) $repo->get('uuid'),
        ]);
        $this->workspaceRepoJunctionRepo->save($junction);

        $this->gitRepositoryManager->clone($repoUrl, $repoPath, $branch);
    }

    private function validateRepoUrl(string $repoUrl): void
    {
        $httpsPattern = '#^https://[a-zA-Z0-9._\-]+(/[a-zA-Z0-9._\-]+)+(?:\.git)?$#';
        $sshPattern = '#^git@[a-zA-Z0-9._\-]+:[a-zA-Z0-9._\-]+(/[a-zA-Z0-9._\-]+)+(?:\.git)?$#';

        if (preg_match($httpsPattern, $repoUrl) !== 1 && preg_match($sshPattern, $repoUrl) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid repository URL: %s. Must be https:// or git@ (SSH) format.',
                $repoUrl,
            ));
        }
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
}
