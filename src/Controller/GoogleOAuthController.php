<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Entity\Integration;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Entity\EntityTypeManager;

final class GoogleOAuthController
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';

    private const SCOPES = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/calendar.readonly',
        'https://www.googleapis.com/auth/drive.readonly',
    ];

    private readonly string $clientId;

    private readonly string $clientSecret;

    private readonly string $redirectUri;

    /** @phpstan-ignore constructor.unusedParameter */
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        ?Environment $twig = null,
    ) {
        $this->clientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '';
        $this->clientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: '';
        $this->redirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') ?: '';
    }

    public function redirect(
        array $params = [],
        array $query = [],
        mixed $account = null,
        ?Request $httpRequest = null,
        ?Environment $twig = null,
    ): RedirectResponse {
        if ($account === null) {
            return new RedirectResponse('/login', 302);
        }

        $state = bin2hex(random_bytes(32));
        $_SESSION['google_oauth_state'] = $state;

        $authUrl = self::AUTH_ENDPOINT.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return new RedirectResponse($authUrl, 302);
    }

    public function callback(
        array $params = [],
        array $query = [],
        mixed $account = null,
        ?Request $httpRequest = null,
        ?Environment $twig = null,
    ): RedirectResponse {
        try {
            return $this->handleCallback($query, $account);
        } catch (\Throwable $e) {
            // Temporary debug - remove after smoke test
            $debugPath = dirname(__DIR__, 2).'/storage/oauth-debug.log';
            file_put_contents($debugPath, date('c').' '.$e->getMessage()."\n".$e->getTraceAsString()."\n", FILE_APPEND);
            $_SESSION['flash_error'] = 'OAuth error: '.$e->getMessage();

            return new RedirectResponse('/app', 302);
        }
    }

    private function handleCallback(array $query, mixed $account): RedirectResponse
    {
        if ($account === null) {
            return new RedirectResponse('/login', 302);
        }

        if (isset($query['error'])) {
            $_SESSION['flash_error'] = 'Google authorization denied: '.$query['error'];

            return new RedirectResponse('/', 302);
        }

        $expectedState = $_SESSION['google_oauth_state'] ?? null;
        unset($_SESSION['google_oauth_state']);

        if ($expectedState === null || ! hash_equals($expectedState, $query['state'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid OAuth state. Please try again.';

            return new RedirectResponse('/', 302);
        }

        $tokenData = $this->exchangeCodeForTokens($query['code'] ?? '');

        if ($tokenData === null) {
            $_SESSION['flash_error'] = 'Failed to exchange authorization code.';

            return new RedirectResponse('/', 302);
        }

        $userInfo = $this->fetchUserInfo($tokenData['access_token']);
        $providerEmail = $userInfo['email'] ?? null;

        $this->upsertIntegration($account, $tokenData, $providerEmail);

        $_SESSION['flash_success'] = 'Google account connected'
            .($providerEmail ? ' as '.$providerEmail : '').'.';

        return new RedirectResponse('/', 302);
    }

    private function exchangeCodeForTokens(string $code): ?array
    {
        $payload = http_build_query([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
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

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    private function fetchUserInfo(string $accessToken): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'header' => 'Authorization: Bearer '.$accessToken,
                'timeout' => 10,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::USERINFO_ENDPOINT, false, $context);

        if ($response === false) {
            return [];
        }

        $httpCode = $this->parseHttpStatusCode($http_response_header ?? []); // @phpstan-ignore nullCoalesce.variable

        if ($httpCode >= 400) {
            return [];
        }

        return json_decode($response, true, 512, JSON_THROW_ON_ERROR) ?? [];
    }

    private function upsertIntegration(mixed $account, array $tokenData, ?string $providerEmail): void
    {
        $storage = $this->entityTypeManager->getStorage('integration');
        $accountId = $account->get('uuid');

        $existingIds = $storage->getQuery()
            ->condition('account_id', $accountId)
            ->condition('provider', 'google')
            ->range(0, 1)
            ->execute();

        $expiresAt = isset($tokenData['expires_in'])
            ? (new \DateTimeImmutable('+'.$tokenData['expires_in'].' seconds'))->format('c')
            : null;

        $scopes = isset($tokenData['scope'])
            ? json_encode(explode(' ', $tokenData['scope']))
            : json_encode([]);

        if ($existingIds !== []) {
            $integration = $storage->load(reset($existingIds));
            assert($integration instanceof Integration);
            $oldScopes = $integration->get('scopes');

            $integration->set('access_token', $tokenData['access_token']);
            $integration->set('token_expires_at', $expiresAt);
            $integration->set('scopes', $scopes);
            $integration->set('status', 'active');
            $integration->set('provider_email', $providerEmail);

            if (isset($tokenData['refresh_token'])) {
                $integration->set('refresh_token', $tokenData['refresh_token']);
            }

            if ($oldScopes !== $scopes) {
                $metadata = json_decode($integration->get('metadata') ?? '{}', true) ?? [];
                $metadata['scopes_changed_at'] = (new \DateTimeImmutable)->format('c');
                $integration->set('metadata', json_encode($metadata));
            }
        } else {
            $integration = new Integration([
                'uuid' => bin2hex(random_bytes(16)),
                'name' => 'google',
                'account_id' => $accountId,
                'provider' => 'google',
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'token_expires_at' => $expiresAt,
                'scopes' => $scopes,
                'status' => 'active',
                'provider_email' => $providerEmail,
                'metadata' => json_encode([
                    'token_type' => $tokenData['token_type'] ?? 'Bearer',
                ]),
            ]);
        }

        $storage->save($integration);
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
