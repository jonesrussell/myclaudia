<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Waaseyaa\Entity\EntityTypeManager;

final class GoogleTokenManager implements GoogleTokenManagerInterface
{
    private const EXPIRY_BUFFER_SECONDS = 60;

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly string $clientId = '',
        private readonly string $clientSecret = '',
    ) {}

    public function getValidAccessToken(string $accountId): string
    {
        $integration = $this->findActiveIntegration($accountId);

        if ($integration === null) {
            throw new \RuntimeException('No active Google integration for account '.$accountId);
        }

        $expiresAt = $integration->get('token_expires_at');

        if ($expiresAt !== null && ! $this->isExpired($expiresAt)) {
            return $integration->get('access_token');
        }

        $refreshToken = $integration->get('refresh_token');

        if ($refreshToken === null || $refreshToken === '') {
            $integration->set('status', 'error');
            $this->entityTypeManager->getStorage('integration')->save($integration);

            throw new \RuntimeException('No refresh token available for account '.$accountId);
        }

        return $this->refreshAccessToken($integration, $refreshToken);
    }

    public function hasActiveIntegration(string $accountId): bool
    {
        return $this->findActiveIntegration($accountId) !== null;
    }

    private function findActiveIntegration(string $accountId): ?object
    {
        $ids = $this->entityTypeManager->getStorage('integration')->getQuery()
            ->condition('account_id', $accountId)
            ->condition('provider', 'google')
            ->condition('status', 'active')
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        return $this->entityTypeManager->getStorage('integration')->load(reset($ids));
    }

    private function isExpired(string $expiresAt): bool
    {
        $expiry = new \DateTimeImmutable($expiresAt);
        $now = new \DateTimeImmutable;

        return $expiry->getTimestamp() - $now->getTimestamp() < self::EXPIRY_BUFFER_SECONDS;
    }

    private function refreshAccessToken(object $integration, string $refreshToken): string
    {
        $payload = http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => $payload,
                'timeout' => 30,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents(self::TOKEN_ENDPOINT, false, $context);
        $httpCode = $this->parseHttpStatusCode($http_response_header ?? []); // @phpstan-ignore nullCoalesce.variable

        if ($response === false || $httpCode >= 400) {
            $integration->set('status', 'error');
            $this->entityTypeManager->getStorage('integration')->save($integration);

            throw new \RuntimeException(
                'Google token refresh failed for account '.$integration->get('account_id')
            );
        }

        $data = json_decode($response, true, 512, JSON_THROW_ON_ERROR);

        $integration->set('access_token', $data['access_token']);
        $integration->set(
            'token_expires_at',
            (new \DateTimeImmutable('+'.$data['expires_in'].' seconds'))->format('c'),
        );

        if (isset($data['refresh_token'])) {
            $integration->set('refresh_token', $data['refresh_token']);
        }

        $this->entityTypeManager->getStorage('integration')->save($integration);

        return $data['access_token'];
    }

    /**
     * @param  list<string>  $headers
     */
    private function parseHttpStatusCode(array $headers): int
    {
        $httpCode = 0;

        foreach ($headers as $header) {
            if (preg_match('#^HTTP/\d+\.\d+\s+(\d+)#', $header, $m)) {
                $httpCode = (int) $m[1];
            }
        }

        return $httpCode;
    }
}
