<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Workspace;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalWorkspaceController
{
    private const REPO_PATTERN = '/^[a-zA-Z0-9._-]+\/[a-zA-Z0-9._-]+$/';

    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
        private readonly string $tenantId,
        private readonly ?GitRepositoryManager $gitManager = null,
    ) {}

    public function listWorkspaces(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $limit = min((int) ($query['limit'] ?? 50), 100);

        $all = $this->workspaceRepo->findBy(['tenant_id' => $this->tenantId]);

        $items = [];
        $count = 0;
        foreach ($all as $workspace) {
            if ($count >= $limit) {
                break;
            }
            $items[] = [
                'uuid' => $workspace->get('uuid'),
                'name' => $workspace->get('name'),
                'description' => $workspace->get('description'),
                'status' => $workspace->get('status'),
                'mode' => $workspace->get('mode'),
            ];
            $count++;
        }

        return $this->jsonResponse(['workspaces' => $items, 'count' => $count]);
    }

    public function workspaceContext(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Workspace UUID required', 400);
        }

        $results = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        $workspace = $results[0] ?? null;

        if ($workspace === null) {
            return $this->jsonError('Workspace not found', 404);
        }

        return $this->jsonResponse([
            'uuid' => $workspace->get('uuid'),
            'name' => $workspace->get('name'),
            'description' => $workspace->get('description'),
            'mode' => $workspace->get('mode'),
            'status' => $workspace->get('status'),
            'saved_context' => $workspace->get('saved_context'),
        ]);
    }

    public function create(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $data = json_decode($httpRequest?->getContent() ?: '{}', true) ?: [];
        $name = trim((string) ($data['name'] ?? ''));

        if ($name === '' || mb_strlen($name) > 100) {
            return $this->jsonError('Workspace name is required (1-100 characters)', 400);
        }

        $mode = $data['mode'] ?? 'persistent';
        $description = $data['description'] ?? '';

        $workspace = new Workspace([
            'uuid' => $this->generateUuid(),
            'name' => $name,
            'description' => $description,
            'mode' => $mode,
            'status' => 'active',
            'tenant_id' => $this->tenantId,
        ]);
        $this->workspaceRepo->save($workspace);

        return $this->jsonResponse([
            'uuid' => $workspace->get('uuid'),
            'name' => $workspace->get('name'),
            'status' => 'active',
            'mode' => $mode,
            'created_at' => (new \DateTimeImmutable())->format('c'),
        ]);
    }

    public function delete(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        $uuid = $params['uuid'] ?? '';
        if ($uuid === '') {
            return $this->jsonError('Workspace UUID required', 400);
        }

        $results = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        $workspace = $results[0] ?? null;

        if ($workspace === null) {
            return $this->jsonError('Workspace not found', 404);
        }

        $this->workspaceRepo->delete($workspace);

        return $this->jsonResponse(['success' => true]);
    }

    public function cloneRepo(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        if ($this->authenticate($httpRequest) === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        if ($this->gitManager === null) {
            return $this->jsonError('Git operations not available', 500);
        }

        $uuid = $params['uuid'] ?? '';
        $data = json_decode($httpRequest?->getContent() ?: '{}', true) ?: [];
        $repo = trim((string) ($data['repo'] ?? ''));
        $branch = trim((string) ($data['branch'] ?? 'main'));

        if (! preg_match(self::REPO_PATTERN, $repo)) {
            return $this->jsonError('Invalid repo format. Expected: owner/name', 400);
        }

        $results = $this->workspaceRepo->findBy(['uuid' => $uuid, 'tenant_id' => $this->tenantId]);
        if (($results[0] ?? null) === null) {
            return $this->jsonError('Workspace not found', 404);
        }

        $repoUrl = sprintf('https://github.com/%s.git', $repo);
        $localPath = $this->gitManager->buildWorkspaceRepoPath($uuid);

        try {
            $this->gitManager->clone($repoUrl, $localPath, $branch);
        } catch (\RuntimeException $e) {
            return $this->jsonError('Clone failed: ' . $e->getMessage(), 500);
        }

        return $this->jsonResponse([
            'success' => true,
            'local_path' => $localPath,
            'branch' => $branch,
            'repo_url' => $repoUrl,
        ]);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF), random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
        );
    }

    private function authenticate(mixed $httpRequest): ?string
    {
        $auth = '';
        if ($httpRequest instanceof Request) {
            $auth = $httpRequest->headers->get('Authorization', '');
        }

        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return $this->apiTokenGenerator->validate(substr($auth, 7));
    }

    private function jsonResponse(array $data): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function jsonError(string $message, int $statusCode): SsrResponse
    {
        return new SsrResponse(
            content: json_encode(['error' => $message], JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
