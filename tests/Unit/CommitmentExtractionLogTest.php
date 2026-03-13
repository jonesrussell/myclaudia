<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit;

use Claudriel\Entity\Commitment;
use Claudriel\Entity\CommitmentExtractionLog;
use Claudriel\Entity\McEvent;
use Claudriel\Ingestion\CommitmentHandler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class CommitmentExtractionLogTest extends TestCase
{
    public function test_low_confidence_extraction_is_logged_without_creating_commitment(): void
    {
        $commitmentRepo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
        $logRepo = new EntityRepository(
            new EntityType(id: 'commitment_extraction_log', label: 'Commitment Extraction Log', class: CommitmentExtractionLog::class, keys: ['id' => 'celid', 'uuid' => 'uuid']),
            new InMemoryStorageDriver,
            new EventDispatcher,
        );
        $handler = new CommitmentHandler($commitmentRepo, $logRepo);
        $event = new McEvent([
            'source' => 'gmail',
            'type' => 'message.received',
            'payload' => '{"subject":"Need help","body":"Can you maybe send this later?"}',
        ]);

        $handler->handle(
            [['title' => 'Send this later', 'confidence' => 0.5]],
            $event,
            personId: 'person-1',
            tenantId: 'user-1',
        );

        self::assertCount(0, $commitmentRepo->findBy([]));

        $logs = $logRepo->findBy([]);
        self::assertCount(1, $logs);
        self::assertNull($logs[0]->get('mc_event_id'));
        self::assertSame('{"subject":"Need help","body":"Can you maybe send this later?"}', $logs[0]->get('raw_event_payload'));
        self::assertSame('{"title":"Send this later","confidence":0.5}', $logs[0]->get('extracted_commitment_payload'));
        self::assertSame(0.5, $logs[0]->get('confidence'));
        self::assertSame('insufficient_context', $logs[0]->get('failure_category'));
        self::assertNotEmpty($logs[0]->get('created_at'));
    }
}
