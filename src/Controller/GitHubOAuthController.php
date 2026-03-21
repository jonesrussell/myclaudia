<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Integration;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;

final class GitHubOAuthController
{
    private const AUTH_ENDPOINT = 'https://github.com/login/oauth/authorize';

    private const TOKEN_ENDPOINT = 'https://github.com/login/oauth/access_token';

    private const USER_ENDPOINT = 'https://api.github.com/user';

    private const SCOPES = [
        'repo',
        'notifications',
        'read:org',
    ];

    private readonly string $clientId;

    private readonly string $clientSecret;

    private readonly string $redirectUri;

    /** @phpstan-ignore constructor.unusedParameter */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        ?Environment $twig = null,
    ) {
        $this->clientId = $_ENV['GITHUB_CLIENT_ID'] ?? getenv('GITHUB_CLIENT_ID') ?: '';
        $this->clientSecret = $_ENV['GITHUB_CLIENT_SECRET'] ?? getenv('GITHUB_CLIENT_SECRET') ?: '';
        $this->redirectUri = $_ENV['GITHUB_REDIRECT_URI'] ?? getenv('GITHUB_REDIRECT_URI') ?: '';
    }

    public function connect(
        array $params = [],
        array $query = [],
        ?AccountInterface $account = null,
        ?Request $httpRequest = null,
    ): RedirectResponse {
        $authenticatedAccount = $this->resolveAccount($account);
        if ($authenticatedAccount === null) {
            return new RedirectResponse('/login', 302);
        }

        $state = bin2hex(random_bytes(32));
        $_SESSION['github_oauth_state'] = $state;

        $authUrl = self::AUTH_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', self::SCOPES),
            'state' => $state,
        ]);

        return new RedirectResponse($authUrl, 302);
    }

    public function callback(
        array $params = [],
        array $query = [],
        ?AccountInterface $account = null,
        ?Request $httpRequest = null,
    ): RedirectResponse {
        $authenticatedAccount = $this->resolveAccount($account);

        if ($authenticatedAccount === null) {
            return new RedirectResponse('/login', 302);
        }

        if (isset($query['error'])) {
            $_SESSION['flash_error'] = 'GitHub authorization denied: '.$query['error'];

            return new RedirectResponse('/app', 302);
        }

        $expectedState = $_SESSION['github_oauth_state'] ?? null;
        unset($_SESSION['github_oauth_state']);

        if ($expectedState === null || ! hash_equals($expectedState, $query['state'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid OAuth state. Please try again.';

            return new RedirectResponse('/app', 302);
        }

        $tokenData = $this->exchangeCodeForToken($query['code'] ?? '');

        if ($tokenData === null) {
            $_SESSION['flash_error'] = 'Failed to exchange authorization code.';

            return new RedirectResponse('/app', 302);
        }

        $userInfo = $this->fetchUserInfo($tokenData['access_token']);
        $githubUsername = $userInfo['login'] ?? null;

        $this->upsertIntegration($authenticatedAccount, $tokenData, $githubUsername);

        $_SESSION['flash_success'] = 'GitHub account connected'
            .($githubUsername ? ' as '.$githubUsername : '').'.';

        return new RedirectResponse('/app', 302);
    }

    private function exchangeCodeForToken(string $code): ?array
    {
        $payload = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json",
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::TOKEN_ENDPOINT, false, $context);

        if ($response === false) {
            return null;
        }

        $httpCode = $this->parseHttpStatusCode($http_response_header ?? []); // @phpstan-ignore nullCoalesce.variable

        if ($httpCode >= 400) {
            return null;
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        if (isset($data['error'])) {
            return null;
        }

        return $data;
    }

    private function fetchUserInfo(string $accessToken): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => "Authorization: Bearer {$accessToken}\r\nUser-Agent: Claudriel/1.0\r\nAccept: application/json",
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::USER_ENDPOINT, false, $context);

        if ($response === false) {
            return [];
        }

        $httpCode = $this->parseHttpStatusCode($http_response_header ?? []); // @phpstan-ignore nullCoalesce.variable

        if ($httpCode >= 400) {
            return [];
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    private function upsertIntegration(AuthenticatedAccount $account, array $tokenData, ?string $githubUsername): void
    {
        $storage = $this->entityTypeManager->getStorage('integration');
        $accountId = $account->getUuid();

        $existingIds = $storage->getQuery()
            ->condition('account_id', $accountId)
            ->condition('provider', 'github')
            ->range(0, 1)
            ->execute();

        $scopes = isset($tokenData['scope'])
            ? json_encode(explode(',', $tokenData['scope']))
            : json_encode([]);

        if ($existingIds !== []) {
            $integration = $storage->load(reset($existingIds));
            assert($integration instanceof Integration);

            $integration->set('access_token', $tokenData['access_token']);
            $integration->set('scopes', $scopes);
            $integration->set('status', 'active');
            $integration->set('provider_email', $githubUsername);
        } else {
            $integration = new Integration([
                'uuid' => bin2hex(random_bytes(16)),
                'name' => 'github',
                'account_id' => $accountId,
                'provider' => 'github',
                'access_token' => $tokenData['access_token'],
                'refresh_token' => null,
                'token_expires_at' => null,
                'scopes' => $scopes,
                'status' => 'active',
                'provider_email' => $githubUsername,
                'metadata' => json_encode([
                    'token_type' => $tokenData['token_type'] ?? 'bearer',
                ]),
            ]);
        }

        $storage->save($integration);
    }

    private function resolveAccount(mixed $account): ?AuthenticatedAccount
    {
        if ($account instanceof AuthenticatedAccount) {
            return $account;
        }

        return (new AuthenticatedAccountSessionResolver($this->entityTypeManager))->resolve();
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
}
