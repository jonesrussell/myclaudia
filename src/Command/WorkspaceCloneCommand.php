<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\Artifact;
use Claudriel\Entity\Workspace;
use Claudriel\Support\LinkedRepoLookup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:workspace:clone', description: 'Clone a workspace repository')]
final class WorkspaceCloneCommand extends Command
{
    use LinkedRepoLookup;

    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepo,
        private readonly EntityRepositoryInterface $artifactRepo,
        private readonly GitRepositoryManager $gitRepositoryManager,
        private readonly EntityRepositoryInterface $repoRepository,
        private readonly EntityRepositoryInterface $workspaceRepoRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('workspace_uuid', InputArgument::REQUIRED, 'Workspace UUID');
        $this->addArgument('repo_url', InputArgument::REQUIRED, 'Git repository URL');
        $this->addOption('branch', null, InputOption::VALUE_OPTIONAL, 'Git branch', 'main');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspaceUuid = trim((string) $input->getArgument('workspace_uuid'));
        $repoUrl = trim((string) $input->getArgument('repo_url'));
        $branch = trim((string) $input->getOption('branch'));

        $workspace = $this->findWorkspace($workspaceUuid);
        if ($workspace === null) {
            $output->writeln(sprintf('<error>Workspace not found:</error> %s', $workspaceUuid));

            return Command::FAILURE;
        }

        $artifact = $this->findRepoArtifact($workspaceUuid) ?? new Artifact([
            'name' => sprintf('%s Repository', (string) $workspace->get('name')),
            'workspace_uuid' => $workspaceUuid,
            'type' => 'repo',
        ]);

        $artifact->set('type', 'repo');
        $artifact->set('workspace_uuid', $workspaceUuid);
        $artifact->set('repo_url', $repoUrl);
        $artifact->set('branch', $branch !== '' ? $branch : 'main');

        $this->gitRepositoryManager->ensureLocalCopy($artifact);
        $this->artifactRepo->save($artifact);

        $repo = $this->findOrCreateRepo(
            (string) $artifact->get('local_path'),
            $repoUrl,
            $branch !== '' ? $branch : 'main',
        );
        $this->ensureJunction($workspaceUuid, (string) $repo->get('uuid'));

        $output->writeln(sprintf('<info>Cloned workspace repository:</info> %s', $artifact->get('local_path')));
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
