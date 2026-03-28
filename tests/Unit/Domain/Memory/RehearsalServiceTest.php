<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Memory;

use Claudriel\Domain\Memory\RehearsalService;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\Person;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\EntityStorage\Driver\InMemoryStorageDriver;
use Waaseyaa\EntityStorage\EntityRepository;

final class RehearsalServiceTest extends TestCase
{
    private EntityRepository $personRepo;

    private EntityRepository $commitmentRepo;

    private RehearsalService $service;

    protected function setUp(): void
    {
        $dispatcher = new EventDispatcher;

        $this->personRepo = new EntityRepository(
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new InMemoryStorageDriver,
            $dispatcher,
        );
        $this->commitmentRepo = new EntityRepository(
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new InMemoryStorageDriver,
            $dispatcher,
        );

        $this->service = new RehearsalService([
            'person' => $this->personRepo,
            'commitment' => $this->commitmentRepo,
        ]);
    }

    public function test_access_increments_count_and_sets_timestamp(): void
    {
        $person = new Person(['pid' => 1, 'uuid' => 'p-1', 'name' => 'Alice', 'importance_score' => 0.5, 'access_count' => 2]);
        $this->personRepo->save($person);

        $this->service->recordAccess('person', 'p-1');

        $updated = $this->personRepo->findBy(['uuid' => 'p-1']);
        self::assertCount(1, $updated);
        self::assertSame(3, $updated[0]->get('access_count'));
        self::assertNotNull($updated[0]->get('last_accessed_at'));
    }

    public function test_access_applies_rehearsal_boost_capped_at_one(): void
    {
        $person = new Person(['pid' => 1, 'uuid' => 'p-1', 'name' => 'Bob', 'importance_score' => 0.5]);
        $this->personRepo->save($person);

        $this->service->recordAccess('person', 'p-1');

        $updated = $this->personRepo->findBy(['uuid' => 'p-1']);
        self::assertEqualsWithDelta(0.55, $updated[0]->get('importance_score'), 0.001);
    }

    public function test_boost_caps_at_one(): void
    {
        $person = new Person(['pid' => 1, 'uuid' => 'p-1', 'name' => 'Cap', 'importance_score' => 0.98]);
        $this->personRepo->save($person);

        $this->service->recordAccess('person', 'p-1');

        $updated = $this->personRepo->findBy(['uuid' => 'p-1']);
        self::assertEqualsWithDelta(1.0, $updated[0]->get('importance_score'), 0.001);
    }

    public function test_unknown_entity_type_is_silently_ignored(): void
    {
        $this->service->recordAccess('unknown_type', 'some-uuid');
        self::assertTrue(true);
    }

    public function test_nonexistent_uuid_is_silently_ignored(): void
    {
        $this->service->recordAccess('person', 'nonexistent-uuid');
        self::assertTrue(true);
    }

    public function test_multiple_accesses_compound(): void
    {
        $person = new Person(['pid' => 1, 'uuid' => 'p-1', 'name' => 'Multi', 'importance_score' => 0.5, 'access_count' => 0]);
        $this->personRepo->save($person);

        $this->service->recordAccess('person', 'p-1');
        $this->service->recordAccess('person', 'p-1');

        $updated = $this->personRepo->findBy(['uuid' => 'p-1']);
        self::assertEqualsWithDelta(0.60, $updated[0]->get('importance_score'), 0.001);
        self::assertSame(2, $updated[0]->get('access_count'));
    }
}
