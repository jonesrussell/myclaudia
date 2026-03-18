<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Routing\RequestScopeViolation;
use Claudriel\Routing\TenantWorkspaceResolver;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\ContentEntityInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

/**
 * Chat interface controller.
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
    public function index(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account, $httpRequest);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }

        $apiKey = $this->getApiKey();

        // Load recent sessions
        $sessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $sessionIds = $sessionStorage->getQuery()->execute();
        /** @var ContentEntityInterface[] $loadedSessions */
        $loadedSessions = $sessionStorage->loadMultiple($sessionIds);
        $allSessions = array_values(array_filter(
            $loadedSessions,
            fn ($session): bool => $resolver->tenantMatches($session, $scope->tenantId),
        ));

        // Sort by created_at descending, take 10
        usort($allSessions, static function (ContentEntityInterface $a, ContentEntityInterface $b) {
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
     * GET /api/chat/sessions/{uuid}/messages — load messages for a session.
     */
    public function messages(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account, $httpRequest);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }

        $uuid = $params['uuid'] ?? '';
        $sessionStorage = $this->entityTypeManager->getStorage('chat_session');
        $sessionIds = $sessionStorage->getQuery()->condition('uuid', $uuid)->execute();

        if (empty($sessionIds)) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        $session = $sessionStorage->load(reset($sessionIds));
        if (! $resolver->tenantMatches($session, $scope->tenantId) || ! $resolver->workspaceMatches($session, $scope->workspaceId())) {
            return $this->json(['error' => 'Session not found'], 404);
        }

        $messageStorage = $this->entityTypeManager->getStorage('chat_message');
        $messageIds = $messageStorage->getQuery()->condition('session_uuid', $uuid)->execute();
        /** @var ContentEntityInterface[] $loadedMessages */
        $loadedMessages = $messageStorage->loadMultiple($messageIds);
        $allMessages = array_values(array_filter(
            $loadedMessages,
            fn ($message): bool => $resolver->tenantMatches($message, $scope->tenantId),
        ));

        usort($allMessages, static function (ContentEntityInterface $a, ContentEntityInterface $b) {
            return ($a->get('created_at') ?? '') <=> ($b->get('created_at') ?? '');
        });

        $result = [];
        foreach ($allMessages as $msg) {
            $result[] = [
                'role' => $msg->get('role'),
                'content' => $msg->get('content'),
            ];
        }

        return new SsrResponse(
            content: json_encode(['messages' => $result]),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    /**
     * POST /api/chat/send — send a message and get the assistant response.
     */
    public function send(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $raw = method_exists($httpRequest, 'getContent') ? $httpRequest->getContent() : '';
        $body = json_decode($raw, true) ?? [];
        $resolver = new TenantWorkspaceResolver($this->entityTypeManager);
        try {
            $scope = $resolver->resolve($query, $account, $httpRequest, $body);
            $resolver->assertPayloadTenantMatchesContext($body, $scope->tenantId);
        } catch (RequestScopeViolation $exception) {
            return $this->json(['error' => $exception->getMessage()], $exception->statusCode());
        }

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
            if (! empty($ids)) {
                $session = $sessionStorage->load(reset($ids));
            }
        }

        if ($session instanceof ChatSession) {
            if (! $resolver->tenantMatches($session, $scope->tenantId) || ! $resolver->workspaceMatches($session, $scope->workspaceId())) {
                return $this->json(['error' => 'Session not found'], 404);
            }
        }

        if ($session === null) {
            $session = new ChatSession([
                'uuid' => $this->generateUuid(),
                'title' => mb_substr($message, 0, 60),
                'created_at' => (new \DateTimeImmutable)->format('c'),
                'tenant_id' => $scope->tenantId,
                'workspace_id' => $scope->workspaceId(),
            ]);
            $sessionStorage->save($session);
        }

        assert($session instanceof ContentEntityInterface);
        $sessionUuid = $session->get('uuid');

        // Save user message
        $userMsg = new ChatMessage([
            'uuid' => $this->generateUuid(),
            'session_uuid' => $sessionUuid,
            'role' => 'user',
            'content' => $message,
            'created_at' => (new \DateTimeImmutable)->format('c'),
            'tenant_id' => $scope->tenantId,
            'workspace_id' => $scope->workspaceId(),
        ]);
        $messageStorage->save($userMsg);

        // Return message ID for streaming via /stream/chat/{messageId}
        return new SsrResponse(
            content: json_encode([
                'message_id' => $userMsg->get('uuid'),
                'session_id' => $sessionUuid,
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

    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            random_int(0, 0xFFFF), random_int(0, 0xFFFF),
            random_int(0, 0xFFFF),
            random_int(0, 0x0FFF) | 0x4000,
            random_int(0, 0x3FFF) | 0x8000,
            random_int(0, 0xFFFF), random_int(0, 0xFFFF), random_int(0, 0xFFFF),
        );
    }

    private function json(array $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
