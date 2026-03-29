<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\CommitmentUpdateController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class CommitmentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'cid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'title' => ['type' => 'string', 'required' => true],
                'status' => ['type' => 'string'],
                'workflow_state' => ['type' => 'string'],
                'confidence' => ['type' => 'float'],
                'direction' => ['type' => 'string'],
                'due_date' => ['type' => 'datetime'],
                'person_uuid' => ['type' => 'string'],
                'workspace_uuid' => ['type' => 'string'],
                'source' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'importance_score' => ['type' => 'float'],
                'access_count' => ['type' => 'integer'],
                'last_accessed_at' => ['type' => 'datetime'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'commitment_extraction_log',
            label: 'Commitment Extraction Log',
            class: CommitmentExtractionLog::class,
            keys: ['id' => 'celid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'celid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'event_uuid' => ['type' => 'string'],
                'model' => ['type' => 'string'],
                'prompt_tokens' => ['type' => 'integer'],
                'completion_tokens' => ['type' => 'integer'],
                'candidates_count' => ['type' => 'integer'],
                'saved_count' => ['type' => 'integer'],
                'raw_response' => ['type' => 'text_long'],
                'failure_category' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        // Commitment CRUD routes removed — now served by /api/graphql (#180)

        $router->addRoute(
            'claudriel.commitment.update',
            RouteBuilder::create('/commitments/{uuid}')
                ->controller(CommitmentUpdateController::class.'::update')
                ->allowAll()
                ->methods('PATCH')
                ->build(),
        );
    }
}
