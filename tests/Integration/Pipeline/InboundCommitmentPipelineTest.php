<?php

declare(strict_types=1);

namespace Claudriel\Tests\Integration\Pipeline;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Ingestion\CommitmentHandler;
use Claudriel\Ingestion\Pipeline\CommitmentExtractionStep;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\AI\Pipeline\PipelineContext;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class InboundCommitmentPipelineTest extends TestCase
{
    public function test_full_pipeline_saves_inbound_commitment(): void
    {
        // Mock AI client returns an inbound commitment
        $aiClient = new class {
            public function complete(string $prompt): string
            {
                return json_encode([[
                    'title' => 'Send the signed contract',
                    'confidence' => 0.9,
                    'direction' => 'inbound',
                ]]);
            }
        };

        $dispatcher = new EventDispatcher;
        $commitmentRepo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
        $logRepo = new EntityRepository(
            new EntityType(id: 'commitment_extraction_log', label: 'Log', class: CommitmentExtractionLog::class, keys: ['id' => 'celid', 'uuid' => 'uuid']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        // Step 1: Extract commitments from email body
        $step = new CommitmentExtractionStep($aiClient);
        $context = new PipelineContext(pipelineId: 'test', startedAt: time());
        $result = $step->process([
            'body' => 'I will send you the signed contract by Friday.',
            'from_email' => 'client@example.com',
        ], $context);

        self::assertTrue($result->success);
        $candidates = $result->output['commitments'];
        self::assertCount(1, $candidates);
        self::assertSame('inbound', $candidates[0]['direction']);

        // Step 2: Handler saves the commitment
        $event = new McEvent([
            'eid' => 1,
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => json_encode(['thread_id' => 'thread-100', 'subject' => 'Contract', 'from_email' => 'client@example.com']),
            'occurred' => (new \DateTimeImmutable)->format(\DateTimeInterface::ATOM),
            'tenant_id' => 'test-tenant',
        ]);
        $handler = new CommitmentHandler($commitmentRepo, $logRepo);
        $handler->handle($candidates, $event, 'person-uuid-1', 'test-tenant');

        // Verify saved commitment has correct direction
        $saved = $commitmentRepo->findBy([]);
        self::assertCount(1, $saved);
        $commitment = array_values($saved)[0];
        self::assertSame('Send the signed contract', $commitment->get('title'));
        self::assertSame('inbound', $commitment->get('direction'));
        self::assertSame('pending', $commitment->get('status'));
        self::assertSame('test-tenant', $commitment->get('tenant_id'));
    }
}
