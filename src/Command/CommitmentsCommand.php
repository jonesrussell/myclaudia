<?php

declare(strict_types=1);

namespace MyClaudia\Command;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'myclaudia:commitments', description: 'List active commitments')]
final class CommitmentsCommand extends Command
{
    public function __construct(private readonly EntityRepositoryInterface $commitmentRepo)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $commitments = $this->commitmentRepo->findBy(['status' => 'active']);
        if (empty($commitments)) {
            $output->writeln('No active commitments.');
            return Command::SUCCESS;
        }
        foreach ($commitments as $c) {
            $output->writeln(sprintf('[%s] %s', strtoupper($c->get('status')), $c->get('title')));
        }
        return Command::SUCCESS;
    }
}
