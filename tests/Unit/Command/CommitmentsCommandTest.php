<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\CommitmentsCommand;
use Claudriel\Entity\Commitment;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class CommitmentsCommandTest extends TestCase
{
    private EntityRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
    }

    public function testNoCommitmentsOutputsMessage(): void
    {
        $tester = new CommandTester(new CommitmentsCommand($this->repo));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No active commitments', $tester->getDisplay());
    }

    public function testListsActiveCommitments(): void
    {
        $commitment = new Commitment(['title' => 'Ship the feature', 'status' => 'active']);
        $this->repo->save($commitment);

        $tester = new CommandTester(new CommitmentsCommand($this->repo));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Ship the feature', $tester->getDisplay());
        self::assertStringContainsString('ACTIVE', $tester->getDisplay());
    }
}
