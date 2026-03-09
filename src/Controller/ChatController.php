<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\AnthropicChatClient;
use Claudriel\Domain\Chat\ChatSystemPromptBuilder;
use Claudriel\Domain\DayBrief\Assembler\DayBriefAssembler;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Support\DriftDetector;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

/**
 * Chat interface controller.
 *
 * HttpKernel instantiates as: new ChatController($entityTypeManager, $twig)
 */
final class ChatController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly mixed $twig = null,
    ) {}

    /**
     * GET /chat — render the chat UI.
     */
    public function index(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $apiKey = $this->getApiKey();

        // Load recent sessions
        $sessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $sessionIds = $sessionStorage->getQuery()->execute();
        $allSessions = $sessionStorage->loadMultiple($sessionIds);

        // Sort by created_at descending, take 10
        usort($allSessions, function ($a, $b) {
            return ($b->get('created_at') ?? '') <=> ($a->get('created_at') ?? '');
        });
        $sessions = array_slice($allSessions, 0, 10);

        $twigSessions = [];
        foreach ($sessions as $session) {
            $twigSessions[] = [
                'uuid' => $session->get('uuid'),
                'title' => $session->get('title') ?? 'New Chat',
                'created_at' => $session->get('created_at'),
            ];
        }

        if ($this->twig !== null) {
            $html = $this->twig->render('chat.html.twig', [
                'sessions' => $twigSessions,
                'api_configured' => $apiKey !== null,
            ]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return new SsrResponse(
            content: json_encode(['sessions' => $twigSessions, 'api_configured' => $apiKey !== null]),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    /**
     * POST /api/chat/send — send a message and get the assistant response.
     */
    public function send(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $raw = method_exists($httpRequest, 'getContent') ? $httpRequest->getContent() : '';
        $body = json_decode($raw, true) ?? [];
        $message = trim($body['message'] ?? '');
        $sessionUuid = $body['session_id'] ?? null;

        if ($message === '') {
            return new SsrResponse(
                content: json_encode(['error' => 'Message required']),
                statusCode: 422,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $apiKey = $this->getApiKey();
        if ($apiKey === null) {
            return new SsrResponse(
                content: json_encode(['error' => 'Chat not configured. Set ANTHROPIC_API_KEY in your environment.']),
                statusCode: 503,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        $sessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $messageStorage = $this->entityTypeManager->getStorage('chat_message');

        // Find or create session
        $session = null;
        if ($sessionUuid !== null) {
            $ids = $sessionStorage->getQuery()->condition('uuid', $sessionUuid)->execute();
            if (!empty($ids)) {
                $session = $sessionStorage->load(reset($ids));
            }
        }

        if ($session === null) {
            $session = new ChatSession([
                'uuid' => $this->generateUuid(),
                'title' => mb_substr($message, 0, 60),
                'created_at' => (new \DateTimeImmutable())->format('c'),
            ]);
            $sessionStorage->save($session);
        }

        $sessionUuid = $session->get('uuid');

        // Save user message
        $userMsg = new ChatMessage([
            'uuid' => $this->generateUuid(),
            'session_uuid' => $sessionUuid,
            'role' => 'user',
            'content' => $message,
            'created_at' => (new \DateTimeImmutable())->format('c'),
        ]);
        $messageStorage->save($userMsg);

        // Load conversation history for this session
        $allMsgIds = $messageStorage->getQuery()->execute();
        $allMessages = $messageStorage->loadMultiple($allMsgIds);
        $sessionMessages = [];
        foreach ($allMessages as $msg) {
            if ($msg->get('session_uuid') === $sessionUuid) {
                $sessionMessages[] = $msg;
            }
        }

        // Sort by created_at
        usort($sessionMessages, function ($a, $b) {
            return ($a->get('created_at') ?? '') <=> ($b->get('created_at') ?? '');
        });

        // Build messages array (only role + content for the API)
        $apiMessages = array_map(
            fn ($m) => ['role' => $m->get('role'), 'content' => $m->get('content')],
            $sessionMessages,
        );

        // Build system prompt
        $projectRoot = $this->resolveProjectRoot();
        $promptBuilder = $this->buildPromptBuilder($projectRoot);
        $systemPrompt = $promptBuilder->build();

        // Call Anthropic
        $model = $_ENV['ANTHROPIC_MODEL'] ?? getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-20250514';
        $client = new AnthropicChatClient($apiKey, $model);

        try {
            $response = $client->complete($systemPrompt, $apiMessages);
        } catch (\RuntimeException $e) {
            return new SsrResponse(
                content: json_encode(['error' => 'AI service error: ' . $e->getMessage()]),
                statusCode: 502,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        // Save assistant message
        $assistantMsg = new ChatMessage([
            'uuid' => $this->generateUuid(),
            'session_uuid' => $sessionUuid,
            'role' => 'assistant',
            'content' => $response,
            'created_at' => (new \DateTimeImmutable())->format('c'),
        ]);
        $messageStorage->save($assistantMsg);

        return new SsrResponse(
            content: json_encode([
                'session_id' => $sessionUuid,
                'response' => $response,
            ]),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function getApiKey(): ?string
    {
        $key = getenv('ANTHROPIC_API_KEY');
        return is_string($key) && $key !== '' ? $key : null;
    }

    private function resolveProjectRoot(): string
    {
        // The project root is the Waaseyaa application root.
        // Use MYCLAUDIA_ROOT env, or fall back to common detection.
        $root = getenv('MYCLAUDIA_ROOT');
        if (is_string($root) && $root !== '' && is_dir($root)) {
            return $root;
        }

        // Fall back: walk up from this file to find composer.json
        $dir = __DIR__;
        while ($dir !== '/' && $dir !== '') {
            if (is_file($dir . '/composer.json')) {
                return $dir;
            }
            $dir = dirname($dir);
        }

        return getcwd() ?: '/tmp';
    }

    private function buildPromptBuilder(string $projectRoot): ChatSystemPromptBuilder
    {
        $eventStorage = $this->entityTypeManager->getStorage('mc_event');
        $commitmentStorage = $this->entityTypeManager->getStorage('commitment');

        // Build lightweight repos for the assembler using entity storage directly.
        // The assembler calls findBy([]) and get() on entities, which storage supports.
        $eventRepo = new StorageRepositoryAdapter($eventStorage);
        $commitmentRepo = new StorageRepositoryAdapter($commitmentStorage);
        $driftDetector = new DriftDetector($commitmentRepo);

        $skillStorage = $this->entityTypeManager->getStorage('skill');
        $skillRepo = new StorageRepositoryAdapter($skillStorage);

        $assembler = new DayBriefAssembler($eventRepo, $commitmentRepo, $driftDetector, $skillRepo);

        return new ChatSystemPromptBuilder($assembler, $projectRoot);
    }

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xffff), random_int(0, 0xffff),
            random_int(0, 0xffff),
            random_int(0, 0x0fff) | 0x4000,
            random_int(0, 0x3fff) | 0x8000,
            random_int(0, 0xffff), random_int(0, 0xffff), random_int(0, 0xffff),
        );
    }
}
