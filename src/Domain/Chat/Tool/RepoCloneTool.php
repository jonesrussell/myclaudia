<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;

final class RepoCloneTool implements AgentToolInterface
{
    public function __construct(
        private readonly string $apiBaseUrl,
        private readonly string $accountId,
        private readonly string $tenantId,
        private readonly InternalApiTokenGenerator $tokenGenerator,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'repo_clone',
            'description' => 'Clone a GitHub repository into a workspace. The repo will be available for code tasks after cloning.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'workspace_uuid' => [
                        'type' => 'string',
                        'description' => 'UUID of the workspace to clone into.',
                    ],
                    'repo' => [
                        'type' => 'string',
                        'description' => 'GitHub repo in owner/name format (e.g. "jonesrussell/blog").',
                    ],
                    'branch' => [
                        'type' => 'string',
                        'description' => 'Optional branch to check out after cloning.',
                    ],
                ],
                'required' => ['workspace_uuid', 'repo'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $workspaceUuid = trim((string) ($args['workspace_uuid'] ?? ''));
        $repo = trim((string) ($args['repo'] ?? ''));

        if ($workspaceUuid === '' || $repo === '') {
            return ['error' => 'workspace_uuid and repo are required'];
        }

        if (preg_match('#^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+$#', $repo) !== 1) {
            return ['error' => 'repo must be in owner/name format'];
        }

        $body = ['repo' => $repo];
        $branch = trim((string) ($args['branch'] ?? ''));
        if ($branch !== '') {
            $body['branch'] = $branch;
        }

        $token = $this->tokenGenerator->generate($this->accountId);
        $url = rtrim($this->apiBaseUrl, '/').'/api/internal/workspaces/'.urlencode($workspaceUuid).'/clone-repo';

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [
                    'Content-Type: application/json',
                    'Authorization: Bearer '.$token,
                    'X-Tenant-Id: '.$this->tenantId,
                ]),
                'content' => json_encode($body, JSON_THROW_ON_ERROR),
                'ignore_errors' => true,
            ],
        ]);

        $response = file_get_contents($url, false, $context);
        if ($response === false) {
            return ['error' => 'Failed to reach repo clone API'];
        }

        $data = json_decode($response, true);
        if (! is_array($data)) {
            return ['error' => 'Invalid response from repo clone API'];
        }

        return $data;
    }
}
