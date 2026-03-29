<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Account;
use Waaseyaa\Entity\EntityTypeManager;

final class AuthenticatedAccountSessionResolver
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
    ) {}

    public function resolve(): ?AuthenticatedAccount
    {
        if (session_status() !== \PHP_SESSION_ACTIVE) {
            session_start();
        }

        $accountUuid = $_SESSION['claudriel_account_uuid'] ?? null;
        if (is_string($accountUuid) && $accountUuid !== '') {
            return $this->resolveVerifiedByUuid($accountUuid);
        }

        if ($this->shouldUseDevCliAutoSession()) {
            return $this->resolveFirstVerifiedAccount();
        }

        return null;
    }

    private function shouldUseDevCliAutoSession(): bool
    {
        if (PHP_SAPI !== 'cli-server') {
            return false;
        }

        $flag = $_ENV['CLAUDRIEL_DEV_CLI_SESSION'] ?? getenv('CLAUDRIEL_DEV_CLI_SESSION') ?: '';

        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    private function resolveVerifiedByUuid(string $accountUuid): ?AuthenticatedAccount
    {
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

    /**
     * First verified account in storage (order undefined). Only for explicit php -S dev workflows.
     */
    private function resolveFirstVerifiedAccount(): ?AuthenticatedAccount
    {
        $ids = $this->entityTypeManager->getStorage('account')->getQuery()
            ->range(0, 100)
            ->execute();

        foreach ($ids as $id) {
            $account = $this->entityTypeManager->getStorage('account')->load($id);
            if ($account instanceof Account && $account->isVerified()) {
                return new AuthenticatedAccount($account);
            }
        }

        return null;
    }
}
