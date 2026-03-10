<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\DayBriefController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\Database\PdoDatabase;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class DayBriefControllerTest extends TestCase
{
    private EntityTypeManager $entityTypeManager;

    protected function setUp(): void
    {
        $db         = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher();

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
    }

    public function test_json_response_has_categorized_shape(): void
    {
        $controller = new DayBriefController($this->entityTypeManager, null);
        $response   = $controller->show();

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);

        $body = json_decode($response->content, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('schedule', $body);
        self::assertArrayHasKey('job_hunt', $body);
        self::assertArrayHasKey('people', $body);
        self::assertArrayHasKey('creators', $body);
        self::assertArrayHasKey('notifications', $body);
        self::assertArrayHasKey('commitments', $body);
        self::assertArrayHasKey('counts', $body);
        self::assertArrayHasKey('generated_at', $body);
    }

    public function test_html_response_when_twig_provided(): void
    {
        $loader = new ArrayLoader([
            'day-brief.html.twig' => '<html><body>Schedule: {{ schedule|length }}</body></html>',
        ]);
        $twig = new Environment($loader);

        $controller = new DayBriefController($this->entityTypeManager, $twig);
        $response   = $controller->show();

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
            'uuid'     => 'eeee0001-0001-0001-0001-eeeeeeeeeeee',
            'type'     => 'message.received',
            'source'   => 'gmail',
            'category' => 'people',
            'occurred' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'payload'  => json_encode([
                'subject'    => 'Test Subject Line',
                'from_email' => 'alice@example.com',
                'from_name'  => 'Alice',
            ]),
        ]);
        $this->entityTypeManager->getStorage('mc_event')->save($event);

        $controller = new DayBriefController($this->entityTypeManager, null);
        $response   = $controller->show();

        $body = json_decode($response->content, true);
        self::assertCount(1, $body['people']);
        self::assertSame('Alice', $body['people'][0]['person_name']);
    }
}
