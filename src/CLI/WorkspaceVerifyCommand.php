<?php

declare(strict_types=1);

namespace Claudriel\CLI;

use Claudriel\Entity\Workspace;
use Claudriel\Support\WorkspaceRepoResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:workspace:verify', description: 'Stub verification for a workspace repository')]
final class WorkspaceVerifyCommand extends Command
{
    public function __construct(
        private readonly WorkspaceRepoResolver $resolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('workspace_uuid', InputArgument::REQUIRED, 'Workspace UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspaceUuid = (string) $input->getArgument('workspace_uuid');
        $workspace = $this->resolver->findWorkspace($workspaceUuid);

        if (! $workspace instanceof Workspace) {
            $output->writeln('Workspace not found.');

            return Command::FAILURE;
        }

        $workspace->set('ci_status', 'verification pending');
        $this->resolver->getWorkspaceRepository()->save($workspace);

        $output->writeln('last_commit_hash: '.(string) ($workspace->get('last_commit_hash') ?? ''));
        $output->writeln((string) $workspace->get('ci_status'));

        return Command::SUCCESS;
    }
}
