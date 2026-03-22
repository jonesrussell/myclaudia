<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Telescope\TelescopeServiceProvider;

#[AsCommand(name: 'claudriel:telescope', description: 'Query telescope observability entries')]
final class TelescopeCommand extends Command
{
    public function __construct(
        private readonly TelescopeServiceProvider $telescope,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('type', InputArgument::OPTIONAL, 'Entry type: request, query, event, cache', 'request');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Number of entries', '20');
        $this->addOption('clear', null, InputOption::VALUE_NONE, 'Clear all entries');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('clear')) {
            $this->telescope->getStore()->clear();
            $output->writeln('Telescope entries cleared.');

            return Command::SUCCESS;
        }

        $type = $input->getArgument('type');
        $limit = (int) $input->getOption('limit');

        $output->writeln($this->formatEntries($type, $limit));

        return Command::SUCCESS;
    }

    private function formatEntries(string $type, int $limit): string
    {
        $entries = $this->telescope->getStore()->query($type, $limit);

        if ($entries === []) {
            return "No entries found for type: {$type}";
        }

        $lines = [];
        foreach ($entries as $entry) {
            $time = $entry->createdAt->format('H:i:s.u');
            $summary = $this->summarizeEntry($entry->type, $entry->data);
            $lines[] = "[{$time}] {$summary}";
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function summarizeEntry(string $type, array $data): string
    {
        return match ($type) {
            'request' => sprintf(
                '%s %s → %d (%.1fms)',
                $data['method'] ?? '?',
                $data['uri'] ?? '?',
                $data['status_code'] ?? 0,
                $data['duration'] ?? 0,
            ),
            'query' => sprintf(
                '%s (%.1fms)%s',
                mb_substr($data['sql'] ?? '?', 0, 80),
                $data['duration'] ?? 0,
                ($data['slow'] ?? false) ? ' [SLOW]' : '',
            ),
            'event' => $data['event'] ?? '?',
            'cache' => sprintf(
                '%s %s',
                strtoupper($data['operation'] ?? '?'),
                $data['key'] ?? '?',
            ),
            default => json_encode($data, JSON_THROW_ON_ERROR),
        };
    }
}
