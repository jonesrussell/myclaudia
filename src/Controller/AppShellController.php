<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Twig\Environment;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class AppShellController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], mixed $account = null, mixed $httpRequest = null): RedirectResponse|SsrResponse
    {
        if (! $account instanceof AuthenticatedAccount) {
            return new RedirectResponse('/login', 302);
        }

        return (new DashboardController($this->entityTypeManager, $this->twig))
            ->show($params, $query, $account, $httpRequest);
    }
}
