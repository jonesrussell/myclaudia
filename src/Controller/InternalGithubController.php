<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Support\OAuthTokenManagerInterface;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\SSR\SsrResponse;

final class InternalGithubController
{
    public function __construct(
        private readonly OAuthTokenManagerInterface $tokenManager,
        private readonly InternalApiTokenGenerator $apiTokenGenerator,
    ) {}

    public function notifications(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId, 'github');
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $all = ($query['all'] ?? 'false') === 'true' ? 'true' : 'false';

        $url = 'https://api.github.com/notifications?'.http_build_query(['all' => $all]);
        $response = $this->githubApiGet($url, $accessToken, $accountId);

        return $this->jsonResponse($response);
    }

    public function listIssues(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId, 'github');
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $repo = $query['repo'] ?? '';
        if (! $this->isValidRepoParam($repo)) {
            return $this->jsonError('repo query parameter required (owner/repo format)', 400);
        }

        $state = $query['state'] ?? 'open';
        $labels = $query['labels'] ?? '';

        $queryParams = ['state' => $state];
        if ($labels !== '') {
            $queryParams['labels'] = $labels;
        }

        $url = "https://api.github.com/repos/{$repo}/issues?".http_build_query($queryParams);
        $response = $this->githubApiGet($url, $accessToken, $accountId);

        return $this->jsonResponse($response);
    }

    public function readIssue(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId, 'github');
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $owner = $params['owner'] ?? '';
        $repo = $params['repo'] ?? '';
        $number = $params['number'] ?? '';

        if (! $this->isValidPathSegment($owner) || ! $this->isValidPathSegment($repo) || ! $this->isValidPathSegment($number)) {
            return $this->jsonError('owner, repo, and number are required', 400);
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}/issues/{$number}";
        $response = $this->githubApiGet($url, $accessToken, $accountId);

        return $this->jsonResponse($response);
    }

    public function listPulls(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId, 'github');
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $repo = $query['repo'] ?? '';
        if (! $this->isValidRepoParam($repo)) {
            return $this->jsonError('repo query parameter required (owner/repo format)', 400);
        }

        $state = $query['state'] ?? 'open';

        $url = "https://api.github.com/repos/{$repo}/pulls?".http_build_query(['state' => $state]);
        $response = $this->githubApiGet($url, $accessToken, $accountId);

        return $this->jsonResponse($response);
    }

    public function readPull(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId, 'github');
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $owner = $params['owner'] ?? '';
        $repo = $params['repo'] ?? '';
        $number = $params['number'] ?? '';

        if (! $this->isValidPathSegment($owner) || ! $this->isValidPathSegment($repo) || ! $this->isValidPathSegment($number)) {
            return $this->jsonError('owner, repo, and number are required', 400);
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}/pulls/{$number}";
        $response = $this->githubApiGet($url, $accessToken, $accountId);

        return $this->jsonResponse($response);
    }

    public function createIssue(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId, 'github');
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $owner = $params['owner'] ?? '';
        $repo = $params['repo'] ?? '';

        if (! $this->isValidPathSegment($owner) || ! $this->isValidPathSegment($repo)) {
            return $this->jsonError('owner and repo are required', 400);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $title = $body['title'] ?? '';
        if ($title === '') {
            return $this->jsonError('title is required', 400);
        }

        $issueData = ['title' => $title];
        if (! empty($body['body'])) {
            $issueData['body'] = $body['body'];
        }
        if (! empty($body['labels'])) {
            $issueData['labels'] = $body['labels'];
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}/issues";
        $response = $this->githubApiPost($url, $accessToken, $issueData, $accountId);

        return $this->jsonResponse($response);
    }

    public function addComment(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $accountId = $this->authenticate($httpRequest);
        if ($accountId === null) {
            return $this->jsonError('Unauthorized', 401);
        }

        try {
            $accessToken = $this->tokenManager->getValidAccessToken($accountId, 'github');
        } catch (\RuntimeException $e) {
            return $this->jsonError($e->getMessage(), 503);
        }

        $owner = $params['owner'] ?? '';
        $repo = $params['repo'] ?? '';
        $number = $params['number'] ?? '';

        if (! $this->isValidPathSegment($owner) || ! $this->isValidPathSegment($repo) || ! $this->isValidPathSegment($number)) {
            return $this->jsonError('owner, repo, and number are required', 400);
        }

        $body = $this->getRequestBody($httpRequest);
        if ($body === null) {
            return $this->jsonError('Invalid request body', 400);
        }

        $commentBody = $body['body'] ?? '';
        if ($commentBody === '') {
            return $this->jsonError('body is required', 400);
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}/issues/{$number}/comments";
        $response = $this->githubApiPost($url, $accessToken, ['body' => $commentBody], $accountId);

        return $this->jsonResponse($response);
    }

    private function authenticate(mixed $httpRequest): ?string
    {
        $auth = '';
        if ($httpRequest instanceof Request) {
            $auth = $httpRequest->headers->get('Authorization', '');
        }

        if (! str_starts_with($auth, 'Bearer ')) {
            return null;
        }

        return $this->apiTokenGenerator->validate(substr($auth, 7));
    }

    /**
     * @return array<string, mixed>
     */
    private function githubApiGet(string $url, string $accessToken, string $accountId): array
    {
        $context = stream_context_create([
            'http' => [
                'header' => "Authorization: Bearer {$accessToken}\r\nUser-Agent: Claudriel\r\nAccept: application/vnd.github+json\r\n",
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['error' => 'GitHub API request failed'];
        }

        /** @phpstan-ignore nullCoalesce.variable */
        $statusCode = $this->parseHttpStatusCode($http_response_header ?? []);
        if ($statusCode === 401) {
            $this->tokenManager->markRevoked($accountId, 'github');

            return ['error' => 'GitHub token revoked', 'status' => 401];
        }

        return json_decode($response, true) ?? ['error' => 'Invalid GitHub API response'];
    }

    /**
     * @return array<string, mixed>
     */
    private function githubApiPost(string $url, string $accessToken, array $data, string $accountId): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$accessToken}\r\nUser-Agent: Claudriel\r\nAccept: application/vnd.github+json\r\nContent-Type: application/json\r\n",
                'content' => json_encode($data, JSON_THROW_ON_ERROR),
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        if ($response === false) {
            return ['error' => 'GitHub API request failed'];
        }

        /** @phpstan-ignore nullCoalesce.variable */
        $statusCode = $this->parseHttpStatusCode($http_response_header ?? []);
        if ($statusCode === 401) {
            $this->tokenManager->markRevoked($accountId, 'github');

            return ['error' => 'GitHub token revoked', 'status' => 401];
        }

        return json_decode($response, true) ?? ['error' => 'Invalid GitHub API response'];
    }

    /**
     * @param  list<string>  $headers
     */
    private function parseHttpStatusCode(array $headers): int
    {
        $httpCode = 0;

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $header, $m)) {
                $httpCode = (int) $m[1];
            }
        }

        return $httpCode;
    }

    /**
     * Validate that a GitHub path segment (owner, repo, number) contains only safe characters.
     * Prevents SSRF via path traversal (e.g., "owner/../admin").
     */
    private function isValidPathSegment(string $value): bool
    {
        return $value !== '' && $value !== '.' && $value !== '..' && preg_match('/^[a-zA-Z0-9._-]+$/', $value) === 1;
    }

    /**
     * Validate owner/repo format from a combined "owner/repo" query parameter.
     */
    private function isValidRepoParam(string $repo): bool
    {
        if (! str_contains($repo, '/')) {
            return false;
        }

        $parts = explode('/', $repo, 2);

        return $this->isValidPathSegment($parts[0]) && $this->isValidPathSegment($parts[1]);
    }

    private function getRequestBody(mixed $httpRequest): ?array
    {
        if (! $httpRequest instanceof Request) {
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
