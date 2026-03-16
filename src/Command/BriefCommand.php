<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Domain\DayBrief\Service\BriefSessionStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'claudriel:brief', description: 'Show your Day Brief')]
final class BriefCommand extends Command
{
    public function __construct(
        private readonly DayBriefAssembler $assembler,
        private readonly BriefSessionStore $sessionStore,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $since = $this->sessionStore->getLastBriefAt() ?? new \DateTimeImmutable('-24 hours');
        $brief = $this->assembler->assemble(tenantId: 'default', since: $since);

        $output->writeln('<info>Day Brief</info>');
        $output->writeln('');

        if (! empty($brief['schedule'])) {
            $output->writeln(sprintf('<comment>Schedule (%d)</comment>', count($brief['schedule'])));
            foreach ($brief['schedule'] as $item) {
                $time = $item['start_time'] ?? '';
                $output->writeln(sprintf('  • %s (%s)', $item['title'], $time));
            }
            $output->writeln('');
        }

        if (! empty($brief['job_hunt'])) {
            $output->writeln(sprintf('<comment>Job Hunt (%d)</comment>', count($brief['job_hunt'])));
            foreach ($brief['job_hunt'] as $item) {
                $output->writeln(sprintf('  • %s — %s', $item['title'], $item['source_name']));
            }
            $output->writeln('');
        }

        if (! empty($brief['people'])) {
            $output->writeln(sprintf('<comment>People (%d)</comment>', count($brief['people'])));
            foreach ($brief['people'] as $item) {
                $output->writeln(sprintf('  • %s: %s', $item['person_name'], $item['summary']));
            }
            $output->writeln('');
        }

        $pending = $brief['commitments']['pending'];
        $output->writeln(sprintf('<comment>Pending commitments (%d)</comment>', count($pending)));
        foreach ($pending as $c) {
            $output->writeln(sprintf('  • %s (%.0f%% confidence)', $c->get('title'), $c->get('confidence') * 100));
        }

        $drifting = $brief['commitments']['drifting'];
        if (! empty($drifting)) {
            $output->writeln('');
            $output->writeln('<error>Drifting (no activity 48h+)</error>');
            foreach ($drifting as $c) {
                $output->writeln(sprintf('  ! %s', $c->get('title')));
            }
        }

        if (! empty($brief['notifications'])) {
            $output->writeln('');
            $output->writeln(sprintf('<comment>Notifications (%d)</comment>', count($brief['notifications'])));
            foreach ($brief['notifications'] as $item) {
                $output->writeln(sprintf('  • %s', $item['title']));
            }
        }

        $this->sessionStore->recordBriefAt(new \DateTimeImmutable);

        return Command::SUCCESS;
    }
}
