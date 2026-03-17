<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\ChatStreamController;
use Claudriel\Domain\Chat\SubprocessChatClient;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\Commitment;
use Claudriel\Entity\McEvent;
use Claudriel\Entity\Person;
use Claudriel\Entity\Skill;
use Claudriel\Entity\Workspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;
use Waaseyaa\SSR\SsrResponse;

final class ChatStreamControllerTest extends TestCase
{
    public function test_returns404_for_nonexistent_message(): void
    {
        $etm = $this->buildEntityTypeManager();
        $controller = new ChatStreamController($etm);

        $response = $controller->stream(
            ['messageId' => 'nonexistent-uuid'],
            [],
            null,
            null,
        );

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(404, $response->statusCode);
    }

    public function test_returns503_when_api_key_missing(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $etm = $this->buildEntityTypeManager();

        // Create a session and message
        $sessionStorage = $etm->getStorage('chat_session');
        $session = new ChatSession(['uuid' => 'sess-1', 'title' => 'Test', 'created_at' => date('c')]);
        $sessionStorage->save($session);

        $msgStorage = $etm->getStorage('chat_message');
        $msg = new ChatMessage([
            'uuid' => 'msg-1',
            'session_uuid' => 'sess-1',
            'role' => 'user',
            'content' => 'hello',
            'created_at' => date('c'),
        ]);
        $msgStorage->save($msg);

        $controller = new ChatStreamController($etm);
        $response = $controller->stream(['messageId' => 'msg-1'], [], null, null);

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(503, $response->statusCode);

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        }
    }

    public function test_creates_workspace_from_chat_request_without_api_key(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $etm = $this->buildEntityTypeManager();

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-2', 'title' => 'Test', 'created_at' => date('c')]));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-2',
            'session_uuid' => 'sess-2',
            'role' => 'user',
            'content' => 'create a workspace named "Foobar"',
            'created_at' => date('c'),
        ]));

        $controller = new ChatStreamController($etm);
        $response = $controller->stream(['messageId' => 'msg-2'], [], null, null);

        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $callback = $response->getCallback();
        self::assertIsCallable($callback);
        $callback();
        ob_end_clean();

        $workspaceStorage = $etm->getStorage('workspace');
        $workspaceIds = $workspaceStorage->getQuery()->condition('name', 'Foobar')->execute();
        self::assertNotEmpty($workspaceIds);

        $assistantIds = $msgStorage->getQuery()->condition('role', 'assistant')->execute();
        self::assertNotEmpty($assistantIds);
        $assistantMessage = $msgStorage->load(reset($assistantIds));
        self::assertInstanceOf(ChatMessage::class, $assistantMessage);
        self::assertSame('Created the Claudriel workspace "Foobar". Refresh the sidebar if it is not visible yet.', $assistantMessage->get('content'));

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        }
    }

    public function test_creates_workspace_from_chat_request_with_smart_quotes(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $etm = $this->buildEntityTypeManager();

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-3', 'title' => 'Test', 'created_at' => date('c')]));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-3',
            'session_uuid' => 'sess-3',
            'role' => 'user',
            'content' => "create a workspace named \u{201C}Foo\u{201D}",
            'created_at' => date('c'),
        ]));

        $controller = new ChatStreamController($etm);
        $response = $controller->stream(['messageId' => 'msg-3'], [], null, null);

        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $callback = $response->getCallback();
        self::assertIsCallable($callback);
        $callback();
        ob_end_clean();

        $workspaceStorage = $etm->getStorage('workspace');
        $workspaceIds = $workspaceStorage->getQuery()->condition('name', 'Foo')->execute();
        self::assertNotEmpty($workspaceIds);

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        }
    }

    public function test_deletes_multiple_workspaces_from_chat_request_without_api_key(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $etm = $this->buildEntityTypeManager();

        $workspaceStorage = $etm->getStorage('workspace');
        $workspaceStorage->save(new Workspace(['name' => 'Bar', 'description' => '']));
        $workspaceStorage->save(new Workspace(['name' => 'Foo', 'description' => '']));

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-4', 'title' => 'Test', 'created_at' => date('c')]));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-4',
            'session_uuid' => 'sess-4',
            'role' => 'user',
            'content' => 'delete workspace Bar and Foo',
            'created_at' => date('c'),
        ]));

        $controller = new ChatStreamController($etm);
        $response = $controller->stream(['messageId' => 'msg-4'], [], null, null);

        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $callback = $response->getCallback();
        self::assertIsCallable($callback);
        $callback();
        ob_end_clean();

        self::assertSame([], $workspaceStorage->getQuery()->condition('name', 'Bar')->execute());
        self::assertSame([], $workspaceStorage->getQuery()->condition('name', 'Foo')->execute());

        $assistantIds = $msgStorage->getQuery()->condition('role', 'assistant')->execute();
        $assistantMessage = $msgStorage->load(reset($assistantIds));
        self::assertInstanceOf(ChatMessage::class, $assistantMessage);
        self::assertSame('Deleted "Bar" and "Foo".', $assistantMessage->get('content'));

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        }
    }

    public function test_delete_workspace_reports_missing_names(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $etm = $this->buildEntityTypeManager();

        $workspaceStorage = $etm->getStorage('workspace');
        $workspaceStorage->save(new Workspace(['name' => 'Bar', 'description' => '']));

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-5', 'title' => 'Test', 'created_at' => date('c')]));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-5',
            'session_uuid' => 'sess-5',
            'role' => 'user',
            'content' => 'delete workspace Bar and Missing',
            'created_at' => date('c'),
        ]));

        $controller = new ChatStreamController($etm);
        $response = $controller->stream(['messageId' => 'msg-5'], [], null, null);

        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $callback = $response->getCallback();
        self::assertIsCallable($callback);
        $callback();
        ob_end_clean();

        self::assertSame([], $workspaceStorage->getQuery()->condition('name', 'Bar')->execute());
        self::assertSame([], $workspaceStorage->getQuery()->condition('name', 'Missing')->execute());

        $assistantIds = $msgStorage->getQuery()->condition('role', 'assistant')->execute();
        $assistantMessage = $msgStorage->load(reset($assistantIds));
        self::assertInstanceOf(ChatMessage::class, $assistantMessage);
        self::assertSame('Deleted "Bar". Could not find "Missing".', $assistantMessage->get('content'));

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        }
    }

    public function test_stream_forwards_sanitized_progress_events_from_subprocess(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        $originalSecret = getenv('AGENT_INTERNAL_SECRET');
        putenv('ANTHROPIC_API_KEY=test-key');
        putenv('AGENT_INTERNAL_SECRET=test-secret-that-is-at-least-32-bytes-long');

        $etm = $this->buildEntityTypeManager();

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-6', 'title' => 'Telemetry', 'created_at' => date('c')]));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-6',
            'session_uuid' => 'sess-6',
            'role' => 'user',
            'content' => 'What is on my calendar today?',
            'created_at' => date('c'),
        ]));

        // Create a mock script that emits progress + token events
        $script = sys_get_temp_dir().'/mock_agent_progress_'.uniqid().'.php';
        file_put_contents($script, <<<'PHP'
        <?php
        // Read stdin (the request JSON) and discard
        file_get_contents('php://stdin');
        echo json_encode(['event' => 'tool_call', 'tool' => 'calendar_list', 'args' => []]) . "\n";
        echo json_encode(['event' => 'tool_result', 'tool' => 'calendar_list', 'result' => ['items' => []]]) . "\n";
        echo json_encode(['event' => 'message', 'content' => 'Today looks clear.']) . "\n";
        echo json_encode(['event' => 'done']) . "\n";
        PHP);

        $controller = new ChatStreamController(
            $etm,
            subprocessClientFactory: static function () use ($script) {
                return new SubprocessChatClient(
                    command: [PHP_BINARY, $script],
                    timeoutSeconds: 10,
                );
            },
        );

        $response = $controller->stream(['messageId' => 'msg-6'], [], null, null);

        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        ob_start();
        $callback = $response->getCallback();
        self::assertIsCallable($callback);
        $callback();
        ob_end_flush();
        $output = ob_get_clean();

        self::assertIsString($output);
        self::assertStringContainsString('event: chat-progress', $output);
        self::assertStringContainsString('event: chat-token', $output);
        self::assertStringContainsString('event: chat-done', $output);

        unlink($script);

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        } else {
            putenv('ANTHROPIC_API_KEY');
        }
        if ($originalSecret !== false) {
            putenv("AGENT_INTERNAL_SECRET={$originalSecret}");
        } else {
            putenv('AGENT_INTERNAL_SECRET');
        }
    }

    public function test_delete_workspace_does_not_cross_tenant_boundaries(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $etm = $this->buildEntityTypeManager();

        $workspaceStorage = $etm->getStorage('workspace');
        $workspaceStorage->save(new Workspace(['name' => 'Bar', 'description' => '', 'tenant_id' => 'tenant-two']));

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-tenant', 'title' => 'Tenant Test', 'created_at' => date('c'), 'tenant_id' => 'tenant-one']));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-tenant',
            'session_uuid' => 'sess-tenant',
            'role' => 'user',
            'content' => 'delete workspace Bar',
            'created_at' => date('c'),
            'tenant_id' => 'tenant-one',
        ]));

        $controller = new ChatStreamController($etm);
        $request = Request::create('/stream/chat/msg-tenant', 'GET', server: ['HTTP_X_TENANT_ID' => 'tenant-one']);
        $response = $controller->stream(['messageId' => 'msg-tenant'], [], null, $request);

        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        $callback = $response->getCallback();
        self::assertIsCallable($callback);
        $callback();
        ob_end_clean();

        $remaining = $workspaceStorage->getQuery()->condition('name', 'Bar')->execute();
        self::assertNotEmpty($remaining);

        $assistantIds = $msgStorage->getQuery()->condition('role', 'assistant')->execute();
        $assistantMessage = $msgStorage->load(reset($assistantIds));
        self::assertInstanceOf(ChatMessage::class, $assistantMessage);
        self::assertSame('Could not find "Bar".', $assistantMessage->get('content'));

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        }
    }

    public function test_stream_fails_closed_for_mismatched_workspace_scope(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY');
        unset($_ENV['ANTHROPIC_API_KEY']);

        $etm = $this->buildEntityTypeManager();
        $workspaceStorage = $etm->getStorage('workspace');
        $workspaceStorage->save(new Workspace(['uuid' => 'workspace-a', 'name' => 'Workspace A', 'tenant_id' => 'default']));
        $workspaceStorage->save(new Workspace(['uuid' => 'workspace-b', 'name' => 'Workspace B', 'tenant_id' => 'default']));

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-scope', 'title' => 'Scope Test', 'created_at' => date('c'), 'tenant_id' => 'default', 'workspace_id' => 'workspace-a']));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-scope',
            'session_uuid' => 'sess-scope',
            'role' => 'user',
            'content' => 'hello',
            'created_at' => date('c'),
            'tenant_id' => 'default',
            'workspace_id' => 'workspace-a',
        ]));

        $controller = new ChatStreamController($etm);
        $request = Request::create('/stream/chat/msg-scope?workspace_uuid=workspace-b', 'GET');
        $response = $controller->stream(['messageId' => 'msg-scope'], ['workspace_uuid' => 'workspace-b'], null, $request);

        self::assertInstanceOf(SsrResponse::class, $response);
        self::assertSame(404, $response->statusCode);

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        }
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
        $etm = new EntityTypeManager($dispatcher, function ($def) use ($db, $dispatcher) {
            (new SqlSchemaHandler($def, $db))->ensureTable();

            return new SqlEntityStorage($def, $db, $dispatcher);
        });
        foreach ($this->entityTypes() as $type) {
            $etm->registerEntityType($type);
        }

        return $etm;
    }

    /** @return list<EntityType> */
    private function entityTypes(): array
    {
        return [
            new EntityType(id: 'mc_event', label: 'Event', class: McEvent::class, keys: ['id' => 'eid', 'uuid' => 'uuid']),
            new EntityType(id: 'commitment', label: 'Commitment', class: Commitment::class, keys: ['id' => 'cid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'person', label: 'Person', class: Person::class, keys: ['id' => 'pid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'skill', label: 'Skill', class: Skill::class, keys: ['id' => 'sid', 'uuid' => 'uuid', 'label' => 'name']),
            new EntityType(id: 'chat_session', label: 'Chat Session', class: ChatSession::class, keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title']),
            new EntityType(id: 'chat_message', label: 'Chat Message', class: ChatMessage::class, keys: ['id' => 'cmid', 'uuid' => 'uuid']),
            new EntityType(id: 'workspace', label: 'Workspace', class: Workspace::class, keys: ['id' => 'wid', 'uuid' => 'uuid', 'label' => 'name']),
        ];
    }
}
