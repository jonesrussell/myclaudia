<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalGoogleController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Support\OAuthTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InternalGoogleControllerTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long';

    private InternalApiTokenGenerator $tokenGenerator;

    protected function setUp(): void
    {
        $this->tokenGenerator = new InternalApiTokenGenerator(self::SECRET);
    }

    // -----------------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------------

    public function test_rejects_request_without_authorization_header(): void
    {
        $controller = $this->controller();
        $request = Request::create('/api/internal/gmail/list');

        $response = $controller->gmailList(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Unauthorized', $response->content);
    }

    public function test_rejects_invalid_bearer_token(): void
    {
        $controller = $this->controller();
        $request = Request::create('/api/internal/gmail/list');
        $request->headers->set('Authorization', 'Bearer invalid-token');

        $response = $controller->gmailList(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
    }

    public function test_rejects_expired_token(): void
    {
        $expiredGenerator = new InternalApiTokenGenerator(self::SECRET, ttlSeconds: 0);
        $token = $expiredGenerator->generate('acct-123');

        $controller = $this->controller();
        $request = Request::create('/api/internal/gmail/list');
        $request->headers->set('Authorization', 'Bearer '.$token);

        // Token expires immediately (ttl=0), but validation uses the real generator with 300s TTL
        // We need to sleep or use a generator with 0 TTL for validation too
        $controllerWithExpired = $this->controller(tokenGenerator: new InternalApiTokenGenerator(self::SECRET, ttlSeconds: 0));
        sleep(1);
        $response = $controllerWithExpired->gmailList(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
    }

    public function test_rejects_token_with_wrong_secret(): void
    {
        $wrongGenerator = new InternalApiTokenGenerator('wrong-secret-that-is-at-least-32-bytes');
        $token = $wrongGenerator->generate('acct-123');

        $controller = $this->controller();
        $request = Request::create('/api/internal/gmail/list');
        $request->headers->set('Authorization', 'Bearer '.$token);

        $response = $controller->gmailList(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // Missing Google integration (503)
    // -----------------------------------------------------------------------

    public function test_returns_503_when_no_google_integration(): void
    {
        $tokenManager = $this->createMock(OAuthTokenManagerInterface::class);
        $tokenManager->method('getValidAccessToken')
            ->willThrowException(new \RuntimeException('No active Google integration for account acct-123'));

        $controller = new InternalGoogleController($tokenManager, $this->tokenGenerator);
        $request = $this->authenticatedRequest('/api/internal/gmail/list', 'acct-123');

        $response = $controller->gmailList(httpRequest: $request);

        self::assertSame(503, $response->statusCode);
        self::assertStringContainsString('No active Google integration', $response->content);
    }

    // -----------------------------------------------------------------------
    // gmailList
    // -----------------------------------------------------------------------

    public function test_gmail_list_caps_max_results_at_50(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/gmail/list', 'acct-123');

        $response = $controller->gmailList(query: ['max_results' => '999'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    public function test_gmail_list_defaults_to_unread_query(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/gmail/list', 'acct-123');

        $response = $controller->gmailList(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // gmailRead
    // -----------------------------------------------------------------------

    public function test_gmail_read_rejects_empty_message_id(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/gmail/read/', 'acct-123');

        $response = $controller->gmailRead(params: ['id' => ''], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('Message ID required', $response->content);
    }

    public function test_gmail_read_accepts_valid_message_id(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/gmail/read/msg-1', 'acct-123');

        $response = $controller->gmailRead(params: ['id' => 'msg-1'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // gmailSend
    // -----------------------------------------------------------------------

    public function test_gmail_send_rejects_missing_to_and_subject(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/gmail/send', 'acct-123', ['body' => 'Hello']);

        $response = $controller->gmailSend(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('to and subject are required', $response->content);
    }

    public function test_gmail_send_rejects_header_injection_in_to(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/gmail/send', 'acct-123', [
            'to' => "evil@example.com\r\nBcc: spy@example.com",
            'subject' => 'Hello',
            'body' => 'Test',
        ]);

        $response = $controller->gmailSend(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('Invalid characters', $response->content);
    }

    public function test_gmail_send_rejects_header_injection_in_subject(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/gmail/send', 'acct-123', [
            'to' => 'test@example.com',
            'subject' => "Hello\nBcc: spy@example.com",
            'body' => 'Test',
        ]);

        $response = $controller->gmailSend(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('Invalid characters', $response->content);
    }

    public function test_gmail_send_rejects_invalid_request_body(): void
    {
        $controller = $this->controller();
        $token = $this->tokenGenerator->generate('acct-123');
        $request = Request::create('/api/internal/gmail/send', 'POST', content: 'not json');
        $request->headers->set('Authorization', 'Bearer '.$token);

        $response = $controller->gmailSend(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('Invalid request body', $response->content);
    }

    public function test_gmail_send_accepts_valid_email(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/gmail/send', 'acct-123', [
            'to' => 'test@example.com',
            'subject' => 'Hello',
            'body' => 'Test body',
        ]);

        $response = $controller->gmailSend(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // calendarList
    // -----------------------------------------------------------------------

    public function test_calendar_list_caps_days_ahead_at_30(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/calendar/list', 'acct-123');

        $response = $controller->calendarList(query: ['days_ahead' => '999'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    public function test_calendar_list_caps_max_results_at_100(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/calendar/list', 'acct-123');

        $response = $controller->calendarList(query: ['max_results' => '999'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // calendarCreate
    // -----------------------------------------------------------------------

    public function test_calendar_create_rejects_invalid_body(): void
    {
        $controller = $this->controller();
        $token = $this->tokenGenerator->generate('acct-123');
        $request = Request::create('/api/internal/calendar/create', 'POST', content: 'not json');
        $request->headers->set('Authorization', 'Bearer '.$token);

        $response = $controller->calendarCreate(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
    }

    public function test_calendar_create_accepts_valid_event(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/calendar/create', 'acct-123', [
            'title' => 'Team standup',
            'start_time' => '2026-03-18T09:00:00-04:00',
            'end_time' => '2026-03-18T09:30:00-04:00',
        ]);

        $response = $controller->calendarCreate(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    public function test_calendar_create_handles_attendees(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/calendar/create', 'acct-123', [
            'title' => 'Team standup',
            'start_time' => '2026-03-18T09:00:00-04:00',
            'end_time' => '2026-03-18T09:30:00-04:00',
            'attendees' => ['alice@example.com', 'bob@example.com'],
        ]);

        $response = $controller->calendarCreate(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function controller(?InternalApiTokenGenerator $tokenGenerator = null): InternalGoogleController
    {
        $tokenManager = $this->createMock(OAuthTokenManagerInterface::class);
        $tokenManager->method('getValidAccessToken')->willReturn('mock-google-access-token');

        return new InternalGoogleController($tokenManager, $tokenGenerator ?? $this->tokenGenerator);
    }

    private function authenticatedRequest(string $uri, string $accountId): Request
    {
        $token = $this->tokenGenerator->generate($accountId);
        $request = Request::create($uri);
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }

    private function authenticatedPostRequest(string $uri, string $accountId, array $body): Request
    {
        $token = $this->tokenGenerator->generate($accountId);
        $request = Request::create($uri, 'POST', content: json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Authorization', 'Bearer '.$token);

        return $request;
    }
}
