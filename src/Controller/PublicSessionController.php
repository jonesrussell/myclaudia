<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Account;
use Claudriel\Entity\Tenant;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;
use Waaseyaa\User\Middleware\CsrfMiddleware;

final class PublicSessionController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
    ) {}

    public function loginForm(array $params = [], array $query = []): RedirectResponse|SsrResponse
    {
        if ($account = $this->authenticatedAccountFromSession()) {
            return new RedirectResponse($this->appUrl((string) $account->getTenantId(), $this->defaultWorkspaceUuidForTenant((string) $account->getTenantId())), 302);
        }

        return $this->render('public/login.twig', [
            'csrf_token' => CsrfMiddleware::token(),
            'email' => (string) ($query['email'] ?? ''),
            'error' => null,
        ]);
    }

    public function login(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): RedirectResponse|SsrResponse
    {
        $request = $httpRequest ?? Request::create('/login', 'POST');
        $email = strtolower(trim((string) $request->request->get('email', '')));
        $password = (string) $request->request->get('password', '');

        if ($email === '' || $password === '') {
            return $this->render('public/login.twig', [
                'csrf_token' => CsrfMiddleware::token(),
                'email' => $email,
                'error' => 'Email and password are required.',
            ], 422);
        }

        $resolvedAccount = $this->findVerifiedAccountByEmail($email);
        if (! $resolvedAccount instanceof Account || ! password_verify($password, (string) $resolvedAccount->get('password_hash'))) {
            return $this->render('public/login.twig', [
                'csrf_token' => CsrfMiddleware::token(),
                'email' => $email,
                'error' => 'Invalid credentials.',
            ], 401);
        }

        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['claudriel_account_uuid'] = $resolvedAccount->get('uuid');
        session_regenerate_id(true);
        CsrfMiddleware::regenerate();

        $tenantId = (string) ($resolvedAccount->get('tenant_id') ?? '');
        $workspaceUuid = $this->defaultWorkspaceUuidForTenant($tenantId);
        $query = ['login' => '1'];
        if ($tenantId !== '') {
            $query['tenant_id'] = $tenantId;
        }
        if ($workspaceUuid !== null) {
            $query['workspace_uuid'] = $workspaceUuid;
        }

        return new RedirectResponse('/app?'.http_build_query($query), 302);
    }

    public function logout(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): RedirectResponse
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        unset($_SESSION['claudriel_account_uuid']);
        session_regenerate_id(true);
        CsrfMiddleware::regenerate();

        return new RedirectResponse('/?logged_out=1', 302);
    }

    public function sessionState(array $params = [], array $query = [], mixed $account = null): SsrResponse
    {
        if (! $account instanceof AuthenticatedAccount) {
            return new SsrResponse(
                content: json_encode(['error' => 'Not authenticated.'], JSON_THROW_ON_ERROR),
                statusCode: 401,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        return new SsrResponse(
            content: json_encode([
                'account' => [
                    'uuid' => $account->getUuid(),
                    'email' => $account->getEmail(),
                    'tenant_id' => $account->getTenantId(),
                    'roles' => $account->getRoles(),
                    'default_workspace_uuid' => $this->defaultWorkspaceUuidForTenant((string) $account->getTenantId()),
                ],
            ], JSON_THROW_ON_ERROR),
            statusCode: 200,
            headers: ['Content-Type' => 'application/json'],
        );
    }

    private function findVerifiedAccountByEmail(string $email): ?Account
    {
        $ids = $this->entityTypeManager->getStorage('account')->getQuery()
            ->condition('email', $email)
            ->condition('status', 'active')
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $account = $this->entityTypeManager->getStorage('account')->load(reset($ids));

        return $account instanceof Account ? $account : null;
    }

    private function defaultWorkspaceUuidForTenant(string $tenantId): ?string
    {
        if ($tenantId === '') {
            return null;
        }

        $tenant = $this->findTenantByUuid($tenantId);
        if (! $tenant instanceof Tenant) {
            return null;
        }

        $metadata = $tenant->get('metadata');
        if (! is_array($metadata)) {
            return null;
        }

        $workspaceUuid = $metadata['default_workspace_uuid'] ?? null;

        return is_string($workspaceUuid) && $workspaceUuid !== '' ? $workspaceUuid : null;
    }

    private function findTenantByUuid(string $tenantId): ?Tenant
    {
        $ids = $this->entityTypeManager->getStorage('tenant')->getQuery()
            ->condition('uuid', $tenantId)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $tenant = $this->entityTypeManager->getStorage('tenant')->load(reset($ids));

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function render(string $template, array $context, int $statusCode = 200): SsrResponse
    {
        if ($this->twig === null) {
            return new SsrResponse(
                content: json_encode($context, JSON_THROW_ON_ERROR),
                statusCode: $statusCode,
                headers: ['Content-Type' => 'application/json'],
            );
        }

        return new SsrResponse(
            content: $this->twig->render($template, $context),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function authenticatedAccountFromSession(): ?AuthenticatedAccount
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        $accountUuid = $_SESSION['claudriel_account_uuid'] ?? null;
        if (! is_string($accountUuid) || $accountUuid === '') {
            return null;
        }

        $ids = $this->entityTypeManager->getStorage('account')->getQuery()
            ->condition('uuid', $accountUuid)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $account = $this->entityTypeManager->getStorage('account')->load(reset($ids));
        if (! $account instanceof Account || ! $account->isVerified()) {
            return null;
        }

        return new AuthenticatedAccount($account);
    }

    private function appUrl(string $tenantId, ?string $workspaceUuid): string
    {
        $query = [];
        if ($tenantId !== '') {
            $query['tenant_id'] = $tenantId;
        }
        if ($workspaceUuid !== null && $workspaceUuid !== '') {
            $query['workspace_uuid'] = $workspaceUuid;
        }

        return '/app'.($query === [] ? '' : '?'.http_build_query($query));
    }
}
