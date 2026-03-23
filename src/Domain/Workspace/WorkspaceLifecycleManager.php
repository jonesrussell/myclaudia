<?php

declare(strict_types=1);

namespace Claudriel\Domain\Workspace;

use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Entity\WorkspaceRepo;
use Claudriel\Support\WorkspaceRepoResolver;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class WorkspaceLifecycleManager
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly GitRepositoryManager $gitRepositoryManager,
        private readonly EntityRepositoryInterface $repoRepo,
        private readonly EntityRepositoryInterface $workspaceRepoJunctionRepo,
        private readonly ?WorkspaceRepoResolver $repoResolver = null,
    ) {}

    /**
     * Create a workspace with optional git repository cloning.
     *
     * @param  array<string,mixed>  $data
     */
    public function create(array $data): Workspace
    {
        $mode = $data['mode'] ?? 'persistent';
        if (! in_array($mode, ['persistent', 'ephemeral'], true)) {
            throw new \InvalidArgumentException(sprintf('Invalid workspace mode: %s', $mode));
        }

        $data['mode'] = $mode;
        $data['status'] = $data['status'] ?? 'active';

        $repoUrl = trim((string) ($data['repo_url'] ?? ''));
        $branch = trim((string) ($data['branch'] ?? 'main'));
        unset($data['repo_url'], $data['repo_path'], $data['last_commit_hash']);

        $workspace = new Workspace($data);
        $storage = $this->entityTypeManager->getStorage('workspace');
        $storage->save($workspace);

        if ($repoUrl !== '') {
            $uuid = (string) $workspace->get('uuid');
            $localPath = $this->gitRepositoryManager->buildWorkspaceRepoPath($uuid);

            $this->gitRepositoryManager->clone($repoUrl, $localPath, $branch);

            $repo = new Repo([
                'url' => $repoUrl,
                'name' => WorkspaceRepoResolver::extractRepoName($repoUrl),
                'default_branch' => $branch,
                'local_path' => $localPath,
            ]);
            $this->repoRepo->save($repo);

            $junction = new WorkspaceRepo([
                'workspace_uuid' => $uuid,
                'repo_uuid' => (string) $repo->get('uuid'),
            ]);
            $this->workspaceRepoJunctionRepo->save($junction);
        }

        return $workspace;
    }

    /**
     * Archive a workspace (sets status to archived).
     */
    public function archive(string $uuid): void
    {
        $workspace = $this->loadByUuid($uuid);
        $workspace->set('status', 'archived');
        $this->entityTypeManager->getStorage('workspace')->save($workspace);
    }

    /**
     * Restore an archived workspace (sets status to active).
     */
    public function restore(string $uuid): void
    {
        $workspace = $this->loadByUuid($uuid);

        if ($workspace->get('status') !== 'archived') {
            throw new \RuntimeException(sprintf('Workspace %s is not archived (current status: %s)', $uuid, (string) $workspace->get('status')));
        }

        $workspace->set('status', 'active');
        $this->entityTypeManager->getStorage('workspace')->save($workspace);
    }

    /**
     * Destroy an ephemeral workspace. Refuses to destroy persistent workspaces.
     * Cleans up local git repository if present.
     */
    public function destroy(string $uuid): void
    {
        $workspace = $this->loadByUuid($uuid);

        if ($workspace->get('mode') !== 'ephemeral') {
            throw new \RuntimeException(sprintf('Cannot destroy persistent workspace %s. Archive it instead.', $uuid));
        }

        $repo = $this->repoResolver?->findLinkedRepo($uuid);
        $repoPath = $repo !== null ? trim((string) ($repo->get('local_path') ?? '')) : '';

        if ($repoPath !== '' && is_dir($repoPath)) {
            $this->removeDirectory($repoPath);
        }

        $this->entityTypeManager->getStorage('workspace')->delete([$workspace]);
    }

    /**
     * Compute current status from git state.
     *
     * @return 'active'|'archived'|'drifted'|'syncing'
     */
    public function computeStatus(string $uuid): string
    {
        $workspace = $this->loadByUuid($uuid);
        $currentStatus = (string) $workspace->get('status');

        if ($currentStatus === 'archived') {
            return 'archived';
        }

        if ($currentStatus === 'syncing') {
            return 'syncing';
        }

        if ($this->isDrifted($workspace)) {
            return 'drifted';
        }

        return 'active';
    }

    /**
     * Check if local workspace is behind remote.
     *
     * Without last_commit_hash on workspace, drift detection requires
     * comparing local HEAD against remote. Currently returns false as
     * a safe default; full implementation deferred to DriftDetector.
     */
    public function isDrifted(Workspace $workspace): bool
    {
        $wsUuid = (string) ($workspace->get('uuid') ?? '');
        $repo = $this->repoResolver?->findLinkedRepo($wsUuid);

        if ($repo === null) {
            return false;
        }

        $repoPath = trim((string) ($repo->get('local_path') ?? ''));
        if ($repoPath === '' || ! is_dir($repoPath.'/.git')) {
            return false;
        }

        return false;
    }

    /**
     * Auto-sync a persistent workspace by pulling latest changes.
     *
     * Only works for persistent mode workspaces with a connected repository.
     * Returns true if a sync (git pull) was performed.
     */
    public function autoSync(string $uuid): bool
    {
        $workspace = $this->loadByUuid($uuid);

        if ($workspace->get('mode') !== 'persistent') {
            return false;
        }

        $repo = $this->repoResolver?->findLinkedRepo($uuid);
        if ($repo === null) {
            return false;
        }

        $repoPath = trim((string) ($repo->get('local_path') ?? ''));
        if ($repoPath === '' || ! is_dir($repoPath.'/.git')) {
            return false;
        }

        if ($this->isSyncingAtPath($repoPath)) {
            return false;
        }

        try {
            $this->gitRepositoryManager->pull($repoPath);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Rebuild an ephemeral workspace by destroying and re-cloning.
     *
     * Only works for ephemeral mode workspaces with a connected repository.
     * Triggered when drift exceeds threshold or manually.
     */
    public function rebuild(string $uuid): void
    {
        $workspace = $this->loadByUuid($uuid);

        if ($workspace->get('mode') !== 'ephemeral') {
            throw new \RuntimeException(sprintf('Cannot rebuild persistent workspace %s. Use autoSync instead.', $uuid));
        }

        $repo = $this->repoResolver?->findLinkedRepo($uuid);
        if ($repo === null) {
            throw new \RuntimeException(sprintf('Workspace %s has no repository connected.', $uuid));
        }

        $repoUrl = trim((string) ($repo->get('url') ?? ''));
        if ($repoUrl === '') {
            throw new \RuntimeException(sprintf('Workspace %s has no repository URL configured.', $uuid));
        }

        $branch = trim((string) ($repo->get('default_branch') ?? 'main'));
        $repoPath = trim((string) ($repo->get('local_path') ?? ''));

        if ($repoPath === '') {
            $repoPath = $this->gitRepositoryManager->buildWorkspaceRepoPath($uuid);
        }

        if (is_dir($repoPath)) {
            $this->removeDirectory($repoPath);
        }

        $this->gitRepositoryManager->clone($repoUrl, $repoPath, $branch);

        $repo->set('local_path', $repoPath);
        $this->repoRepo->save($repo);
    }

    /**
     * Check if a git operation is in progress (lock file exists).
     */
    public function isSyncing(Workspace $workspace): bool
    {
        $wsUuid = (string) ($workspace->get('uuid') ?? '');
        $repo = $this->repoResolver?->findLinkedRepo($wsUuid);

        if ($repo === null) {
            return false;
        }

        $repoPath = trim((string) ($repo->get('local_path') ?? ''));

        return $this->isSyncingAtPath($repoPath);
    }

    private function isSyncingAtPath(string $repoPath): bool
    {
        if ($repoPath === '') {
            return false;
        }

        return file_exists($repoPath.'/.git/index.lock');
    }

    private function loadByUuid(string $uuid): Workspace
    {
        $storage = $this->entityTypeManager->getStorage('workspace');
        $all = $storage->loadMultiple();

        foreach ($all as $entity) {
            if ($entity instanceof Workspace && $entity->get('uuid') === $uuid) {
                return $entity;
            }
        }

        throw new \RuntimeException(sprintf('Workspace not found: %s', $uuid));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }
}
