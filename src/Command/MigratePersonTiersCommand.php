<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Support\PersonTierClassifier;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:migrate-person-tiers', description: 'Backfill tier field on existing Person entities')]
final class MigratePersonTiersCommand extends Command
{
    public function __construct(private readonly EntityRepositoryInterface $personRepo)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ContentEntityInterface[] $persons */
        $persons = $this->personRepo->findBy([]);
        $updated = 0;

        foreach ($persons as $person) {
            $email = $person->get('email') ?? '';
            $tier = PersonTierClassifier::classify($email, $person->get('name'));

            if ($person->get('tier') !== $tier) {
                $person->set('tier', $tier);
                $this->personRepo->save($person);
                $updated++;
            }
        }

        $output->writeln(sprintf('Updated %d person(s).', $updated));

        return Command::SUCCESS;
    }
}
