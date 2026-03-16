<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:workspaces', description: 'List workspaces')]
final class WorkspacesCommand extends Command
{
    public function __construct(private readonly EntityRepositoryInterface $workspaceRepo)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ContentEntityInterface[] $all */
        $all = $this->workspaceRepo->findBy([]);

        if (empty($all)) {
            $output->writeln('No workspaces found.');

            return Command::SUCCESS;
        }

        foreach ($all as $workspace) {
            $output->writeln(sprintf(
                '<info>%s</info> — %s (uuid: %s)',
                $workspace->get('name'),
                $workspace->get('description') ?: '(no description)',
                $workspace->get('uuid') ?: '(no uuid)',
            ));
        }

        return Command::SUCCESS;
    }
}
