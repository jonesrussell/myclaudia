<?php

declare(strict_types=1);

namespace Claudriel\CLI;

use Claudriel\AI\CodexExecutionPipeline;
use Claudriel\AI\PromptBuilder;
use Claudriel\Domain\Git\GitOperator;
use Claudriel\Entity\Workspace;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:workspace:iterate', description: 'Run a Codex iteration against a workspace')]
final class WorkspaceIterateCommand extends Command
{
    public function __construct(
        private readonly EntityRepositoryInterface $workspaceRepository,
        private readonly EntityRepositoryInterface $operationRepository,
        private readonly PromptBuilder $promptBuilder,
        private readonly GitOperator $gitOperator,
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

        $pipeline = new CodexExecutionPipeline(
            $this->promptBuilder,
            $this->gitOperator,
            $this->workspaceRepository,
            $this->operationRepository,
        );
        $pipeline->execute($workspace, $instruction);

        $output->writeln('Iteration complete');

        return Command::SUCCESS;
    }
}
