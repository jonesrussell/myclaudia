<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\DayBriefController;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
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

        $eventType = new EntityType(
            id: 'mc_event',
            label: 'Event',
            class: McEvent::class,
            keys: ['id' => 'eid', 'uuid' => 'uuid'],
        );
        $this->entityTypeManager->registerEntityType($eventType);

        $commitmentType = new EntityType(
            id: 'commitment',
            label: 'Commitment',
            class: Commitment::class,
            keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title'],
        );
        $this->entityTypeManager->registerEntityType($commitmentType);
    }

    public function testShowReturnsJsonWhenTwigIsNull(): void
    {
        $controller = new DayBriefController($this->entityTypeManager, null);
        $response   = $controller->show();

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);

        $body = json_decode($response->content, true);
        self::assertIsArray($body);
        self::assertArrayHasKey('recent_events', $body);
        self::assertArrayHasKey('events_by_source', $body);
        self::assertArrayHasKey('people', $body);
        self::assertArrayHasKey('pending_commitments', $body);
        self::assertArrayHasKey('drifting_commitments', $body);
    }

    public function testShowReturnsHtmlWhenTwigIsProvided(): void
    {
        $loader = new ArrayLoader([
            'day-brief.html.twig' => '<html><body>Brief: {{ recent_events|length }} events</body></html>',
        ]);
        $twig = new Environment($loader);

        $controller = new DayBriefController($this->entityTypeManager, $twig);
        $response   = $controller->show();

        self::assertSame(200, $response->statusCode);
        self::assertSame('text/html; charset=UTF-8', $response->headers['Content-Type']);
        self::assertStringContainsString('<html>', $response->content);
        self::assertStringContainsString('0 events', $response->content);
    }

    public function testShowReturnsJsonWhenAcceptHeaderPrefersJson(): void
    {
        $loader = new ArrayLoader([
            'day-brief.html.twig' => '<html><body>Brief: {{ recent_events|length }} events</body></html>',
        ]);
        $twig = new Environment($loader);

        $controller = new DayBriefController($this->entityTypeManager, $twig);
        $request = Request::create('/brief', 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $response = $controller->show([], [], null, $request);

        self::assertSame(200, $response->statusCode);
        self::assertSame('application/json', $response->headers['Content-Type']);

        $body = json_decode($response->content, true);
        self::assertIsArray($body);
    }

    public function testShowIncludesRecentEventsInHtml(): void
    {
        $event = new McEvent([
            'uuid'     => 'eeee0001-0001-0001-0001-eeeeeeeeeeee',
            'type'     => 'email_received',
            'source'   => 'gmail',
            'occurred' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'payload'  => json_encode([
                'subject'    => 'Test Subject Line',
                'from_email' => 'alice@example.com',
                'from_name'  => 'Alice',
            ]),
        ]);
        $this->entityTypeManager->getStorage('mc_event')->save($event);

        $loader = new ArrayLoader([
            'day-brief.html.twig' => '{% for source, events in events_by_source %}{% for e in events %}{{ e.type }}{% endfor %}{% endfor %}',
        ]);
        $twig = new Environment($loader);

        $controller = new DayBriefController($this->entityTypeManager, $twig);
        $response   = $controller->show();

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('email_received', $response->content);
    }
}
