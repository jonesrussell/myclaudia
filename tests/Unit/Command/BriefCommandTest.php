<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\BriefCommand;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Claudriel\Support\DriftDetector;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
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
        self::assertStringContainsString('Pending commitments (0)', $tester->getDisplay());
    }
}
