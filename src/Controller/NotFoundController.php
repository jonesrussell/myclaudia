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

        $escaped = htmlspecialchars($path, ENT_QUOTES, 'UTF-8');

        return new SsrResponse(
            content: <<<HTML
                <!doctype html>
                <html lang="en">
                <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <title>404 Not Found — Claudriel</title>
                <link rel="preconnect" href="https://fonts.googleapis.com">
                <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
                <link href="https://fonts.googleapis.com/css2?family=Bricolage+Grotesque:opsz,wght@12..96,700&family=DM+Sans:opsz,wght@9..40,400&display=swap" rel="stylesheet">
                <style>
                *{box-sizing:border-box;margin:0;padding:0}
                body{font-family:'DM Sans',system-ui,sans-serif;background:#0a0c10;color:#e8e9ed;line-height:1.6}
                nav{background:#131620;padding:0 1.25rem;border-bottom:1px solid rgba(255,255,255,0.06)}
                .nav-inner{max-width:720px;margin:0 auto;display:flex;align-items:center;height:3.25rem}
                .brand{color:#e8e9ed;font-family:'Bricolage Grotesque',system-ui,sans-serif;font-weight:700;font-size:1.05rem;text-decoration:none;letter-spacing:-0.02em}
                main{max-width:720px;margin:2rem auto;padding:0 1.25rem}
                h1{font-family:'Bricolage Grotesque',system-ui,sans-serif;font-size:1.75rem;font-weight:700;letter-spacing:-0.02em;margin-bottom:0.75rem}
                p{color:#9b9cb5;font-size:0.9rem}
                code{background:#131620;padding:0.15rem 0.4rem;border-radius:4px;font-size:0.85em}
                a.back{color:#2dd4bf;text-decoration:none;font-size:0.85rem;display:inline-block;margin-top:1.5rem}
                a.back:hover{text-decoration:underline}
                </style>
                </head>
                <body>
                <nav><div class="nav-inner"><a href="/" class="brand">Claudriel</a></div></nav>
                <main>
                <h1>404 Not Found</h1>
                <p>Path: <code>{$escaped}</code></p>
                <a href="/" class="back">← Back to home</a>
                </main>
                </body>
                </html>
                HTML,
            statusCode: 404,
        );
    }
}
