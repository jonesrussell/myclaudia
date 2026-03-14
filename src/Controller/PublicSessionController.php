<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Account;
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

    public function loginForm(array $params = [], array $query = []): SsrResponse
    {
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

        return new RedirectResponse('/?login=1', 302);
    }

    public function logout(array $params = [], array $query = [], mixed $account = null, ?Request $httpRequest = null): RedirectResponse
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        unset($_SESSION['claudriel_account_uuid']);
        session_regenerate_id(true);
        CsrfMiddleware::regenerate();

        return new RedirectResponse('/login?logged_out=1', 302);
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
}
