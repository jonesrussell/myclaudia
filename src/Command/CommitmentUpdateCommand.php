<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

#[AsCommand(name: 'claudriel:commitment:update', description: 'Update a commitment status')]
final class CommitmentUpdateCommand extends Command
{
    private const ACTION_MAP = [
        'done' => 'done',
        'ignore' => 'ignored',
        'track' => 'active',
    ];

    public function __construct(private readonly EntityRepositoryInterface $commitmentRepo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('uuid', InputArgument::REQUIRED, 'Commitment UUID');
        $this->addArgument('action', InputArgument::REQUIRED, 'Action: done, ignore, track');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = (string) $input->getArgument('uuid');
        $action = (string) $input->getArgument('action');

        if (! array_key_exists($action, self::ACTION_MAP)) {
            $output->writeln(sprintf('<error>Invalid action "%s". Use: done, ignore, track</error>', $action));

            return Command::FAILURE;
        }

        /** @var ContentEntityInterface[] $results */
        $results = $this->commitmentRepo->findBy(['uuid' => $uuid]);
        $commitment = $results[0] ?? null;

        if ($commitment === null) {
            $output->writeln(sprintf('<error>Commitment "%s" not found.</error>', $uuid));

            return Command::FAILURE;
        }

        $commitment->set('status', self::ACTION_MAP[$action]);
        $this->commitmentRepo->save($commitment);

        $output->writeln(sprintf('Commitment "%s" marked as %s.', $uuid, self::ACTION_MAP[$action]));

        return Command::SUCCESS;
    }
}
