<?php

declare(strict_types=1);

namespace Claudriel\CLI;

use Claudriel\Entity\Repo;
use Claudriel\Entity\Workspace;
use Claudriel\Support\LinkedRepoLookup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:workspace:verify', description: 'Stub verification for a workspace repository')]
final class WorkspaceVerifyCommand extends Command
{
    use LinkedRepoLookup;

    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepository,
        private readonly EntityRepositoryInterface $repoRepository,
        private readonly EntityRepositoryInterface $workspaceRepoRepository,
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
        $results = $this->workspaceRepository->findBy(['uuid' => $workspaceUuid]);
        $workspace = $results[0] ?? null;

        if (! $workspace instanceof Workspace) {
            $output->writeln('Workspace not found.');

            return Command::FAILURE;
        }

        $repo = $this->findLinkedRepo($workspaceUuid);

        if ($repo instanceof Repo) {
            $repo->set('ci_status', 'verification pending');
            $this->repoRepository->save($repo);
        }

        $output->writeln('last_commit_hash: '.(string) ($repo?->get('last_commit_hash') ?? ''));
        $output->writeln((string) ($repo?->get('ci_status') ?? 'no linked repo'));

        return Command::SUCCESS;
    }
}
