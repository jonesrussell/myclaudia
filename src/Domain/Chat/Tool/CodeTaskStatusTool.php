<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat\Tool;

use Claudriel\Domain\Chat\AgentToolInterface;
use Claudriel\Entity\CodeTask;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class CodeTaskStatusTool implements AgentToolInterface
{
    public function __construct(
        private readonly EntityRepositoryInterface $codeTaskRepo,
    ) {}

    public function definition(): array
    {
        return [
            'name' => 'code_task_status',
            'description' => 'Check the status of a code task. Returns the current status, PR URL (if created), summary, and any errors.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'task_uuid' => [
                        'type' => 'string',
                        'description' => 'UUID of the code task to check.',
                    ],
                ],
                'required' => ['task_uuid'],
            ],
        ];
    }

    public function execute(array $args): array
    {
        $uuid = trim((string) ($args['task_uuid'] ?? ''));
        if ($uuid === '') {
            return ['error' => 'task_uuid is required'];
        }

        $tasks = $this->codeTaskRepo->findBy(['uuid' => $uuid]);
        if ($tasks === []) {
            return ['error' => 'Code task not found'];
        }

        $task = $tasks[0];
        if (! $task instanceof CodeTask) {
            return ['error' => 'Code task not found'];
        }

        return [
            'uuid' => $task->get('uuid'),
            'status' => $task->get('status'),
            'branch_name' => $task->get('branch_name'),
            'pr_url' => $task->get('pr_url'),
            'summary' => $task->get('summary'),
            'diff_preview' => $task->get('diff_preview'),
            'error' => $task->get('error'),
            'started_at' => $task->get('started_at'),
            'completed_at' => $task->get('completed_at'),
        ];
    }
}
