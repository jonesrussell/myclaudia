<?php

declare(strict_types=1);

namespace Claudriel\CLI;

use Claudriel\AI\CodexExecutionPipeline;
use Claudriel\AI\PromptBuilder;
use Claudriel\Domain\Git\GitOperator;
use Claudriel\Entity\Workspace;
use Claudriel\Support\LinkedRepoLookup;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:workspace:run-loop', description: 'Continuously run Codex iterations for a workspace')]
final class WorkspaceRunLoopCommand extends Command
{
    use LinkedRepoLookup;

    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepository,
        private readonly EntityRepositoryInterface $operationRepository,
        private readonly PromptBuilder $promptBuilder,
        private readonly GitOperator $gitOperator,
        private readonly EntityRepositoryInterface $repoRepository,
        private readonly EntityRepositoryInterface $workspaceRepoRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('workspace_uuid', InputArgument::REQUIRED, 'Workspace UUID');
        $this->addArgument('instruction', InputArgument::REQUIRED, 'Natural-language instruction');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $workspaceUuid = (string) $input->getArgument('workspace_uuid');
        $instruction = (string) $input->getArgument('instruction');

        $results = $this->workspaceRepository->findBy(['uuid' => $workspaceUuid]);
        $workspace = $results[0] ?? null;

        if (! $workspace instanceof Workspace) {
            $output->writeln('Workspace not found.');

            return Command::FAILURE;
        }

        $repo = $this->findLinkedRepo($workspaceUuid);

        if ($repo === null || trim((string) ($repo->get('local_path') ?? '')) === '') {
            $output->writeln('Workspace repo_path is missing.');

            return Command::FAILURE;
        }

        $pipeline = new CodexExecutionPipeline(
            $this->promptBuilder,
            $this->gitOperator,
            $this->workspaceRepository,
            $this->operationRepository,
        );

        $iteration = 0;

        while (true) {
            try {
                $iteration++;
                $pipeline->execute($workspace, $instruction);
                $output->writeln(sprintf('Iteration %d complete', $iteration));
                sleep(5);
            } catch (\Throwable $throwable) {
                $output->writeln(sprintf('Iteration %d failed: %s', $iteration, $throwable->getMessage()));

                return Command::FAILURE;
            }
        }
    }
}
