<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Domain\Chat;

use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(InternalApiTokenGenerator::class)]
final class InternalApiTokenGeneratorTest extends TestCase
{
    private const SECRET = 'test-secret-that-is-at-least-32-bytes-long!!';

    #[Test]
    public function generate_returns_token_with_three_parts(): void
    {
        $generator = new InternalApiTokenGenerator(self::SECRET);
        $token = $generator->generate('account-123');

        $parts = explode(':', $token);
        $this->assertCount(3, $parts, 'Token must have format account_id:timestamp:signature');
    }

    #[Test]
    public function validate_accepts_valid_token(): void
    {
        $generator = new InternalApiTokenGenerator(self::SECRET);
        $token = $generator->generate('account-123');

        $result = $generator->validate($token);
        $this->assertSame('account-123', $result);
    }

    #[Test]
    public function validate_rejects_expired_token(): void
    {
        $generator = new InternalApiTokenGenerator(self::SECRET, ttlSeconds: 1);

        // Manually create an expired token (timestamp 600 seconds ago)
        $expiredTimestamp = time() - 600;
        $payload = "account-123:{$expiredTimestamp}";
        $signature = hash_hmac('sha256', $payload, self::SECRET);
        $expiredToken = "{$payload}:{$signature}";

        $this->assertNull($generator->validate($expiredToken));
    }

    #[Test]
    public function validate_rejects_tampered_token(): void
    {
        $generator = new InternalApiTokenGenerator(self::SECRET);
        $token = $generator->generate('account-123');

        // Tamper with the account_id
        $tampered = str_replace('account-123', 'account-456', $token);
        $this->assertNull($generator->validate($tampered));
    }

    #[Test]
    public function validate_rejects_wrong_secret(): void
    {
        $generator1 = new InternalApiTokenGenerator(self::SECRET);
        $generator2 = new InternalApiTokenGenerator('different-secret-also-32-bytes-long!!');

        $token = $generator1->generate('account-123');
        $this->assertNull($generator2->validate($token));
    }

    #[Test]
    public function validate_rejects_malformed_token(): void
    {
        $generator = new InternalApiTokenGenerator(self::SECRET);

        $this->assertNull($generator->validate(''));
        $this->assertNull($generator->validate('just-one-part'));
        $this->assertNull($generator->validate('two:parts'));
    }
}
