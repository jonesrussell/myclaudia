<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;

final class CodeTaskCreateTool implements AgentToolInterface
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
            'name' => 'code_task_create',
            'description' => 'Create a code task that uses Claude Code to make changes to a GitHub repository. The task will clone the repo, create a branch, apply the requested changes, and open a pull request. Use code_task_status to check progress.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'repo' => [
                        'type' => 'string',
                        'description' => 'GitHub repo in owner/name format (e.g. "jonesrussell/blog").',
                    ],
                    'prompt' => [
                        'type' => 'string',
                        'description' => 'Description of the code changes to make.',
                    ],
                    'branch_name' => [
                        'type' => 'string',
                        'description' => 'Optional branch name. Auto-generated from prompt if omitted.',
                    ],
                ],
                'required' => ['repo', 'prompt'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $repo = trim((string) ($args['repo'] ?? ''));
        $prompt = trim((string) ($args['prompt'] ?? ''));

        if ($repo === '' || $prompt === '') {
            return ['error' => 'repo and prompt are required'];
        }

        if (preg_match('#^[a-zA-Z0-9._-]+/[a-zA-Z0-9._-]+$#', $repo) !== 1) {
            return ['error' => 'repo must be in owner/name format'];
        }

        $body = ['repo' => $repo, 'prompt' => $prompt];
        $branchName = trim((string) ($args['branch_name'] ?? ''));
        if ($branchName !== '') {
            $body['branch_name'] = $branchName;
        }

        $token = $this->tokenGenerator->generate($this->accountId);
        $url = rtrim($this->apiBaseUrl, '/').'/api/internal/code-tasks/create';

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
            return ['error' => 'Failed to reach code task API'];
        }

        $data = json_decode($response, true);
        if (! is_array($data)) {
            return ['error' => 'Invalid response from code task API'];
        }

        return $data;
    }
}
