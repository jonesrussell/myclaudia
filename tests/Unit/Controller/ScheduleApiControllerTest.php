<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\ScheduleApiController;
use Claudriel\Entity\ScheduleEntry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class ScheduleApiControllerTest extends TestCase
{
    public function test_crud_and_today_filtering_for_schedule_entries(): void
    {
        $controller = new ScheduleApiController($this->buildEntityTypeManager());
        $today = new \DateTimeImmutable('2026-03-13T22:00:00-04:00');
        $tomorrow = $today->modify('+1 day');

        $createToday = $controller->create(
            httpRequest: Request::create('/api/schedule', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'title' => 'Morning Standup',
                'starts_at' => $today->format(\DateTimeInterface::ATOM),
                'ends_at' => $today->modify('+30 minutes')->format(\DateTimeInterface::ATOM),
                'notes' => 'Daily sync',
            ], JSON_THROW_ON_ERROR)),
        );
        $createTomorrow = $controller->create(
            httpRequest: Request::create('/api/schedule', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'title' => 'Tomorrow Planning',
                'starts_at' => $tomorrow->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR)),
        );

        self::assertSame(201, $createToday->statusCode);
        self::assertSame(201, $createTomorrow->statusCode);

        $todayEntry = json_decode($createToday->content, true, 512, JSON_THROW_ON_ERROR)['schedule'];
        $tomorrowEntry = json_decode($createTomorrow->content, true, 512, JSON_THROW_ON_ERROR)['schedule'];

        $listResponse = $controller->list(query: ['date' => 'today', 'timezone' => 'America/Toronto']);
        $listPayload = json_decode($listResponse->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(1, $listPayload['schedule']);
        self::assertSame('Morning Standup', $listPayload['schedule'][0]['title']);
        self::assertSame('', $listPayload['schedule_summary']);
        self::assertArrayHasKey('time_snapshot', $listPayload);

        $updateResponse = $controller->update(
            params: ['uuid' => $todayEntry['uuid']],
            httpRequest: Request::create('/api/schedule/'.$todayEntry['uuid'], 'PATCH', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'title' => 'Morning Sync',
            ], JSON_THROW_ON_ERROR)),
        );
        self::assertSame('Morning Sync', json_decode($updateResponse->content, true, 512, JSON_THROW_ON_ERROR)['schedule']['title']);

        $deleteResponse = $controller->delete(params: ['uuid' => $tomorrowEntry['uuid']]);
        self::assertSame(200, $deleteResponse->statusCode);
    }

    public function test_recurring_delete_defaults_to_single_occurrence(): void
    {
        $controller = new ScheduleApiController($this->buildEntityTypeManager());
        $today = new \DateTimeImmutable('today 11:00:00');
        $tomorrow = $today->modify('+1 day');

        $first = $controller->create(
            httpRequest: Request::create('/api/schedule', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'title' => 'Aftercare Support Group',
                'starts_at' => $today->format(\DateTimeInterface::ATOM),
                'recurring_series_id' => 'series-aftercare',
            ], JSON_THROW_ON_ERROR)),
        );
        $second = $controller->create(
            httpRequest: Request::create('/api/schedule', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'title' => 'Aftercare Support Group',
                'starts_at' => $tomorrow->format(\DateTimeInterface::ATOM),
                'recurring_series_id' => 'series-aftercare',
            ], JSON_THROW_ON_ERROR)),
        );

        $firstEntry = json_decode($first->content, true, 512, JSON_THROW_ON_ERROR)['schedule'];
        $secondEntry = json_decode($second->content, true, 512, JSON_THROW_ON_ERROR)['schedule'];

        $delete = $controller->delete(params: ['uuid' => $firstEntry['uuid']]);
        $payload = json_decode($delete->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('occurrence', $payload['scope']);
        self::assertSame(1, $payload['affected_count']);

        $showFirst = json_decode($controller->show(params: ['uuid' => $firstEntry['uuid']])->content, true, 512, JSON_THROW_ON_ERROR);
        $showSecond = json_decode($controller->show(params: ['uuid' => $secondEntry['uuid']])->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('cancelled', $showFirst['schedule']['status']);
        self::assertSame('active', $showSecond['schedule']['status']);
    }

    public function test_recurring_delete_with_series_scope_removes_entire_series(): void
    {
        $controller = new ScheduleApiController($this->buildEntityTypeManager());
        $today = new \DateTimeImmutable('today 11:00:00');
        $tomorrow = $today->modify('+1 day');

        $first = $controller->create(
            httpRequest: Request::create('/api/schedule', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'title' => 'Aftercare Support Group',
                'starts_at' => $today->format(\DateTimeInterface::ATOM),
                'recurring_series_id' => 'series-aftercare',
            ], JSON_THROW_ON_ERROR)),
        );
        $second = $controller->create(
            httpRequest: Request::create('/api/schedule', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'title' => 'Aftercare Support Group',
                'starts_at' => $tomorrow->format(\DateTimeInterface::ATOM),
                'recurring_series_id' => 'series-aftercare',
            ], JSON_THROW_ON_ERROR)),
        );

        $firstEntry = json_decode($first->content, true, 512, JSON_THROW_ON_ERROR)['schedule'];
        $secondEntry = json_decode($second->content, true, 512, JSON_THROW_ON_ERROR)['schedule'];

        $delete = $controller->delete(params: ['uuid' => $firstEntry['uuid']], query: ['scope' => 'series']);
        $payload = json_decode($delete->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('series', $payload['scope']);
        self::assertSame(2, $payload['affected_count']);
        self::assertSame(404, $controller->show(params: ['uuid' => $firstEntry['uuid']])->statusCode);
        self::assertSame(404, $controller->show(params: ['uuid' => $secondEntry['uuid']])->statusCode);
    }

    public function test_today_query_returns_clear_day_when_all_events_have_ended(): void
    {
        $controller = new ScheduleApiController($this->buildEntityTypeManager());
        $ended = new \DateTimeImmutable('-3 hours');

        $controller->create(
            httpRequest: Request::create('/api/schedule', 'POST', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'title' => 'Completed Block',
                'starts_at' => $ended->format(\DateTimeInterface::ATOM),
                'ends_at' => $ended->modify('+30 minutes')->format(\DateTimeInterface::ATOM),
            ], JSON_THROW_ON_ERROR)),
        );

        $listPayload = json_decode($controller->list(query: ['date' => 'today'])->content, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame([], $listPayload['schedule']);
        self::assertSame('Your day is clear', $listPayload['schedule_summary']);
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;

        $etm = new EntityTypeManager($dispatcher, function ($definition) use ($db, $dispatcher): SqlEntityStorage {
            (new SqlSchemaHandler($definition, $db))->ensureTable();

            return new SqlEntityStorage($definition, $db, $dispatcher);
        });

        $etm->registerEntityType(new EntityType(
            id: 'schedule_entry',
            label: 'Schedule Entry',
            class: ScheduleEntry::class,
            keys: ['id' => 'seid', 'uuid' => 'uuid', 'label' => 'title'],
        ));

        return $etm;
    }
}
