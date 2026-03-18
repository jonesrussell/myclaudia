<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\DayBriefController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class DayBriefControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;

        $this->entityTypeManager = new EntityTypeManager(
            $dispatcher,
            function ($definition) use ($db, $dispatcher): SqlEntityStorage {
                (new SqlSchemaHandler($definition, $db))->ensureTable();

                return new SqlEntityStorage($definition, $db, $dispatcher);
            },
        );

        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'mc_event', label: 'Event', class: McEvent::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid', 'content_hash' => 'content_hash'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'commitment', label: 'Commitment', class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'person', label: 'Person', class: Person::class,
            keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
        $this->entityTypeManager->registerEntityType(new EntityType(
            id: 'workspace', label: 'Workspace', class: Workspace::class,
            keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
    }

    public function test_json_response_has_categorized_shape(): void
    {
        $controller = new DayBriefController($this->entityTypeManager, null);
        $response = $controller->show(httpRequest: Request::create('/brief'));

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);

        $body = json_decode($response->content, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('schedule', $body);
        self::assertArrayHasKey('schedule_summary', $body);
        self::assertArrayHasKey('job_hunt', $body);
        self::assertArrayHasKey('people', $body);
        self::assertArrayHasKey('creators', $body);
        self::assertArrayHasKey('notifications', $body);
        self::assertArrayHasKey('commitments', $body);
        self::assertArrayHasKey('counts', $body);
        self::assertArrayHasKey('generated_at', $body);
        self::assertArrayHasKey('time_snapshot', $body);
    }

    public function test_html_response_when_twig_provided(): void
    {
        $loader = new ArrayLoader([
            'day-brief.html.twig' => '<html><body>Schedule: {{ schedule|length }}</body></html>',
        ]);
        $twig = new Environment($loader);

        $controller = new DayBriefController($this->entityTypeManager, $twig);
        $response = $controller->show(httpRequest: Request::create('/brief'));

        self::assertSame(200, $response->statusCode);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertStringContainsString('<html>', $response->content);
    }

    public function test_json_when_accept_header_prefers_json(): void
    {
        $loader = new ArrayLoader([
            'day-brief.html.twig' => '<html><body>Brief</body></html>',
        ]);
        $twig = new Environment($loader);

        $controller = new DayBriefController($this->entityTypeManager, $twig);
        $request = Request::create('/brief', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $response = $controller->show([], [], null, $request);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);
    }

    public function test_json_when_accept_header_is_vnd_api_json(): void
    {
        $loader = new ArrayLoader([
            'day-brief.html.twig' => '<html><body>Brief</body></html>',
        ]);
        $twig = new Environment($loader);

        $controller = new DayBriefController($this->entityTypeManager, $twig);
        $request = Request::create('/brief', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/vnd.api+json',
        ]);
        $response = $controller->show([], [], null, $request);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);

        $body = json_decode($response->content, true);
        self::assertArrayHasKey('schedule', $body);
    }

    public function test_html_when_accept_header_is_text_html(): void
    {
        $loader = new ArrayLoader([
            'day-brief.html.twig' => '<html><body>Brief</body></html>',
        ]);
        $twig = new Environment($loader);

        $controller = new DayBriefController($this->entityTypeManager, $twig);
        $request = Request::create('/brief', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'text/html',
        ]);
        $response = $controller->show([], [], null, $request);

        self::assertSame(200, $response->statusCode);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
    }

    public function test_json_when_format_query_param_is_json(): void
    {
        $loader = new ArrayLoader([
            'day-brief.html.twig' => '<html><body>Brief</body></html>',
        ]);
        $twig = new Environment($loader);

        $controller = new DayBriefController($this->entityTypeManager, $twig);
        $request = Request::create('/brief', 'GET');
        $request->setRequestFormat('json');
        $response = $controller->show([], [], null, $request);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);

        $body = json_decode($response->content, true);
        self::assertArrayHasKey('schedule', $body);
    }

    public function test_json_includes_event_data(): void
    {
        $event = new McEvent([
            'uuid' => 'eeee0001-0001-0001-0001-eeeeeeeeeeee',
            'type' => 'message.received',
            'source' => 'gmail',
            'category' => 'people',
            'occurred' => (new \DateTimeImmutable)->format('Y-m-d H:i:s'),
            'payload' => json_encode([
                'subject' => 'Test Subject Line',
                'from_email' => 'alice@example.com',
                'from_name' => 'Alice',
            ]),
        ]);
        $this->entityTypeManager->getStorage('mc_event')->save($event);

        $controller = new DayBriefController($this->entityTypeManager, null);
        $response = $controller->show(httpRequest: Request::create('/brief'));

        $body = json_decode($response->content, true);
        self::assertCount(1, $body['people']);
        self::assertSame('Alice', $body['people'][0]['person_name']);
        self::assertArrayHasKey('time_snapshot', $body);
    }

    public function test_json_filters_events_by_tenant_header(): void
    {
        $storage = $this->entityTypeManager->getStorage('mc_event');
        $storage->save(new McEvent([
            'uuid' => 'tenant-one-event',
            'type' => 'message.received',
            'source' => 'gmail',
            'category' => 'people',
            'tenant_id' => 'tenant-one',
            'occurred' => (new \DateTimeImmutable)->format('Y-m-d H:i:s'),
            'payload' => json_encode(['from_name' => 'Tenant One', 'from_email' => 'one@example.com']),
        ]));
        $storage->save(new McEvent([
            'uuid' => 'tenant-two-event',
            'type' => 'message.received',
            'source' => 'gmail',
            'category' => 'people',
            'tenant_id' => 'tenant-two',
            'occurred' => (new \DateTimeImmutable)->format('Y-m-d H:i:s'),
            'payload' => json_encode(['from_name' => 'Tenant Two', 'from_email' => 'two@example.com']),
        ]));

        $controller = new DayBriefController($this->entityTypeManager, null);
        $request = Request::create('/brief', 'GET', server: ['HTTP_X_TENANT_ID' => 'tenant-one', 'HTTP_ACCEPT' => 'application/json']);
        $response = $controller->show([], [], null, $request);

        self::assertSame(200, $response->statusCode);
        $body = json_decode($response->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, $body['people']);
        self::assertSame('Tenant One', $body['people'][0]['person_name']);
    }
}
