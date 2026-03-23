<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceRepo;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class WorkspaceRepoResolver
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepository,
        private readonly EntityRepositoryInterface $repoRepository,
        private readonly EntityRepositoryInterface $workspaceRepoRepository,
    ) {}

    public function findWorkspace(string $uuid): ?Workspace
    {
        $results = $this->workspaceRepository->findBy(['uuid' => $uuid]);
        $workspace = $results[0] ?? null;

        return $workspace instanceof Workspace ? $workspace : null;
    }

    public function findLinkedRepo(string $workspaceUuid): ?Repo
    {
        $junctions = $this->workspaceRepoRepository->findBy([
            'workspace_uuid' => $workspaceUuid,
            'is_active' => true,
        ]);

        $junction = $junctions[0] ?? null;
        if (! $junction instanceof WorkspaceRepo) {
            return null;
        }

        $repoUuid = (string) $junction->get('repo_uuid');
        if ($repoUuid === '') {
            return null;
        }

        $repos = $this->repoRepository->findBy(['uuid' => $repoUuid]);
        $repo = $repos[0] ?? null;

        return $repo instanceof Repo ? $repo : null;
    }

    public function findOrCreateRepo(string $owner, string $name, ?string $url = null): Repo
    {
        $fullName = ($owner !== '' && $name !== '') ? $owner.'/'.$name : '';

        if ($fullName !== '') {
            $existing = $this->repoRepository->findBy(['full_name' => $fullName]);
            if (isset($existing[0]) && $existing[0] instanceof Repo) {
                return $existing[0];
            }
        }

        $values = [
            'owner' => $owner,
            'name' => $name,
            'full_name' => $fullName,
        ];

        if ($url !== null && $url !== '') {
            $values['url'] = $url;
        }

        $repo = new Repo($values);
        $this->repoRepository->save($repo);

        return $repo;
    }

    public function ensureJunction(string $workspaceUuid, string $repoUuid): WorkspaceRepo
    {
        $existing = $this->workspaceRepoRepository->findBy([
            'workspace_uuid' => $workspaceUuid,
            'repo_uuid' => $repoUuid,
        ]);

        if (isset($existing[0]) && $existing[0] instanceof WorkspaceRepo) {
            return $existing[0];
        }

        $junction = new WorkspaceRepo([
            'workspace_uuid' => $workspaceUuid,
            'repo_uuid' => $repoUuid,
            'is_active' => true,
        ]);

        $this->workspaceRepoRepository->save($junction);

        return $junction;
    }

    public function getWorkspaceRepository(): EntityRepositoryInterface
    {
        return $this->workspaceRepository;
    }

    public function getRepoRepository(): EntityRepositoryInterface
    {
        return $this->repoRepository;
    }
}
