<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\SSR\SsrResponse;

final class TermsOfServiceController
{
    public function __construct(
        private readonly ?Environment $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $context = [
            'last_updated' => '2026-03-20',
            'contact_email' => 'support@claudriel.ai',
        ];

        if ($this->twig === null) {
            return new SsrResponse(
                content: '<html><head><title>Terms of Service | Claudriel</title></head>'
                    . '<body><h1>Terms of Service</h1><p>Last updated: ' . $context['last_updated'] . '</p></body></html>',
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return new SsrResponse(
            content: $this->twig->render('public/terms.twig', $context),
            statusCode: 200,
            headers: ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
