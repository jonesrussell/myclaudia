<?php

declare(strict_types=1);

namespace MyClaudia\Tests\Unit\Command;

use MyClaudia\Command\BriefCommand;
use MyClaudia\Domain\DayBrief\Assembler\DayBriefAssembler;
use MyClaudia\Domain\DayBrief\Service\BriefSessionStore;
use MyClaudia\Support\DriftDetector;
use MyClaudia\Entity\Commitment;
use MyClaudia\Entity\McEvent;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class BriefCommandTest extends TestCase
{
    public function testBriefCommandOutputsHeader(): void
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

        $assembler    = new DayBriefAssembler($eventRepo, $commitmentRepo, new DriftDetector($commitmentRepo));
        $sessionStore = new BriefSessionStore(sys_get_temp_dir() . '/brief_test_' . uniqid('', true) . '.txt');
        $command      = new BriefCommand($assembler, $sessionStore);
        $tester       = new CommandTester($command);

        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Day Brief', $tester->getDisplay());
        self::assertStringContainsString('Recent events (0)', $tester->getDisplay());
    }
}
