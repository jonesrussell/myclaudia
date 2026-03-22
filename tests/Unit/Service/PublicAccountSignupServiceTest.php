<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Service;

use Claudriel\Entity\Account;
use Claudriel\Entity\AccountVerificationToken;
use Claudriel\Service\Mail\MailTransportInterface;
use Claudriel\Service\PublicAccountSignupService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Waaseyaa\Database\DBALDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class PublicAccountSignupServiceTest extends TestCase
{
    public function test_verify_sends_admin_notification_when_admin_email_configured(): void
    {
        $spy = new SpyMailTransport;
        $entityTypeManager = $this->buildEntityTypeManager();

        $service = new PublicAccountSignupService(
            entityTypeManager: $entityTypeManager,
            mailTransport: $spy,
            appUrl: 'https://claudriel.ai',
            adminEmail: 'admin@claudriel.ai',
        );

        $result = $service->signup([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret123',
        ]);

        // Spy captured the verification email
        self::assertCount(1, $spy->sent);
        self::assertSame('jane@example.com', $spy->sent[0]['to_email']);

        // Now verify the account
        $service->verify($result['verification_token']);

        // Spy should now have 2 messages: verification + admin notification
        self::assertCount(2, $spy->sent);

        $adminMsg = $spy->sent[1];
        self::assertSame('admin@claudriel.ai', $adminMsg['to_email']);
        self::assertStringContainsString('Jane Doe', $adminMsg['subject']);
        self::assertStringContainsString('jane@example.com', $adminMsg['text']);
    }

    public function test_verify_skips_admin_notification_when_no_admin_email(): void
    {
        $spy = new SpyMailTransport;
        $entityTypeManager = $this->buildEntityTypeManager();

        $service = new PublicAccountSignupService(
            entityTypeManager: $entityTypeManager,
            mailTransport: $spy,
            appUrl: 'https://claudriel.ai',
        );

        $result = $service->signup([
            'name' => 'Bob Smith',
            'email' => 'bob@example.com',
            'password' => 'secret123',
        ]);

        self::assertCount(1, $spy->sent);

        $service->verify($result['verification_token']);

        // Only the verification email, no admin notification
        self::assertCount(1, $spy->sent);
    }

    public function test_verify_does_not_fail_when_admin_notification_throws(): void
    {
        $failingTransport = new class implements MailTransportInterface
        {
            public int $callCount = 0;

            public function send(array $message): array
            {
                $this->callCount++;

                // Fail on the second call (admin notification), succeed on the first (verification)
                if ($this->callCount >= 2) {
                    throw new \RuntimeException('Mail delivery failed');
                }

                return ['transport' => 'test', 'status' => 'sent'];
            }
        };

        $entityTypeManager = $this->buildEntityTypeManager();

        $service = new PublicAccountSignupService(
            entityTypeManager: $entityTypeManager,
            mailTransport: $failingTransport,
            appUrl: 'https://claudriel.ai',
            adminEmail: 'admin@claudriel.ai',
        );

        $result = $service->signup([
            'name' => 'Alice',
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]);

        // Verification should succeed even when admin notification fails
        $verifyResult = $service->verify($result['verification_token']);
        self::assertSame('active', $verifyResult['account']->get('status'));
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = DBALDatabase::createSqlite(':memory:');
        $dispatcher = new EventDispatcher;
        $entityTypeManager = new EntityTypeManager($dispatcher, function ($definition) use ($db, $dispatcher): SqlEntityStorage {
            (new SqlSchemaHandler($definition, $db))->ensureTable();

            return new SqlEntityStorage($definition, $db, $dispatcher);
        });

        $entityTypeManager->registerEntityType(new EntityType(
            id: 'account',
            label: 'Account',
            class: Account::class,
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name'],
        ));
        $entityTypeManager->registerEntityType(new EntityType(
            id: 'account_verification_token',
            label: 'Account Verification Token',
            class: AccountVerificationToken::class,
            keys: ['id' => 'avtid', 'uuid' => 'uuid'],
        ));

        return $entityTypeManager;
    }
}

/**
 * @internal
 */
final class SpyMailTransport implements MailTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    public function send(array $message): array
    {
        $this->sent[] = $message;

        return ['transport' => 'spy', 'status' => 'sent'];
    }
}
