<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\SSR\SsrResponse;

final class GoogleSettingsController
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?Environment $twig = null,
    ) {}

    public function status(array $params, array $query, AccountInterface $account, Request $httpRequest): SsrResponse
    {
        $accountUuid = method_exists($account, 'getUuid') ? $account->getUuid() : '';

        if ($accountUuid === '') {
            return $this->json(['connected' => false, 'email' => null, 'connected_at' => null]);
        }

        $integration = $this->findGoogleIntegration($accountUuid);

        if ($integration === null) {
            return $this->json(['connected' => false, 'email' => null, 'connected_at' => null]);
        }

        return $this->json([
            'connected' => true,
            'email' => $integration->get('google_email') ?? $integration->get('name') ?? null,
            'connected_at' => $integration->get('created_at'),
        ]);
    }

    public function disconnect(array $params, array $query, AccountInterface $account, Request $httpRequest): SsrResponse
    {
        $accountUuid = method_exists($account, 'getUuid') ? $account->getUuid() : '';

        if ($accountUuid === '') {
            return $this->json(['error' => 'Not authenticated'], 401);
        }

        $integration = $this->findGoogleIntegration($accountUuid);

        if ($integration === null) {
            return $this->json(['error' => 'No Google connection found'], 404);
        }

        // Revoke the token at Google
        $accessToken = $integration->get('access_token');
        if (is_string($accessToken) && $accessToken !== '') {
            $this->revokeGoogleToken($accessToken);
        }

        // Mark integration as disconnected and clear tokens
        $integration->set('status', 'disconnected');
        $integration->set('access_token', null);
        $integration->set('refresh_token', null);
        $integration->set('token_expires_at', null);
        $this->entityTypeManager->getStorage('integration')->save($integration);

        return $this->json(['disconnected' => true]);
    }

    public function show(array $params, array $query, AccountInterface $account, Request $httpRequest): SsrResponse
    {
        $accountUuid = method_exists($account, 'getUuid') ? $account->getUuid() : '';
        $integration = $accountUuid !== '' ? $this->findGoogleIntegration($accountUuid) : null;

        $connected = $integration !== null;
        $email = $connected ? ($integration->get('google_email') ?? $integration->get('name') ?? '') : '';
        $connectedAt = $connected ? ($integration->get('created_at') ?? '') : '';

        if ($this->twig !== null) {
            $html = $this->twig->render('settings.html.twig', [
                'google_connected' => $connected,
                'google_email' => $email,
                'google_connected_at' => $connectedAt,
            ]);

            return new SsrResponse(
                content: $html,
                statusCode: 200,
                headers: ['Content-Type' => 'text/html; charset=UTF-8'],
            );
        }

        return $this->json([
            'google' => [
                'connected' => $connected,
                'email' => $email,
                'connected_at' => $connectedAt,
            ],
        ]);
    }

    private function findGoogleIntegration(string $accountUuid): ?object
    {
        $ids = $this->entityTypeManager->getStorage('integration')->getQuery()
            ->condition('account_id', $accountUuid)
            ->condition('provider', 'google')
            ->condition('status', 'active')
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        return $this->entityTypeManager->getStorage('integration')->load(reset($ids));
    }

    private function revokeGoogleToken(string $token): void
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query(['token' => $token]),
                'ignore_errors' => true,
                'timeout' => 5,
            ],
        ]);

        file_get_contents('https://oauth2.googleapis.com/revoke', false, $context);
    }

    private function json(mixed $data, int $statusCode = 200): SsrResponse
    {
        return new SsrResponse(
            content: json_encode($data, JSON_THROW_ON_ERROR),
            statusCode: $statusCode,
            headers: ['Content-Type' => 'application/json'],
        );
    }
}
