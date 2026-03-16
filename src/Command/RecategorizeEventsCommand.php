<?php

declare(strict_types=1);

namespace Claudriel\Command;

use Claudriel\Ingestion\EventCategorizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

#[AsCommand(name: 'claudriel:recategorize-events', description: 'Re-categorize existing events using EventCategorizer')]
final class RecategorizeEventsCommand extends Command
{
    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly EventCategorizer $categorizer = new EventCategorizer,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $storage = $this->entityTypeManager->getStorage('mc_event');
        $ids = $storage->getQuery()->execute();
        /** @var ContentEntityInterface[] $events */
        $events = $storage->loadMultiple($ids);

        $updated = 0;
        foreach ($events as $event) {
            $source = $event->get('source') ?? '';
            $type = $event->get('type') ?? '';
            $payloadJson = $event->get('payload') ?? '{}';
            $payload = json_decode($payloadJson, true) ?? [];

            $newCategory = $this->categorizer->categorize($source, $type, $payload);
            $oldCategory = $event->get('category') ?? 'notification';

            if ($newCategory !== $oldCategory) {
                $event->set('category', $newCategory);
                $storage->save($event);
                $updated++;
                $output->writeln(sprintf(
                    '  %s: %s -> %s (%s)',
                    $event->get('type') ?? 'unknown',
                    $oldCategory,
                    $newCategory,
                    $payload['subject'] ?? $payload['title'] ?? 'no title',
                ));
            }
        }

        $output->writeln(sprintf('<info>Re-categorized %d of %d events.</info>', $updated, count($events)));

        return Command::SUCCESS;
    }
}
