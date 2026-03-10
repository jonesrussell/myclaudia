<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\BriefStreamController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\Skill;
use Claudriel\Support\BriefSignal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class BriefStreamControllerTest extends TestCase
{
    public function testStreamEmitsBriefDataOnSignalChange(): void
    {
        $signalFile = sys_get_temp_dir() . '/brief_signal_stream_' . uniqid('', true) . '.txt';
        $signal = new BriefSignal($signalFile);
        $signal->touch();

        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();
        $etm = new EntityTypeManager($dispatcher, function ($def) use ($db, $dispatcher) {
            (new SqlSchemaHandler($def, $db))->ensureTable();
            return new SqlEntityStorage($def, $db, $dispatcher);
        });

        foreach ($this->entityTypes() as $type) {
            $etm->registerEntityType($type);
        }

        $controller = new BriefStreamController($etm);

        $output = [];
        $iterations = 0;

        $controller->streamLoop(
            $signalFile,
            outputCallback: function (string $data) use (&$output): void {
                $output[] = $data;
            },
            flushCallback: function (): void {},
            shouldStop: function () use (&$iterations): bool {
                $iterations++;
                return $iterations > 1;
            },
            sleepCallback: function (): void {},
        );

        $combined = implode('', $output);
        self::assertStringContainsString('retry:', $combined);
        self::assertStringContainsString('event: brief-update', $combined);
        self::assertStringContainsString('"schedule"', $combined);

        // Cleanup
        if (file_exists($signalFile)) {
            unlink($signalFile);
        }
    }

    /** @return list<EntityType> */
    private function entityTypes(): array
    {
        return [
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']),
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'skill', label: 'Skill', class: Skill::class, keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name']),
        ];
    }
}
