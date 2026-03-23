<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Claudriel\Entity\Repo;
use Claudriel\Entity\WorkspaceRepo;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Shared helpers for Repo entity lookups through WorkspaceRepo junctions.
 *
 * Using classes must declare constructor-promoted properties:
 *   - EntityRepositoryInterface $repoRepository
 *   - EntityRepositoryInterface $workspaceRepoRepository
 */
trait LinkedRepoLookup
{
    private function findLinkedRepo(string $workspaceUuid): ?Repo
    {
        $junctions = $this->workspaceRepoRepository->findBy(['workspace_uuid' => $workspaceUuid]);

        if ($junctions === []) {
            return null;
        }

        $repoUuid = (string) $junctions[0]->get('repo_uuid');
        $repos = $this->repoRepository->findBy(['uuid' => $repoUuid]);
        $repo = $repos[0] ?? null;

        return $repo instanceof Repo ? $repo : null;
    }

    private function findOrCreateRepo(string $localPath, string $url, string $defaultBranch = 'main'): Repo
    {
        $existing = $this->repoRepository->findBy(['local_path' => $localPath]);

        if ($existing !== []) {
            $repo = $existing[0];
            assert($repo instanceof Repo);
            if ($url !== '' && $repo->get('url') !== $url) {
                $repo->set('url', $url);
                $this->repoRepository->save($repo);
            }

            return $repo;
        }

        $repo = new Repo([
            'local_path' => $localPath,
            'url' => $url,
            'default_branch' => $defaultBranch,
        ]);
        $this->repoRepository->save($repo);

        return $repo;
    }

    private function ensureJunction(string $workspaceUuid, string $repoUuid): void
    {
        $existing = $this->workspaceRepoRepository->findBy([
            'workspace_uuid' => $workspaceUuid,
            'repo_uuid' => $repoUuid,
        ]);

        if ($existing !== []) {
            return;
        }

        $junction = new WorkspaceRepo([
            'workspace_uuid' => $workspaceUuid,
            'repo_uuid' => $repoUuid,
            'is_active' => true,
        ]);
        $this->workspaceRepoRepository->save($junction);
    }
}
