<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'myclaudia:skills', description: 'List skills in the vault')]
final class SkillsCommand extends Command
{
    public function __construct(private readonly EntityRepositoryInterface $skillRepo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('keyword', 'k', InputOption::VALUE_REQUIRED, 'Filter skills by trigger keyword');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $all = $this->skillRepo->findBy([]);
        $keyword = $input->getOption('keyword');

        if ($keyword !== null) {
            $keyword = strtolower((string) $keyword);
            $all = array_values(array_filter($all, function ($skill) use ($keyword): bool {
                $keywords = strtolower($skill->get('trigger_keywords') ?? '');
                $parts = array_map('trim', explode(',', $keywords));

                return in_array($keyword, $parts, true);
            }));
        }

        if (empty($all)) {
            $output->writeln('No skills found.');

            return Command::SUCCESS;
        }

        foreach ($all as $skill) {
            $output->writeln(sprintf(
                '<info>%s</info> — %s',
                $skill->get('name'),
                $skill->get('description') ?: '(no description)',
            ));
            $output->writeln(sprintf('  Keywords: %s', $skill->get('trigger_keywords') ?: '(none)'));
        }

        return Command::SUCCESS;
    }
}
