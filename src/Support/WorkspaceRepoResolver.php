<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Claudriel\Entity\Repo;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

/**
 * Resolves the linked Repo entity for a workspace via the WorkspaceRepo junction table.
 */
final class WorkspaceRepoResolver
{
    public function __construct(
        private readonly EntityRepositoryInterface $repoRepo,
        private readonly EntityRepositoryInterface $workspaceRepoJunctionRepo,
    ) {}

    public function findLinkedRepo(string $workspaceUuid): ?Repo
    {
        if ($workspaceUuid === '') {
            return null;
        }

        $junctions = $this->workspaceRepoJunctionRepo->findBy(['workspace_uuid' => $workspaceUuid]);
        if ($junctions === []) {
            return null;
        }

        $repoUuid = $junctions[0]->get('repo_uuid');
        $repos = $this->repoRepo->findBy(['uuid' => $repoUuid]);
        $repo = $repos[0] ?? null;

        return $repo instanceof Repo ? $repo : null;
    }

    /**
     * Extract a repository name from a URL (https or SSH format).
     */
    public static function extractRepoName(string $repoUrl): string
    {
        $path = parse_url($repoUrl, PHP_URL_PATH);

        if ($path === null || $path === false) {
            $parts = explode(':', $repoUrl);
            $path = $parts[1] ?? $repoUrl;
        }

        $basename = basename((string) $path);

        return str_ends_with($basename, '.git')
            ? substr($basename, 0, -4)
            : $basename;
    }
}
