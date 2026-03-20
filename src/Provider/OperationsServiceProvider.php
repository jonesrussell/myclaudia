<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Entity\Integration;
use Claudriel\Entity\IssueRun;
use Claudriel\Entity\Operation;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;

final class OperationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'operation',
            label: 'Operation',
            class: Operation::class,
            keys: ['id' => 'opid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'opid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'workspace_id' => ['type' => 'string'],
                'input_instruction' => ['type' => 'text_long'],
                'generated_prompt' => ['type' => 'text_long'],
                'model_response' => ['type' => 'text_long'],
                'applied_patch' => ['type' => 'text_long'],
                'commit_hash' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'issue_run',
            label: 'Issue Run',
            class: IssueRun::class,
            keys: ['id' => 'irid', 'uuid' => 'uuid', 'label' => 'issue_title'],
            group: 'orchestration',
            fieldDefinitions: [
                'issue_number' => ['type' => 'integer', 'label' => 'Issue Number'],
                'issue_title' => ['type' => 'string', 'label' => 'Issue Title'],
                'issue_body' => ['type' => 'text_long', 'label' => 'Issue Body'],
                'milestone_title' => ['type' => 'string', 'label' => 'Milestone'],
                'workspace_id' => ['type' => 'integer', 'label' => 'Workspace ID'],
                'status' => ['type' => 'string', 'label' => 'Status'],
                'branch_name' => ['type' => 'string', 'label' => 'Branch Name'],
                'pr_url' => ['type' => 'string', 'label' => 'PR URL'],
                'last_agent_output' => ['type' => 'text_long', 'label' => 'Last Agent Output'],
                'event_log' => ['type' => 'text_long', 'label' => 'Event Log'],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'integration',
            label: 'Integration',
            class: Integration::class,
            keys: ['id' => 'iid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'iid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'type' => ['type' => 'string'],
                'config' => ['type' => 'text_long'],
                'status' => ['type' => 'string'],
                'account_id' => ['type' => 'string'],
                'provider' => ['type' => 'string'],
                'access_token' => ['type' => 'text_long'],
                'refresh_token' => ['type' => 'text_long'],
                'token_expires_at' => ['type' => 'string'],
                'scopes' => ['type' => 'text_long'],
                'provider_email' => ['type' => 'string'],
                'metadata' => ['type' => 'text_long'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }
}
