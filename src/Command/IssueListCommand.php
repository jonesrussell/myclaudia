<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\IssueOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:issue:list', description: 'List issue runs')]
final class IssueListCommand extends Command
{
    public function __construct(
        private readonly IssueOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('status', 's', InputOption::VALUE_OPTIONAL, 'Filter by status');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status = $input->getOption('status');
        $runs = $this->orchestrator->listRuns(is_string($status) ? $status : null);

        if ($runs === []) {
            $output->writeln('No issue runs found.');

            return Command::SUCCESS;
        }

        foreach ($runs as $run) {
            $output->writeln($this->orchestrator->summarizeRun($run));
            $output->writeln('---');
        }

        return Command::SUCCESS;
    }
}
