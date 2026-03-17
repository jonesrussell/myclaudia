<?php

declare(strict_types=1);

namespace Claudriel\Domain\Chat;

final class InternalApiTokenGenerator
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttlSeconds = 300,
    ) {}

    /**
     * Generate a short-lived HMAC token for internal API auth.
     *
     * Format: {account_id}:{timestamp}:{signature}
     */
    public function generate(string $accountId): string
    {
        $timestamp = time();
        $payload = "{$accountId}:{$timestamp}";
        $signature = hash_hmac('sha256', $payload, $this->secret);

        return "{$payload}:{$signature}";
    }

    /**
     * Validate an HMAC token and return the account_id, or null if invalid/expired.
     */
    public function validate(string $token): ?string
    {
        $parts = explode(':', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$accountId, $timestampStr, $signature] = $parts;

        $timestamp = (int) $timestampStr;
        if (time() - $timestamp > $this->ttlSeconds) {
            return null;
        }

        $expectedPayload = "{$accountId}:{$timestampStr}";
        $expectedSignature = hash_hmac('sha256', $expectedPayload, $this->secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return null;
        }

        return $accountId;
    }
}
