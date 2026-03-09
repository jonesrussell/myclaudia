<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\CommitmentUpdateCommand;
use Claudriel\Entity\Commitment;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class CommitmentUpdateCommandTest extends TestCase
{
    private EntityRepository $repo;
    private CommitmentUpdateCommand $command;

    protected function setUp(): void
    {
        $this->repo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver(),
            new EventDispatcher(),
        );
        $this->command = new CommitmentUpdateCommand($this->repo);
    }

    private function saveCommitment(string $uuid): Commitment
    {
        $commitment = new Commitment(['title' => 'Test commitment', 'status' => 'pending', 'uuid' => $uuid]);
        $this->repo->save($commitment);
        return $commitment;
    }

    public function testMarkAsDone(): void
    {
        $uuid = 'aaaaaaaa-0001-0001-0001-aaaaaaaaaaaa';
        $this->saveCommitment($uuid);

        $tester = new CommandTester($this->command);
        $tester->execute(['uuid' => $uuid, 'action' => 'done']);

        self::assertSame(0, $tester->getStatusCode());
        $results = $this->repo->findBy(['uuid' => $uuid]);
        self::assertSame('done', $results[0]->get('status'));
    }

    public function testMarkAsIgnored(): void
    {
        $uuid = 'aaaaaaaa-0002-0002-0002-aaaaaaaaaaaa';
        $this->saveCommitment($uuid);

        $tester = new CommandTester($this->command);
        $tester->execute(['uuid' => $uuid, 'action' => 'ignore']);

        self::assertSame(0, $tester->getStatusCode());
        $results = $this->repo->findBy(['uuid' => $uuid]);
        self::assertSame('ignored', $results[0]->get('status'));
    }

    public function testMarkAsTracked(): void
    {
        $uuid = 'aaaaaaaa-0003-0003-0003-aaaaaaaaaaaa';
        $this->saveCommitment($uuid);

        $tester = new CommandTester($this->command);
        $tester->execute(['uuid' => $uuid, 'action' => 'track']);

        self::assertSame(0, $tester->getStatusCode());
        $results = $this->repo->findBy(['uuid' => $uuid]);
        self::assertSame('active', $results[0]->get('status'));
    }

    public function testUnknownUuidReturnsFailure(): void
    {
        $tester = new CommandTester($this->command);
        $tester->execute(['uuid' => 'does-not-exist', 'action' => 'done']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('not found', $tester->getDisplay());
    }

    public function testInvalidActionReturnsFailure(): void
    {
        $uuid = 'aaaaaaaa-0004-0004-0004-aaaaaaaaaaaa';
        $this->saveCommitment($uuid);

        $tester = new CommandTester($this->command);
        $tester->execute(['uuid' => $uuid, 'action' => 'explode']);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('Invalid action', $tester->getDisplay());
    }
}
