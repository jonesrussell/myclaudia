<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\IssueOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:issue:status', description: 'Show status of an issue run')]
final class IssueStatusCommand extends Command
{
    public function __construct(
        private readonly IssueOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'Run UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = $input->getArgument('uuid');
        $run = $this->orchestrator->getRun($uuid);

        if ($run === null) {
            $output->writeln("Run {$uuid} not found.");

            return Command::FAILURE;
        }

        $output->writeln($this->orchestrator->summarizeRun($run));

        return Command::SUCCESS;
    }
}
