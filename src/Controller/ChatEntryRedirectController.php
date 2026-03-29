<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Waaseyaa\Access\AccountInterface;
use Waaseyaa\SSR\SsrResponse;

/**
 * Redirects legacy GET /chat to the Nuxt admin SPA with the chat rail opened.
 */
final class ChatEntryRedirectController
{
    public function redirectToAdmin(
        array $params = [],
        array $query = [],
        ?AccountInterface $account = null,
        mixed $httpRequest = null,
    ): SsrResponse {
        return SsrResponse::redirect('/admin/today?chat=open');
    }
}
