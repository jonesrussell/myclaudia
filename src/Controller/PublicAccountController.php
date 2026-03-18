<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Account;
use Claudriel\Entity\Workspace;
use Claudriel\Service\Mail\MailTransportInterface;
use Claudriel\Service\PublicAccountSignupService;
use Claudriel\Service\TenantBootstrapService;
use Claudriel\Service\WorkspaceBootstrapService;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;
use Waaseyaa\User\Middleware\CsrfMiddleware;

final class PublicAccountController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
        private readonly ?MailTransportInterface $mailTransport = null,
        private readonly ?string $appUrl = null,
        private readonly ?string $storageDir = null,
    ) {}

    public function signupForm(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): RedirectResponse|SsrResponse
    {
        $resolvedAccount = $account instanceof AuthenticatedAccount
            ? $account
            : (new AuthenticatedAccountSessionResolver($this->entityTypeManager))->resolve();

        if ($resolvedAccount instanceof AuthenticatedAccount) {
            return new RedirectResponse($this->appUrl((string) $resolvedAccount->getTenantId()), 302);
        }

        return $this->render('public/signup.twig', [
            'csrf_token' => CsrfMiddleware::token(),
            'email' => (string) ($query['email'] ?? ''),
            'name' => (string) ($query['name'] ?? ''),
            'error' => null,
        ]);
    }

    public function signup(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): RedirectResponse|SsrResponse
    {
        $request = $httpRequest ?? Request::create('/signup', 'POST');
        $name = trim((string) $request->request->get('name', ''));
        $email = strtolower(trim((string) $request->request->get('email', '')));
        $password = (string) $request->request->get('password', '');

        if ($name === '' || $email === '' || $password === '') {
            return $this->render('public/signup.twig', [
                'csrf_token' => CsrfMiddleware::token(),
                'email' => $email,
                'name' => $name,
                'error' => 'Name, email, and password are required.',
            ], 422);
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->render('public/signup.twig', [
                'csrf_token' => CsrfMiddleware::token(),
                'email' => $email,
                'name' => $name,
                'error' => 'Enter a valid email address.',
            ], 422);
        }

        try {
            $this->service()->signup([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);
        } catch (\RuntimeException $exception) {
            return $this->render('public/signup.twig', [
                'csrf_token' => CsrfMiddleware::token(),
                'email' => $email,
                'name' => $name,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return new RedirectResponse('/signup/check-email?email='.rawurlencode($email), 302);
    }

    public function checkEmail(array $params = [], array $query = []): SsrResponse
    {
        return $this->render('public/check-email.twig', [
            'email' => (string) ($query['email'] ?? ''),
        ]);
    }

    public function verifyEmail(array $params = []): RedirectResponse
    {
        $token = (string) ($params['token'] ?? '');

        try {
            $result = $this->service()->verify($token);
        } catch (\RuntimeException $exception) {
            return new RedirectResponse('/signup/verification-result?status=invalid', 302);
        }

        $account = $result['account'];
        $tenant = $this->tenantBootstrapService()->bootstrapForAccount($account);
        $workspace = $this->workspaceBootstrapService()->bootstrapDefaultWorkspace($tenant);
        $redirect = $result['redirect_path'];

        return new RedirectResponse($redirect.'?verified=1&account='.rawurlencode((string) $account->get('uuid')).'&tenant='.rawurlencode((string) $tenant->get('uuid')).'&workspace='.rawurlencode((string) $workspace->get('uuid')), 302);
    }

    public function verificationResult(array $params = [], array $query = []): SsrResponse
    {
        return $this->render('public/verification-result.twig', [
            'status' => (string) ($query['status'] ?? 'verified'),
        ]);
    }

    public function onboardingBootstrap(array $params = [], array $query = []): RedirectResponse|SsrResponse
    {
        $account = $this->findAccountByUuid((string) ($query['account'] ?? ''));
        $tenant = $this->tenantBootstrapService()->findByUuid((string) ($query['tenant'] ?? ''));
        $workspace = $this->workspaceBootstrapService()->findWorkspaceByUuidForTenant(
            (string) ($query['workspace'] ?? ''),
            (string) ($query['tenant'] ?? ''),
        );
        if (((string) ($query['verified'] ?? '0')) === '1'
            && $account instanceof Account
            && $tenant !== null
            && $workspace instanceof Workspace) {
            $redirectQuery = [
                'verified' => '1',
                'tenant_id' => (string) $tenant->get('uuid'),
                'workspace_uuid' => (string) $workspace->get('uuid'),
            ];

            return new RedirectResponse('/app?'.http_build_query($redirectQuery), 302);
        }

        return $this->render('public/verification-result.twig', [
            'status' => ((string) ($query['verified'] ?? '0')) === '1' ? 'verified' : 'pending',
            'account' => $account,
            'tenant' => $tenant,
            'workspace' => $workspace,
        ]);
    }

    private function service(): PublicAccountSignupService
    {
        return new PublicAccountSignupService(
            entityTypeManager: $this->entityTypeManager,
            mailTransport: $this->mailTransport,
            appUrl: $this->appUrl,
            storageDir: $this->storageDir,
        );
    }

    private function tenantBootstrapService(): TenantBootstrapService
    {
        return new TenantBootstrapService($this->entityTypeManager);
    }

    private function workspaceBootstrapService(): WorkspaceBootstrapService
    {
        return new WorkspaceBootstrapService($this->entityTypeManager);
    }

    private function appUrl(string $tenantId): string
    {
        if ($tenantId === '') {
            return '/app';
        }

        return '/app?'.http_build_query(['tenant_id' => $tenantId]);
    }

    private function findAccountByUuid(string $uuid): ?Account
    {
        $ids = $this->entityTypeManager->getStorage('account')->getQuery()
            ->condition('uuid', $uuid)
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
