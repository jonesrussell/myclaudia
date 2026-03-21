<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\GitHubSyncCommand;
use Claudriel\Entity\Integration;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Ingestion\EventHandler;
use Claudriel\Ingestion\GitHubNotificationNormalizer;
use Claudriel\Support\GitHubTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class GitHubSyncCommandTest extends TestCase
{
    public function test_command_name_is_correct(): void
    {
        $command = $this->buildCommand();
        $this->assertSame('claudriel:github:sync', $command->getName());
    }

    public function test_skips_when_no_active_integrations(): void
    {
        $integrationRepo = $this->createMock(EntityRepositoryInterface::class);
        $integrationRepo->method('findBy')->willReturn([]);

        $command = $this->buildCommand(integrationRepo: $integrationRepo);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No active GitHub integrations found', $tester->getDisplay());
    }

    public function test_skips_account_when_token_revoked(): void
    {
        $integration = new Integration([
            'iid' => 1,
            'account_id' => 'acct-1',
            'provider' => 'github',
            'status' => 'active',
            'access_token' => 'ghp_test',
        ]);

        $integrationRepo = $this->createMock(EntityRepositoryInterface::class);
        $integrationRepo->method('findBy')->willReturn([$integration]);

        $tokenManager = $this->createMock(GitHubTokenManagerInterface::class);
        $tokenManager->method('getValidAccessToken')
            ->willThrowException(new \RuntimeException('GitHub integration has been revoked'));

        $command = $this->buildCommand($tokenManager, $integrationRepo);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Skipping account acct-1', $tester->getDisplay());
        self::assertStringContainsString('revoked', $tester->getDisplay());
    }

    private function buildCommand(
        ?GitHubTokenManagerInterface $tokenManager = null,
        ?EntityRepositoryInterface $integrationRepo = null,
    ): GitHubSyncCommand {
        $tokenManager ??= $this->createMock(GitHubTokenManagerInterface::class);
        $dispatcher = new EventDispatcher;

        $eventRepo = new EntityRepository(
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
        $personRepo = new EntityRepository(
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $integrationRepo ??= $this->createMock(EntityRepositoryInterface::class);
        $integrationRepo->method('findBy')->willReturn([]);

        $eventHandler = new EventHandler($eventRepo, $personRepo);
        $normalizer = new GitHubNotificationNormalizer;

        return new GitHubSyncCommand($tokenManager, $eventHandler, $normalizer, $integrationRepo, 'test-tenant');
    }
}
