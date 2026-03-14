<?php

declare(strict_types=1);

namespace Claudriel\Routing;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Account;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Attribute\AsMiddleware;
use Waaseyaa\Foundation\Middleware\HttpHandlerInterface;
use Waaseyaa\Foundation\Middleware\HttpMiddlewareInterface;

#[AsMiddleware(pipeline: 'http', priority: 31)]
final class AccountSessionMiddleware implements HttpMiddlewareInterface
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function process(Request $request, HttpHandlerInterface $next): Response
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        $existing = $request->attributes->get('_account');
        if ($existing instanceof AccountInterface && $existing->isAuthenticated()) {
            return $next->handle($request);
        }

        $accountUuid = $_SESSION['claudriel_account_uuid'] ?? null;
        if (! is_string($accountUuid) || $accountUuid === '') {
            return $next->handle($request);
        }

        $ids = $this->entityTypeManager->getStorage('account')->getQuery()
            ->condition('uuid', $accountUuid)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            unset($_SESSION['claudriel_account_uuid']);

            return $next->handle($request);
        }

        $account = $this->entityTypeManager->getStorage('account')->load(reset($ids));
        if (! $account instanceof Account || ! $account->isVerified()) {
            unset($_SESSION['claudriel_account_uuid']);

            return $next->handle($request);
        }

        $request->attributes->set('_account', new AuthenticatedAccount($account));

        return $next->handle($request);
    }
}
