<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Artifact;
use Claudriel\Entity\Workspace;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:workspace:pull', description: 'Pull the latest changes for a workspace repository')]
final class WorkspacePullCommand extends Command
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly EntityRepositoryInterface $artifactRepo,
        private readonly GitRepositoryManager $gitRepositoryManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('workspace_uuid', InputArgument::REQUIRED, 'Workspace UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspaceUuid = trim((string) $input->getArgument('workspace_uuid'));

        $workspace = $this->findWorkspace($workspaceUuid);
        if ($workspace === null) {
            $output->writeln(sprintf('<error>Workspace not found:</error> %s', $workspaceUuid));

            return Command::FAILURE;
        }

        $artifact = $this->findRepoArtifact($workspaceUuid);
        if ($artifact === null) {
            $output->writeln(sprintf('<error>Repository artifact not found for workspace:</error> %s', $workspaceUuid));

            return Command::FAILURE;
        }

        $this->gitRepositoryManager->ensureLocalCopy($artifact);
        $this->artifactRepo->save($artifact);

        $output->writeln(sprintf('<info>Updated workspace repository:</info> %s', $artifact->get('local_path')));
        $output->writeln(sprintf('<info>Latest commit:</info> %s', $artifact->get('last_commit')));

        return Command::SUCCESS;
    }

    private function findWorkspace(string $workspaceUuid): ?Workspace
    {
        $results = $this->workspaceRepo->findBy(['uuid' => $workspaceUuid]);
        $workspace = $results[0] ?? null;

        return $workspace instanceof Workspace ? $workspace : null;
    }

    private function findRepoArtifact(string $workspaceUuid): ?Artifact
    {
        $results = $this->artifactRepo->findBy(['workspace_uuid' => $workspaceUuid, 'type' => 'repo']);
        $artifact = $results[0] ?? null;

        return $artifact instanceof Artifact ? $artifact : null;
    }
}
