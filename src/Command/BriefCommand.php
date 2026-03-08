<?php

declare(strict_types=1);

namespace MyClaudia\Command;

use MyClaudia\DayBrief\DayBriefAssembler;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'myclaudia:brief', description: 'Show your Day Brief')]
final class BriefCommand extends Command
{
    public function __construct(private readonly DayBriefAssembler $assembler)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $brief = $this->assembler->assemble(tenantId: 'default', since: new \DateTimeImmutable('-24 hours'));

        $output->writeln('<info>Day Brief</info>');
        $output->writeln('');
        $output->writeln(sprintf('<comment>Recent events (%d)</comment>', count($brief['recent_events'])));
        foreach ($brief['recent_events'] as $event) {
            $output->writeln(sprintf('  [%s] %s', $event->get('source'), $event->get('type')));
        }
        $output->writeln('');
        $output->writeln(sprintf('<comment>Pending commitments (%d)</comment>', count($brief['pending_commitments'])));
        foreach ($brief['pending_commitments'] as $c) {
            $output->writeln(sprintf('  • %s (%.0f%% confidence)', $c->get('title'), $c->get('confidence') * 100));
        }
        if (!empty($brief['drifting_commitments'])) {
            $output->writeln('');
            $output->writeln('<error>Drifting (no activity 48h+)</error>');
            foreach ($brief['drifting_commitments'] as $c) {
                $output->writeln(sprintf('  ! %s', $c->get('title')));
            }
        }
        return Command::SUCCESS;
    }
}
