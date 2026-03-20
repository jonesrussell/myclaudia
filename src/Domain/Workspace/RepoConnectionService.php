<?php

declare(strict_types=1);

namespace Claudriel\Domain\Workspace;

use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Workspace;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class RepoConnectionService
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly GitRepositoryManager $gitRepositoryManager,
    ) {}

    /**
     * Connect a repository to a workspace by URL.
     *
     * Validates the URL format (https or ssh), sets workspace fields,
     * and triggers a clone via GitRepositoryManager.
     */
    public function connect(string $workspaceUuid, string $repoUrl, ?string $branch = 'main'): void
    {
        $branch = $branch ?? 'main';

        $this->validateRepoUrl($repoUrl);

        $workspace = $this->loadWorkspace($workspaceUuid);

        $repoPath = $this->gitRepositoryManager->buildWorkspaceRepoPath($workspaceUuid);

        $workspace->set('repo_url', $repoUrl);
        $workspace->set('branch', $branch);
        $workspace->set('repo_path', $repoPath);

        $this->workspaceRepo->save($workspace);

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
