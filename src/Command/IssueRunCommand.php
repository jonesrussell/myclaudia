<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\IssueOrchestrator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:issue:run', description: 'Create and start a run for a GitHub issue')]
final class IssueRunCommand extends Command
{
    public function __construct(
        private readonly IssueOrchestrator $orchestrator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('number', InputArgument::REQUIRED, 'GitHub issue number');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $issueNumber = (int) $input->getArgument('number');

        $output->writeln("Creating run for issue #{$issueNumber}...");

        $run = $this->orchestrator->createRun($issueNumber);
        $output->writeln("Run created: {$run->get('uuid')}");

        $this->orchestrator->startRun($run);
        $output->writeln($this->orchestrator->summarizeRun($run));

        return Command::SUCCESS;
    }
}
