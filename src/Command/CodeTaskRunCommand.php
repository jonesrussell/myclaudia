<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\CodeTask\CodeTaskRunner;
use Claudriel\Domain\Git\GitRepositoryManager;
use Claudriel\Entity\CodeTask;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:code-task:run', description: 'Execute a queued code task via Claude Code CLI')]
final class CodeTaskRunCommand extends Command
{
    public function __construct(
        private readonly EntityRepositoryInterface $codeTaskRepo,
        private readonly CodeTaskRunner $runner,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'Code task UUID');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = (string) $input->getArgument('uuid');

        $tasks = $this->codeTaskRepo->findBy(['uuid' => $uuid]);
        if ($tasks === []) {
            $output->writeln('<error>Code task not found: '.$uuid.'</error>');

            return Command::FAILURE;
        }

        $task = $tasks[0];
        if (! $task instanceof CodeTask) {
            $output->writeln('<error>Code task not found: '.$uuid.'</error>');

            return Command::FAILURE;
        }

        $gitManager = new GitRepositoryManager;
        $workspaceUuid = (string) $task->get('workspace_uuid');
        $repoPath = $gitManager->buildWorkspaceRepoPath($workspaceUuid);

        $output->writeln(sprintf('Running code task %s against %s...', $uuid, $repoPath));

        $this->runner->run($task, $repoPath);

        $output->writeln(sprintf('Task %s finished with status: %s', $uuid, (string) $task->get('status')));

        return $task->get('status') === 'completed' ? Command::SUCCESS : Command::FAILURE;
    }
}
