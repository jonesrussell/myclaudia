<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\SSR\SsrResponse;

final class PrivacyPolicyController
{
    public function __construct(
        private readonly ?Environment $twig = null,
    ) {}

    public function show(array $params = [], array $query = [], ?AccountInterface $account = null, ?Request $httpRequest = null): SsrResponse
    {
        $context = [
            'last_updated' => '2026-03-20',
            'contact_email' => 'jonesrussell42@gmail.com',
        ];

        if ($this->twig === null) {
            return new SsrResponse(
                content: $this->renderInline($context),
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return new SsrResponse(
            content: $this->twig->render('public/privacy.twig', $context),
            statusCode: 200,
            headers: ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }

    private function renderInline(array $context): string
    {
        return '<html><head><title>Privacy Policy | Claudriel</title></head>'
            .'<body><h1>Privacy Policy</h1><p>Last updated: '.$context['last_updated'].'</p>'
            .'<p>Contact: '.$context['contact_email'].'</p></body></html>';
    }
}
