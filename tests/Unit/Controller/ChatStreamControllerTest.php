<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Controller\ChatStreamController;
use Claudriel\Domain\Chat\NativeAgentClient;
use Claudriel\Entity\Account;
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
use Waaseyaa\Database\DBALDatabase;
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

    public function test_stream_emits_progress_and_token_events(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY=test-key');

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

        $controller = new ChatStreamController(
            $etm,
            agentClientFactory: static fn (): NativeAgentClient => self::createMockAgent(),
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

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        } else {
            putenv('ANTHROPIC_API_KEY');
        }
    }

    public function test_stream_uses_tenant_uuid_not_entity_id_for_account_id(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY=test-key');

        $etm = $this->buildEntityTypeManager();

        $tenantUuid = 'acct-uuid-'.uniqid();

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession(['uuid' => 'sess-acctid', 'title' => 'AccountId Test', 'created_at' => date('c')]));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-acctid',
            'session_uuid' => 'sess-acctid',
            'role' => 'user',
            'content' => 'Check my calendar',
            'created_at' => date('c'),
            'tenant_id' => $tenantUuid,
        ]));

        $account = new Account([
            'aid' => 42,
            'uuid' => $tenantUuid,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'status' => 'active',
            'email_verified_at' => date('c'),
        ]);

        // Capture the accountId passed to the agent client
        $capturedAccountId = null;
        $controller = new ChatStreamController(
            $etm,
            agentClientFactory: static function () use (&$capturedAccountId): NativeAgentClient {
                return new class('fake-key', $capturedAccountId) extends NativeAgentClient
                {
                    private mixed $captureRef;

                    public function __construct(string $apiKey, mixed &$captureRef = null)
                    {
                        parent::__construct($apiKey);
                        $this->captureRef = &$captureRef;
                    }

                    public function stream(
                        string $systemPrompt,
                        array $messages,
                        string $accountId,
                        string $tenantId,
                        string $apiBase,
                        string $apiToken,
                        \Closure $onToken,
                        \Closure $onDone,
                        \Closure $onError,
                        ?\Closure $onProgress = null,
                        ?string $model = null,
                        ?\Closure $onNeedsContinuation = null,
                    ): void {
                        $this->captureRef = $accountId;
                        $onToken('Done.');
                        $onDone('Done.');
                    }
                };
            },
        );

        $response = $controller->stream(['messageId' => 'msg-acctid'], [], new AuthenticatedAccount($account), null);
        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        ob_start();
        $callback = $response->getCallback();
        self::assertIsCallable($callback);
        $callback();
        ob_end_flush();
        ob_get_clean();

        // Verify the agent received the tenant UUID, not the sequential entity ID
        self::assertSame($tenantUuid, $capturedAccountId, 'account_id must be the tenant UUID, not the sequential entity ID');

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        } else {
            putenv('ANTHROPIC_API_KEY');
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

    public function test_stream_uses_workspace_anthropic_model_override(): void
    {
        $originalKey = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY=test-key');

        $etm = $this->buildEntityTypeManager();
        $workspaceStorage = $etm->getStorage('workspace');
        $workspaceStorage->save(new Workspace([
            'uuid' => 'workspace-model',
            'name' => 'Workspace Model',
            'tenant_id' => 'default',
            'anthropic_model' => 'claude-opus-4-6',
        ]));

        $sessionStorage = $etm->getStorage('chat_session');
        $sessionStorage->save(new ChatSession([
            'uuid' => 'sess-model',
            'title' => 'Model Test',
            'created_at' => date('c'),
            'tenant_id' => 'default',
            'workspace_id' => 'workspace-model',
        ]));

        $msgStorage = $etm->getStorage('chat_message');
        $msgStorage->save(new ChatMessage([
            'uuid' => 'msg-model',
            'session_uuid' => 'sess-model',
            'role' => 'user',
            'content' => 'hello',
            'created_at' => date('c'),
            'tenant_id' => 'default',
            'workspace_id' => 'workspace-model',
        ]));

        $capturedModel = null;
        $controller = new ChatStreamController(
            $etm,
            agentClientFactory: static function () use (&$capturedModel): NativeAgentClient {
                return new class('fake-key', $capturedModel) extends NativeAgentClient
                {
                    private mixed $captureRef;

                    public function __construct(string $apiKey, mixed &$captureRef = null)
                    {
                        parent::__construct($apiKey);
                        $this->captureRef = &$captureRef;
                    }

                    public function stream(
                        string $systemPrompt,
                        array $messages,
                        string $accountId,
                        string $tenantId,
                        string $apiBase,
                        string $apiToken,
                        \Closure $onToken,
                        \Closure $onDone,
                        \Closure $onError,
                        ?\Closure $onProgress = null,
                        ?string $model = null,
                        ?\Closure $onNeedsContinuation = null,
                    ): void {
                        $this->captureRef = $model;
                        $onToken('Done.');
                        $onDone('Done.');
                    }
                };
            },
        );

        $response = $controller->stream(['messageId' => 'msg-model'], [], null, null);
        self::assertInstanceOf(StreamedResponse::class, $response);

        ob_start();
        ob_start();
        $callback = $response->getCallback();
        self::assertIsCallable($callback);
        $callback();
        ob_end_flush();
        ob_get_clean();

        self::assertSame('claude-opus-4-6', $capturedModel);

        if ($originalKey !== false) {
            putenv("ANTHROPIC_API_KEY={$originalKey}");
        } else {
            putenv('ANTHROPIC_API_KEY');
        }
    }

    private static function createMockAgent(): NativeAgentClient
    {
        return new class('fake-key') extends NativeAgentClient
        {
            public function __construct(string $apiKey)
            {
                parent::__construct($apiKey);
            }

            public function stream(
                string $systemPrompt,
                array $messages,
                string $accountId,
                string $tenantId,
                string $apiBase,
                string $apiToken,
                \Closure $onToken,
                \Closure $onDone,
                \Closure $onError,
                ?\Closure $onProgress = null,
                ?string $model = null,
                ?\Closure $onNeedsContinuation = null,
            ): void {
                if ($onProgress !== null) {
                    $onProgress([
                        'phase' => 'tool_call',
                        'tool' => 'calendar_list',
                        'summary' => 'Using calendar_list',
                        'level' => 'info',
                    ]);
                }
                $onToken('Today looks clear.');
                $onDone('Today looks clear.');
            }
        };
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = DBALDatabase::createSqlite(':memory:');
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
