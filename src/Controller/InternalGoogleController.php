<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Support\GoogleTokenManagerInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalGoogleController
{
    public function __construct(
        private readonly GoogleTokenManagerInterface $tokenManager,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
    ) {}

    public function gmailList(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $q = $query['q'] ?? 'is:unread';
        $maxResults = min((int) ($query['max_results'] ?? 10), 50);

        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages?'
            . http_build_query(['q' => $q, 'maxResults' => $maxResults]);

        $response = $this->googleApiGet($url, $accessToken);

        return $this->jsonResponse($response);
    }

    public function gmailRead(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $messageId = $params['id'] ?? '';
        if ($messageId === '') {
            return $this->jsonError('Message ID required', 400);
        }

        $url = "https://gmail.googleapis.com/gmail/v1/users/me/messages/{$messageId}?format=full";
        $response = $this->googleApiGet($url, $accessToken);

        return $this->jsonResponse($response);
    }

    public function gmailSend(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $to = $body['to'] ?? '';
        $subject = $body['subject'] ?? '';
        $bodyText = $body['body'] ?? '';

        if ($to === '' || $subject === '') {
            return $this->jsonError('to and subject are required', 400);
        }

        $rawMessage = "To: {$to}\r\nSubject: {$subject}\r\nContent-Type: text/plain; charset=utf-8\r\n\r\n{$bodyText}";
        $encoded = rtrim(strtr(base64_encode($rawMessage), '+/', '-_'), '=');

        $url = 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send';
        $response = $this->googleApiPost($url, $accessToken, ['raw' => $encoded]);

        return $this->jsonResponse($response);
    }

    public function calendarList(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $daysAhead = min((int) ($query['days_ahead'] ?? 7), 30);
        $maxResults = min((int) ($query['max_results'] ?? 20), 100);

        $timeMin = (new \DateTimeImmutable)->format(\DateTimeInterface::RFC3339);
        $timeMax = (new \DateTimeImmutable("+{$daysAhead} days"))->format(\DateTimeInterface::RFC3339);

        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events?'
            . http_build_query([
                'timeMin' => $timeMin,
                'timeMax' => $timeMax,
                'maxResults' => $maxResults,
                'singleEvents' => 'true',
                'orderBy' => 'startTime',
            ]);

        $response = $this->googleApiGet($url, $accessToken);

        return $this->jsonResponse($response);
    }

    public function calendarCreate(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId);
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $eventData = [
            'summary' => $body['title'] ?? '',
            'start' => ['dateTime' => $body['start_time'] ?? ''],
            'end' => ['dateTime' => $body['end_time'] ?? ''],
        ];

        if (! empty($body['description'])) {
            $eventData['description'] = $body['description'];
        }

        if (! empty($body['attendees'])) {
            $eventData['attendees'] = array_map(
                static fn (string $email) => ['email' => $email],
                $body['attendees'],
            );
        }

        $url = 'https://www.googleapis.com/calendar/v3/calendars/primary/events';
        $response = $this->googleApiPost($url, $accessToken, $eventData);

        return $this->jsonResponse($response);
    }

    private function authenticate(mixed $httpRequest): ?string
    {
        $auth = '';
        if ($httpRequest instanceof \Symfony\Component\HttpFoundation\Request) {
            $auth = $httpRequest->headers->get('Authorization', '');
        }

        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return $this->apiTokenGenerator->validate(substr($auth, 7));
    }

    private function googleApiGet(string $url, string $accessToken): array
    {
        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: Bearer {$accessToken}\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['error' => 'Google API request failed'];
        }

        return json_decode($response, true) ?? ['error' => 'Invalid Google API response'];
    }

    private function googleApiPost(string $url, string $accessToken, array $data): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$accessToken}\r\nContent-Type: application/json\r\n",
                'content' => json_encode($data, JSON_THROW_ON_ERROR),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['error' => 'Google API request failed'];
        }

        return json_decode($response, true) ?? ['error' => 'Invalid Google API response'];
    }

    private function getRequestBody(mixed $httpRequest): ?array
    {
        if (! $httpRequest instanceof \Symfony\Component\HttpFoundation\Request) {
            return null;
        }

        $content = $httpRequest->getContent();
        if ($content === '') {
            return null;
        }

        $data = json_decode($content, true);

        return is_array($data) ? $data : null;
    }

    private function jsonResponse(array $data): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function jsonError(string $message, int $statusCode): SsrResponse
    {
        return new SsrResponse(
            content: json_encode(['error' => $message], JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
