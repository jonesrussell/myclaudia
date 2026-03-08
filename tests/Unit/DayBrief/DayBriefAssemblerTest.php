<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\DayBrief;

use MyClaudia\DayBrief\DayBriefAssembler;
use MyClaudia\DriftDetector;
use MyClaudia\Entity\Commitment;
use MyClaudia\Entity\McEvent;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class DayBriefAssemblerTest extends TestCase
{
    public function testAssemblesBriefFromEntities(): void
    {
        $dispatcher = new EventDispatcher();

        $eventRepo = new EntityRepository(
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']),
            new InMemoryStorageDriver(),
            $dispatcher,
        );
        $commitmentRepo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            $dispatcher,
        );
        $event = new McEvent(['source' => 'gmail', 'type' => 'message.received', 'payload' => '{}', 'occurred' => (new \DateTimeImmutable('-2 hours'))->format('Y-m-d H:i:s'), 'tenant_id' => 'user-1']);
        $eventRepo->save($event);

        $commitment = new Commitment(['title' => 'Reply to Jane', 'status' => 'pending', 'confidence' => 0.85, 'tenant_id' => 'user-1']);
        $commitmentRepo->save($commitment);

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, new DriftDetector($commitmentRepo));
        $brief     = $assembler->assemble(tenantId: 'user-1', since: new \DateTimeImmutable('-24 hours'));

        self::assertCount(1, $brief['recent_events']);
        self::assertCount(1, $brief['pending_commitments']);
        self::assertIsArray($brief['drifting_commitments']);
    }
}
