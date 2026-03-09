<?php

declare(strict_types=1);

namespace MyClaudia\Command;

use MyClaudia\Domain\DayBrief\Assembler\DayBriefAssembler;
use MyClaudia\Domain\DayBrief\Service\BriefSessionStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'myclaudia:brief', description: 'Show your Day Brief')]
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

        $output->writeln(sprintf('<comment>Recent events (%d)</comment>', count($brief['recent_events'])));
        foreach ($brief['events_by_source'] as $source => $events) {
            $output->writeln(sprintf('  [%s]', $source));
            foreach ($events as $event) {
                $payload = json_decode($event->get('payload') ?? '{}', true) ?? [];
                $subject = $payload['subject'] ?? $event->get('type');
                $output->writeln(sprintf('    • %s', $subject));
            }
        }

        if (!empty($brief['people'])) {
            $output->writeln('');
            $output->writeln('<comment>People</comment>');
            foreach ($brief['people'] as $email => $name) {
                $output->writeln(sprintf('  %s <%s>', $name, $email));
            }
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

        $this->sessionStore->recordBriefAt(new \DateTimeImmutable());
        return Command::SUCCESS;
    }
}
