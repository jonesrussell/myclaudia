<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\SSR\SsrResponse;

/**
 * Catch-all controller that renders the 404 template for unmatched paths.
 *
 * Registered as the last app route so it matches before the foundation's
 * render pipeline catch-all, avoiding PathAliasResolver resolution errors.
 *
 * @see https://github.com/jonesrussell/claudriel/issues/21
 */
final class NotFoundController
{
    public function __construct(
        private readonly mixed $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $path = '/'.ltrim((string) ($params['path'] ?? ''), '/');

        if ($this->twig instanceof Environment) {
            try {
                $html = $this->twig->render('404.html.twig', ['path' => $path]);

                return new SsrResponse(
                    content: $html,
                    statusCode: 404,
                );
            } catch (\Throwable) {
                // Fall through to plain-text response if template rendering fails.
            }
        }

        return new SsrResponse(
            content: sprintf('<!doctype html><html><body><h1>Not Found</h1><p>%s</p></body></html>', htmlspecialchars($path, ENT_QUOTES, 'UTF-8')),
            statusCode: 404,
        );
    }
}
