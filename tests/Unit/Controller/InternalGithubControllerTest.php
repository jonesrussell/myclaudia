<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\InternalGithubController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Support\OAuthTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class InternalGithubControllerTest extends TestCase
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
        $request = Request::create('/api/internal/github/notifications');

        $response = $controller->notifications(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
        self::assertStringContainsString('Unauthorized', $response->content);
    }

    public function test_rejects_invalid_bearer_token(): void
    {
        $controller = $this->controller();
        $request = Request::create('/api/internal/github/notifications');
        $request->headers->set('Authorization', 'Bearer invalid-token');

        $response = $controller->notifications(httpRequest: $request);

        self::assertSame(401, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // Missing GitHub integration (503)
    // -----------------------------------------------------------------------

    public function test_returns_503_when_no_github_integration(): void
    {
        $tokenManager = $this->createMock(OAuthTokenManagerInterface::class);
        $tokenManager->method('getValidAccessToken')
            ->willThrowException(new \RuntimeException('No active GitHub integration for account acct-123'));

        $controller = new InternalGithubController($tokenManager, $this->tokenGenerator);
        $request = $this->authenticatedRequest('/api/internal/github/notifications', 'acct-123');

        $response = $controller->notifications(httpRequest: $request);

        self::assertSame(503, $response->statusCode);
        self::assertStringContainsString('No active GitHub integration', $response->content);
    }

    // -----------------------------------------------------------------------
    // notifications
    // -----------------------------------------------------------------------

    public function test_notifications_returns_200_with_valid_auth(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/notifications', 'acct-123');

        $response = $controller->notifications(httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // listIssues
    // -----------------------------------------------------------------------

    public function test_list_issues_requires_repo_param(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/issues', 'acct-123');

        $response = $controller->listIssues(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('repo query parameter required', $response->content);
    }

    public function test_list_issues_rejects_repo_without_slash(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/issues', 'acct-123');

        $response = $controller->listIssues(query: ['repo' => 'invalid-repo'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('repo query parameter required', $response->content);
    }

    public function test_list_issues_accepts_valid_repo(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/issues', 'acct-123');

        $response = $controller->listIssues(query: ['repo' => 'owner/repo'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // readIssue
    // -----------------------------------------------------------------------

    public function test_read_issue_requires_owner_repo_number(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/issue/owner/repo/', 'acct-123');

        $response = $controller->readIssue(params: ['owner' => 'owner', 'repo' => 'repo', 'number' => ''], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('owner, repo, and number are required', $response->content);
    }

    public function test_read_issue_accepts_valid_params(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/issue/owner/repo/1', 'acct-123');

        $response = $controller->readIssue(params: ['owner' => 'owner', 'repo' => 'repo', 'number' => '1'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // listPulls
    // -----------------------------------------------------------------------

    public function test_list_pulls_requires_repo_param(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/pulls', 'acct-123');

        $response = $controller->listPulls(httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('repo query parameter required', $response->content);
    }

    // -----------------------------------------------------------------------
    // readPull
    // -----------------------------------------------------------------------

    public function test_read_pull_requires_owner_repo_number(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/pull/owner/repo/', 'acct-123');

        $response = $controller->readPull(params: ['owner' => '', 'repo' => 'repo', 'number' => '1'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('owner, repo, and number are required', $response->content);
    }

    // -----------------------------------------------------------------------
    // createIssue
    // -----------------------------------------------------------------------

    public function test_create_issue_rejects_invalid_body(): void
    {
        $controller = $this->controller();
        $token = $this->tokenGenerator->generate('acct-123');
        $request = Request::create('/api/internal/github/issue/owner/repo', 'POST', content: 'not json');
        $request->headers->set('Authorization', 'Bearer '.$token);

        $response = $controller->createIssue(params: ['owner' => 'owner', 'repo' => 'repo'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('Invalid request body', $response->content);
    }

    public function test_create_issue_rejects_missing_title(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/github/issue/owner/repo', 'acct-123', ['body' => 'description only']);

        $response = $controller->createIssue(params: ['owner' => 'owner', 'repo' => 'repo'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('title is required', $response->content);
    }

    public function test_create_issue_accepts_valid_issue(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/github/issue/owner/repo', 'acct-123', [
            'title' => 'Bug report',
            'body' => 'Something is broken',
            'labels' => ['bug'],
        ]);

        $response = $controller->createIssue(params: ['owner' => 'owner', 'repo' => 'repo'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // addComment
    // -----------------------------------------------------------------------

    public function test_add_comment_rejects_empty_body(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/github/comment/owner/repo/1', 'acct-123', ['body' => '']);

        $response = $controller->addComment(params: ['owner' => 'owner', 'repo' => 'repo', 'number' => '1'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('body is required', $response->content);
    }

    public function test_add_comment_rejects_invalid_body(): void
    {
        $controller = $this->controller();
        $token = $this->tokenGenerator->generate('acct-123');
        $request = Request::create('/api/internal/github/comment/owner/repo/1', 'POST', content: 'not json');
        $request->headers->set('Authorization', 'Bearer '.$token);

        $response = $controller->addComment(params: ['owner' => 'owner', 'repo' => 'repo', 'number' => '1'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
        self::assertStringContainsString('Invalid request body', $response->content);
    }

    public function test_add_comment_accepts_valid_comment(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/github/comment/owner/repo/1', 'acct-123', ['body' => 'Looks good!']);

        $response = $controller->addComment(params: ['owner' => 'owner', 'repo' => 'repo', 'number' => '1'], httpRequest: $request);

        self::assertSame(200, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // SSRF / Path Traversal Protection
    // -----------------------------------------------------------------------

    public function test_read_issue_rejects_path_traversal_in_owner(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/issue/../admin/repo/1', 'acct-123');

        $response = $controller->readIssue(params: ['owner' => '../admin', 'repo' => 'repo', 'number' => '1'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
    }

    public function test_read_issue_rejects_slash_in_repo(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/issue/owner/repo%2F..%2Fadmin/1', 'acct-123');

        $response = $controller->readIssue(params: ['owner' => 'owner', 'repo' => 'repo/../admin', 'number' => '1'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
    }

    public function test_list_issues_rejects_path_traversal_in_repo_param(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedRequest('/api/internal/github/issues', 'acct-123');

        $response = $controller->listIssues(query: ['repo' => '../admin/secret'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
    }

    public function test_create_issue_rejects_path_traversal(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/github/issue/../admin/repo', 'acct-123', ['title' => 'test']);

        $response = $controller->createIssue(params: ['owner' => '..', 'repo' => 'admin'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
    }

    public function test_add_comment_rejects_path_traversal(): void
    {
        $controller = $this->controller();
        $request = $this->authenticatedPostRequest('/api/internal/github/comment/../admin/repo/1', 'acct-123', ['body' => 'test']);

        $response = $controller->addComment(params: ['owner' => '..', 'repo' => 'admin', 'number' => '1'], httpRequest: $request);

        self::assertSame(400, $response->statusCode);
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function controller(?InternalApiTokenGenerator $tokenGenerator = null): InternalGithubController
    {
        $tokenManager = $this->createMock(OAuthTokenManagerInterface::class);
        $tokenManager->method('getValidAccessToken')->willReturn('mock-github-access-token');

        return new InternalGithubController($tokenManager, $tokenGenerator ?? $this->tokenGenerator);
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
