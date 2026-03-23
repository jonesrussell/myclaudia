<?php

declare(strict_types=1);

namespace Claudriel\Domain\Project;

use Claudriel\Entity\ProjectRepo;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceProject;
use Claudriel\Entity\WorkspaceRepo;
use Claudriel\Support\WorkspaceRepoResolver;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class ProjectRepoLinker
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly EntityRepositoryInterface $projectRepo,
        private readonly EntityRepositoryInterface $repoRepo,
        private readonly EntityRepositoryInterface $workspaceRepoJunctionRepo,
        private readonly EntityRepositoryInterface $workspaceProjectJunctionRepo,
        private readonly EntityRepositoryInterface $projectRepoJunctionRepo,
    ) {}

    /**
     * Create a workspace for a repo URL, create a Repo entity,
     * and link everything via junction entities.
     *
     * @return string The UUID of the created workspace
     */
    public function linkRepo(string $projectUuid, string $repoUrl): string
    {
        $this->assertProjectExists($projectUuid);

        $repoName = WorkspaceRepoResolver::extractRepoName($repoUrl);

        // Create the Repo entity
        $repo = new Repo([
            'name' => $repoName,
            'url' => $repoUrl,
        ]);
        $this->repoRepo->save($repo);
        $repoUuid = (string) $repo->get('uuid');

        // Create the Workspace entity
        $workspace = new Workspace([
            'name' => $repoName,
            'description' => sprintf('Repository workspace for %s', $repoUrl),
        ]);
        $this->workspaceRepo->save($workspace);
        $workspaceUuid = (string) $workspace->get('uuid');

        // Create WorkspaceRepo junction
        $wsRepoJunction = new WorkspaceRepo([
            'workspace_uuid' => $workspaceUuid,
            'repo_uuid' => $repoUuid,
        ]);
        $this->workspaceRepoJunctionRepo->save($wsRepoJunction);

        // Create WorkspaceProject junction
        $wsProjectJunction = new WorkspaceProject([
            'workspace_uuid' => $workspaceUuid,
            'project_uuid' => $projectUuid,
        ]);
        $this->workspaceProjectJunctionRepo->save($wsProjectJunction);

        // Create ProjectRepo junction
        $prJunction = new ProjectRepo([
            'project_uuid' => $projectUuid,
            'repo_uuid' => $repoUuid,
        ]);
        $this->projectRepoJunctionRepo->save($prJunction);

        return $workspaceUuid;
    }

    /**
     * Unlink a workspace from a project by deleting the WorkspaceProject junction.
     */
    public function unlinkRepo(string $projectUuid, string $workspaceUuid): void
    {
        $this->assertProjectExists($projectUuid);

        $results = $this->workspaceRepo->findBy(['uuid' => $workspaceUuid]);
        $workspace = $results[0] ?? null;

        if (! $workspace instanceof Workspace) {
            throw new \RuntimeException(sprintf('Workspace not found: %s', $workspaceUuid));
        }

        // Find and delete the WorkspaceProject junction
        $junctions = $this->workspaceProjectJunctionRepo->findBy(['workspace_uuid' => $workspaceUuid]);
        $found = false;

        foreach ($junctions as $junction) {
            assert($junction instanceof WorkspaceProject);
            if ($junction->get('project_uuid') === $projectUuid) {
                $this->workspaceProjectJunctionRepo->delete($junction);
                $found = true;
                break;
            }
        }

        if (! $found) {
            throw new \RuntimeException(sprintf(
                'Workspace %s is not linked to project %s',
                $workspaceUuid,
                $projectUuid,
            ));
        }
    }

    /**
     * List all workspaces linked to a project that have an associated Repo.
     *
     * @return Workspace[]
     */
    public function listRepos(string $projectUuid): array
    {
        $this->assertProjectExists($projectUuid);

        // Find WorkspaceProject junctions for this project
        $wpJunctions = $this->workspaceProjectJunctionRepo->findBy(['project_uuid' => $projectUuid]);

        $workspaces = [];
        foreach ($wpJunctions as $wpJunction) {
            assert($wpJunction instanceof WorkspaceProject);
            $wsUuid = $wpJunction->get('workspace_uuid');

            // Check if this workspace has a linked repo via WorkspaceRepo junction
            $wrJunctions = $this->workspaceRepoJunctionRepo->findBy(['workspace_uuid' => $wsUuid]);
            if ($wrJunctions === []) {
                continue;
            }

            $wsResults = $this->workspaceRepo->findBy(['uuid' => $wsUuid]);
            $ws = $wsResults[0] ?? null;

            if ($ws instanceof Workspace) {
                $workspaces[] = $ws;
            }
        }

        return $workspaces;
    }

    private function assertProjectExists(string $projectUuid): void
    {
        $results = $this->projectRepo->findBy(['uuid' => $projectUuid]);

        if (($results[0] ?? null) === null) {
            throw new \RuntimeException(sprintf('Project not found: %s', $projectUuid));
        }
    }
}
