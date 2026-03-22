<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Command;

use Claudriel\Command\TelescopeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Waaseyaa\Telescope\Storage\SqliteTelescopeStore;
use Waaseyaa\Telescope\TelescopeServiceProvider;

final class TelescopeCommandTest extends TestCase
{
    private TelescopeServiceProvider $telescope;

    private CommandTester $tester;

    protected function setUp(): void
    {
        $store = SqliteTelescopeStore::createInMemory();
        $this->telescope = new TelescopeServiceProvider(store: $store);
        $command = new TelescopeCommand($this->telescope);
        $this->tester = new CommandTester($command);
    }

    public function test_formats_request_entries(): void
    {
        $this->telescope->getRequestRecorder()->record('GET', '/brief', 200, 12.5);
        $this->telescope->getRequestRecorder()->record('POST', '/graphql', 200, 45.3);

        $this->tester->execute(['type' => 'request']);
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('GET', $display);
        self::assertStringContainsString('/brief', $display);
        self::assertStringContainsString('200', $display);
        self::assertStringContainsString('POST', $display);
        self::assertStringContainsString('/graphql', $display);
    }

    public function test_filters_by_type(): void
    {
        $this->telescope->getRequestRecorder()->record('GET', '/brief', 200, 12.5);
        $this->telescope->getEventRecorder()->record('EntitySaved', ['id' => 1]);

        $this->tester->execute(['type' => 'request']);
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('/brief', $display);
        self::assertStringNotContainsString('EntitySaved', $display);
    }

    public function test_shows_empty_message_when_no_entries(): void
    {
        $this->tester->execute(['type' => 'request']);
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('No entries', $display);
    }

    public function test_clear_removes_all_entries(): void
    {
        $this->telescope->getRequestRecorder()->record('GET', '/brief', 200, 12.5);
        $this->telescope->getRequestRecorder()->record('POST', '/graphql', 200, 45.3);

        $this->tester->execute(['--clear' => true]);
        $display = $this->tester->getDisplay();

        self::assertStringContainsString('Telescope entries cleared', $display);

        $store = $this->telescope->getStore();
        self::assertSame([], $store->query('request', 100));
    }

    public function test_limit_constrains_results(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->telescope->getRequestRecorder()->record('GET', "/page/{$i}", 200, 10.0);
        }

        $this->tester->execute(['type' => 'request', '--limit' => '2']);
        $display = $this->tester->getDisplay();

        $lines = array_filter(explode("\n", trim($display)), fn (string $line) => str_starts_with($line, '['));
        self::assertCount(2, $lines);
    }
}
