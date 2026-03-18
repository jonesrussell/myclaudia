<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Service\Mail\MailTransportInterface;
use Claudriel\Service\PasswordResetService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;
use Waaseyaa\User\Middleware\CsrfMiddleware;

final class PublicPasswordResetController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
        private readonly ?MailTransportInterface $mailTransport = null,
        private readonly ?string $appUrl = null,
        private readonly ?string $storageDir = null,
    ) {}

    public function requestForm(array $params = [], array $query = []): SsrResponse
    {
        return $this->render('public/forgot-password.twig', [
            'csrf_token' => CsrfMiddleware::token(),
            'email' => (string) ($query['email'] ?? ''),
            'error' => null,
        ]);
    }

    public function requestReset(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): RedirectResponse|SsrResponse
    {
        $request = $httpRequest ?? Request::create('/forgot-password', 'POST');
        $email = strtolower(trim((string) $request->request->get('email', '')));

        if ($email === '') {
            return $this->render('public/forgot-password.twig', [
                'csrf_token' => CsrfMiddleware::token(),
                'email' => $email,
                'error' => 'Email is required.',
            ], 422);
        }

        $this->service()->requestReset($email);

        return new RedirectResponse('/forgot-password/check-email?email='.rawurlencode($email), 302);
    }

    public function checkEmail(array $params = [], array $query = []): SsrResponse
    {
        return $this->render('public/check-email.twig', [
            'email' => (string) ($query['email'] ?? ''),
        ]);
    }

    public function resetForm(array $params = []): RedirectResponse|SsrResponse
    {
        $token = (string) ($params['token'] ?? '');
        if (! $this->service()->tokenIsValid($token)) {
            return new RedirectResponse('/reset-password/complete?status=invalid', 302);
        }

        return $this->render('public/reset-password.twig', [
            'csrf_token' => CsrfMiddleware::token(),
            'token' => $token,
            'error' => null,
        ]);
    }

    public function resetPassword(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): RedirectResponse|SsrResponse
    {
        $request = $httpRequest ?? Request::create('/reset-password', 'POST');
        $token = (string) ($params['token'] ?? $request->request->get('token', ''));
        $password = (string) $request->request->get('password', '');

        if ($password === '') {
            return $this->render('public/reset-password.twig', [
                'csrf_token' => CsrfMiddleware::token(),
                'token' => $token,
                'error' => 'Password is required.',
            ], 422);
        }

        try {
            $this->service()->resetPassword($token, $password);
        } catch (\RuntimeException $exception) {
            return new RedirectResponse('/reset-password/complete?status=invalid', 302);
        }

        return new RedirectResponse('/reset-password/complete?status=complete', 302);
    }

    public function resetComplete(array $params = [], array $query = []): SsrResponse
    {
        return $this->render('public/reset-password-complete.twig', [
            'status' => (string) ($query['status'] ?? 'complete'),
        ]);
    }

    private function service(): PasswordResetService
    {
        return new PasswordResetService(
            entityTypeManager: $this->entityTypeManager,
            mailTransport: $this->mailTransport,
            appUrl: $this->appUrl,
            storageDir: $this->storageDir,
        );
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
