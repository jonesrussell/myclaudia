<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Integration;
use Claudriel\Service\PublicAccountSignupService;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\OAuthProvider\OAuthStateManager;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\ProviderRegistry;
use Waaseyaa\OAuthProvider\SessionInterface;
use Waaseyaa\User\Middleware\CsrfMiddleware;

final class OAuthController
{
    /** @var array<string, array<string, list<string>>> */
    private const FLOW_SCOPES = [
        'google' => [
            'connect' => [
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
                'https://www.googleapis.com/auth/calendar.freebusy',
                'https://www.googleapis.com/auth/drive.file',
            ],
            'signin' => [
                'openid',
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile',
            ],
        ],
        'github' => [
            'connect' => [
                'repo',
                'notifications',
                'read:org',
            ],
            'signin' => [
                'user:email',
                'read:user',
            ],
        ],
    ];

    /** @phpstan-ignore constructor.unusedParameter */
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly OAuthStateManager $stateManager,
        private readonly EntityTypeManager $entityTypeManager,
        private readonly PublicAccountSignupService $signupService,
        private readonly SessionInterface $session,
        ?Environment $twig = null,
    ) {}

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

        $provider = $params['provider'] ?? '';

        if (! $this->providerRegistry->has($provider) || ! isset(self::FLOW_SCOPES[$provider])) {
            $_SESSION['flash_error'] = "Unknown OAuth provider: {$provider}";

            return new RedirectResponse('/app', 302);
        }

        $state = $this->stateManager->generate($this->session);
        $scopes = self::FLOW_SCOPES[$provider]['connect'];

        $authUrl = $this->providerRegistry->get($provider)->getAuthorizationUrl($scopes, $state);

        return new RedirectResponse($authUrl, 302);
    }

    public function connectCallback(
        array $params = [],
        array $query = [],
        ?AccountInterface $account = null,
        ?Request $httpRequest = null,
    ): RedirectResponse {
        $authenticatedAccount = $this->resolveAccount($account);
        if ($authenticatedAccount === null) {
            return new RedirectResponse('/login', 302);
        }

        $provider = $params['provider'] ?? '';

        if (isset($query['error'])) {
            $_SESSION['flash_error'] = ucfirst($provider).' authorization denied: '.$query['error'];

            return new RedirectResponse('/app', 302);
        }

        if (! $this->stateManager->validate($this->session, $query['state'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid OAuth state. Please try again.';

            return new RedirectResponse('/app', 302);
        }

        if (! $this->providerRegistry->has($provider)) {
            $_SESSION['flash_error'] = "Unknown OAuth provider: {$provider}";

            return new RedirectResponse('/app', 302);
        }

        $oauthProvider = $this->providerRegistry->get($provider);

        try {
            $token = $oauthProvider->exchangeCode($query['code'] ?? '');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to exchange authorization code.';

            return new RedirectResponse('/app', 302);
        }

        $profile = $oauthProvider->getUserProfile($token->accessToken);

        $this->upsertIntegration($authenticatedAccount, $provider, $token, $profile->email);

        $_SESSION['flash_success'] = ucfirst($provider).' account connected as '.$profile->email.'.';

        return new RedirectResponse('/app', 302);
    }

    public function signin(
        array $params = [],
        array $query = [],
        ?AccountInterface $account = null,
        ?Request $httpRequest = null,
    ): RedirectResponse {
        $provider = $params['provider'] ?? '';

        if (! $this->providerRegistry->has($provider) || ! isset(self::FLOW_SCOPES[$provider])) {
            $_SESSION['flash_error'] = "Unknown OAuth provider: {$provider}";

            return new RedirectResponse('/login', 302);
        }

        $state = $this->stateManager->generate($this->session);
        $this->session->set('oauth_flow', 'signin');
        $scopes = self::FLOW_SCOPES[$provider]['signin'];

        $signinKey = $provider.'-signin';
        $oauthProvider = $this->providerRegistry->has($signinKey)
            ? $this->providerRegistry->get($signinKey)
            : $this->providerRegistry->get($provider);

        $authUrl = $oauthProvider->getAuthorizationUrl($scopes, $state);

        return new RedirectResponse($authUrl, 302);
    }

    public function signinCallback(
        array $params = [],
        array $query = [],
        ?AccountInterface $account = null,
        ?Request $httpRequest = null,
    ): RedirectResponse {
        $provider = $params['provider'] ?? '';

        if (isset($query['error'])) {
            $_SESSION['flash_error'] = ucfirst($provider).' sign-in denied: '.$query['error'];

            return new RedirectResponse('/login', 302);
        }

        if (! $this->stateManager->validate($this->session, $query['state'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid OAuth state. Please try again.';

            return new RedirectResponse('/login', 302);
        }

        $flow = $this->session->get('oauth_flow');
        $this->session->remove('oauth_flow');

        if ($flow !== 'signin') {
            $_SESSION['flash_error'] = 'Invalid OAuth flow. Please try again.';

            return new RedirectResponse('/login', 302);
        }

        if (! $this->providerRegistry->has($provider)) {
            $_SESSION['flash_error'] = "Unknown OAuth provider: {$provider}";

            return new RedirectResponse('/login', 302);
        }

        $signinKey = $provider.'-signin';
        $oauthProvider = $this->providerRegistry->has($signinKey)
            ? $this->providerRegistry->get($signinKey)
            : $this->providerRegistry->get($provider);

        try {
            $token = $oauthProvider->exchangeCode($query['code'] ?? '');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to exchange authorization code.';

            return new RedirectResponse('/login', 302);
        }

        $profile = $oauthProvider->getUserProfile($token->accessToken);

        if ($profile->email === '') {
            $_SESSION['flash_error'] = ucfirst($provider).' account email is not available or not verified.';

            return new RedirectResponse('/login', 302);
        }

        $accountEntity = $this->signupService->createFromOAuth($provider, $profile->email, $profile->name);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['claudriel_account_uuid'] = $accountEntity->get('uuid');
        session_regenerate_id(true);
        CsrfMiddleware::regenerate();

        $this->upsertIntegration(
            new AuthenticatedAccount($accountEntity),
            $provider,
            $token,
            $profile->email,
        );

        return new RedirectResponse('/app', 302);
    }

    private function upsertIntegration(
        AuthenticatedAccount $account,
        string $provider,
        OAuthToken $token,
        string $providerEmail,
    ): void {
        $storage = $this->entityTypeManager->getStorage('integration');
        $accountId = $account->getUuid();

        $existingIds = $storage->getQuery()
            ->condition('account_id', $accountId)
            ->condition('provider', $provider)
            ->range(0, 1)
            ->execute();

        $expiresAt = $token->expiresAt?->format('c');
        $scopes = json_encode($token->scopes);

        if ($existingIds !== []) {
            $integration = $storage->load(reset($existingIds));
            assert($integration instanceof Integration);
            $oldScopes = $integration->get('scopes');

            $integration->set('access_token', $token->accessToken);
            $integration->set('token_expires_at', $expiresAt);
            $integration->set('scopes', $scopes);
            $integration->set('status', 'active');
            $integration->set('provider_email', $providerEmail);

            if ($token->refreshToken !== null) {
                $integration->set('refresh_token', $token->refreshToken);
            }

            if ($oldScopes !== $scopes) {
                $metadata = json_decode($integration->get('metadata') ?? '{}', true) ?? [];
                $metadata['scopes_changed_at'] = (new \DateTimeImmutable)->format('c');
                $integration->set('metadata', json_encode($metadata));
            }
        } else {
            $integration = new Integration([
                'uuid' => bin2hex(random_bytes(16)),
                'name' => $provider,
                'account_id' => $accountId,
                'provider' => $provider,
                'access_token' => $token->accessToken,
                'refresh_token' => $token->refreshToken,
                'token_expires_at' => $expiresAt,
                'scopes' => $scopes,
                'status' => 'active',
                'provider_email' => $providerEmail,
                'metadata' => json_encode([
                    'token_type' => $token->tokenType,
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
}
