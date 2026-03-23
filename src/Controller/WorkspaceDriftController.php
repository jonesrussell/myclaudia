<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Git\DriftDetector;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceRepo;
use Claudriel\Support\WorkspaceRepoResolver;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

/**
 * API endpoints for workspace repository connection and drift detection.
 */
final class WorkspaceDriftController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly GitRepositoryManager $gitRepositoryManager,
        private readonly DriftDetector $driftDetector,
        private readonly ?EntityRepositoryInterface $repoRepo = null,
        private readonly ?EntityRepositoryInterface $workspaceRepoJunctionRepo = null,
        private readonly ?WorkspaceRepoResolver $repoResolver = null,
    ) {}

    /**
     * POST /api/workspaces/{uuid}/connect-repo
     *
     * Accepts JSON body: { "repo_url": "...", "branch": "main" }
     */
    public function connectRepo(array $params): SsrResponse
    {
        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->json(['error' => 'Missing workspace UUID.'], 400);
        }

        $input = json_decode(file_get_contents('php://input') ?: '{}', true);
        if (! is_array($input)) {
            return $this->json(['error' => 'Invalid JSON body.'], 400);
        }

        $repoUrl = trim((string) ($input['repo_url'] ?? ''));
        if ($repoUrl === '') {
            return $this->json(['error' => 'repo_url is required.'], 400);
        }

        $branch = trim((string) ($input['branch'] ?? 'main'));

        $httpsPattern = '#^https://[a-zA-Z0-9._\-]+(/[a-zA-Z0-9._\-]+)+(?:\.git)?$#';
        $sshPattern = '#^git@[a-zA-Z0-9._\-]+:[a-zA-Z0-9._\-]+(/[a-zA-Z0-9._\-]+)+(?:\.git)?$#';
        if (preg_match($httpsPattern, $repoUrl) !== 1 && preg_match($sshPattern, $repoUrl) !== 1) {
            return $this->json(['error' => 'Invalid repository URL. Must be https:// or git@ (SSH) format.'], 400);
        }

        $workspace = $this->loadWorkspace($uuid);
        if ($workspace === null) {
            return $this->json(['error' => sprintf('Workspace not found: %s', $uuid)], 404);
        }

        $repoPath = $this->gitRepositoryManager->buildWorkspaceRepoPath($uuid);

        try {
            $this->gitRepositoryManager->clone($repoUrl, $repoPath, $branch);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => sprintf('Clone failed: %s', $e->getMessage())], 500);
        }

        if ($this->repoRepo !== null && $this->workspaceRepoJunctionRepo !== null) {
            $repoEntity = new Repo([
                'url' => $repoUrl,
                'name' => WorkspaceRepoResolver::extractRepoName($repoUrl),
                'default_branch' => $branch,
                'local_path' => $repoPath,
            ]);
            $this->repoRepo->save($repoEntity);

            $junction = new WorkspaceRepo([
                'workspace_uuid' => $uuid,
                'repo_uuid' => (string) $repoEntity->get('uuid'),
            ]);
            $this->workspaceRepoJunctionRepo->save($junction);
        }

        return $this->json(['connected' => true, 'repo_url' => $repoUrl, 'branch' => $branch]);
    }

    /**
     * GET /api/workspaces/{uuid}/drift
     *
     * Returns drift status for a workspace's connected repository.
     */
    public function drift(array $params): SsrResponse
    {
        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->json(['error' => 'Missing workspace UUID.'], 400);
        }

        $workspace = $this->loadWorkspace($uuid);
        if ($workspace === null) {
            return $this->json(['error' => sprintf('Workspace not found: %s', $uuid)], 404);
        }

        $linkedRepo = $this->repoResolver?->findLinkedRepo($uuid);
        $repoPath = $linkedRepo !== null ? trim((string) ($linkedRepo->get('local_path') ?? '')) : '';

        if ($repoPath === '' || ! is_dir($repoPath.'/.git')) {
            return $this->json([
                'drift_status' => 'no_repo',
                'message' => 'No repository connected to this workspace.',
            ]);
        }

        $branch = trim((string) ($linkedRepo->get('default_branch') ?? 'main'));

        try {
            $driftResult = $this->driftDetector->detectDrift($repoPath, $branch);
        } catch (\RuntimeException $e) {
            return $this->json(['error' => $e->getMessage()], 500);
        }

        return $this->json([
            'workspace_uuid' => $uuid,
            'drift_status' => $driftResult->isDrifted ? 'drifted' : 'synced',
            'drift' => $driftResult->toArray(),
        ]);
    }

    private function loadWorkspace(string $uuid): ?Workspace
    {
        $storage = $this->entityTypeManager->getStorage('workspace');
        $all = $storage->loadMultiple();

        foreach ($all as $entity) {
            if ($entity instanceof Workspace && $entity->get('uuid') === $uuid) {
                return $entity;
            }
        }

        return null;
    }

    private function json(mixed $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
