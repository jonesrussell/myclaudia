<?php

declare(strict_types=1);

namespace Claudriel\Domain\Project;

use Claudriel\Entity\Workspace;
use Waaseyaa\EntityStorage\EntityRepository;

final class ProjectRepoLinker
{
    public function __construct(
        private readonly EntityRepository $workspaceRepo,
        private readonly EntityRepository $projectRepo,
    ) {}

    /**
     * Create a workspace for a repo URL and link it to a project.
     *
     * @return string The UUID of the created workspace
     */
    public function linkRepo(string $projectUuid, string $repoUrl): string
    {
        $this->assertProjectExists($projectUuid);

        $repoName = $this->extractRepoName($repoUrl);

        $workspace = new Workspace([
            'name' => $repoName,
            'description' => sprintf('Repository workspace for %s', $repoUrl),
            'repo_url' => $repoUrl,
            'project_id' => $projectUuid,
        ]);

        $this->workspaceRepo->save($workspace);

        return (string) $workspace->get('uuid');
    }

    /**
     * Unlink a workspace from a project by setting project_id to null.
     */
    public function unlinkRepo(string $projectUuid, string $workspaceUuid): void
    {
        $this->assertProjectExists($projectUuid);

        $results = $this->workspaceRepo->findBy(['uuid' => $workspaceUuid]);
        $workspace = $results[0] ?? null;

        if (! $workspace instanceof Workspace) {
            throw new \RuntimeException(sprintf('Workspace not found: %s', $workspaceUuid));
        }

        if ($workspace->get('project_id') !== $projectUuid) {
            throw new \RuntimeException(sprintf(
                'Workspace %s is not linked to project %s',
                $workspaceUuid,
                $projectUuid,
            ));
        }

        $workspace->set('project_id', null);
        $this->workspaceRepo->save($workspace);
    }

    /**
     * List all workspaces linked to a project that have a repo_url set.
     *
     * @return Workspace[]
     */
    public function listRepos(string $projectUuid): array
    {
        $this->assertProjectExists($projectUuid);

        $results = $this->workspaceRepo->findBy(['project_id' => $projectUuid]);

        return array_values(array_filter(
            $results,
            static fn (mixed $ws): bool => $ws instanceof Workspace
                && $ws->get('repo_url') !== null
                && $ws->get('repo_url') !== '',
        ));
    }

    private function assertProjectExists(string $projectUuid): void
    {
        $results = $this->projectRepo->findBy(['uuid' => $projectUuid]);

        if (($results[0] ?? null) === null) {
            throw new \RuntimeException(sprintf('Project not found: %s', $projectUuid));
        }
    }

    private function extractRepoName(string $repoUrl): string
    {
        $path = parse_url($repoUrl, PHP_URL_PATH);

        if ($path === null || $path === false) {
            // SSH format: git@host:owner/repo.git
            $parts = explode(':', $repoUrl);
            $path = $parts[1] ?? $repoUrl;
        }

        $basename = basename((string) $path);

        return str_ends_with($basename, '.git')
            ? substr($basename, 0, -4)
            : $basename;
    }
}
