<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Entity\Workspace;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:workspace:create', description: 'Create a new workspace')]
final class WorkspaceCreateCommand extends Command
{
    public function __construct(private readonly EntityRepositoryInterface $workspaceRepo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Workspace name');
        $this->addOption('description', null, InputOption::VALUE_OPTIONAL, 'Workspace description', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $description = (string) $input->getOption('description');

        $workspace = new Workspace([
            'name' => $name,
            'description' => $description,
        ]);

        $this->workspaceRepo->save($workspace);

        $output->writeln(sprintf('<info>Created workspace:</info> %s', $name));

        return Command::SUCCESS;
    }
}
