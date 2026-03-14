<?php

declare(strict_types=1);

namespace Claudriel\Tests\Unit\Controller;

use Claudriel\Controller\PublicAccountController;
use Claudriel\Entity\Account;
use Claudriel\Entity\AccountVerificationToken;
use Claudriel\Service\Mail\MailTransportInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Waaseyaa\Database\PdoDatabase;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\EntityStorage\SqlEntityStorage;
use Waaseyaa\EntityStorage\SqlSchemaHandler;

final class PublicAccountControllerTest extends TestCase
{
    public function test_signup_form_renders_public_signup_surface(): void
    {
        $controller = $this->controller();

        $response = $controller->signupForm();

        self::assertSame(200, $response->statusCode);
        self::assertStringContainsString('Create Your Claudriel Account', $response->content);
        self::assertStringContainsString('Create account', $response->content);
    }

    public function test_signup_creates_pending_account_and_sends_verification_delivery(): void
    {
        $transport = new InMemoryMailTransport;
        $entityTypeManager = $this->buildEntityTypeManager();
        $controller = $this->controller($entityTypeManager, $transport);

        $response = $controller->signup(
            httpRequest: Request::create('/signup', 'POST', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'correct horse battery staple',
            ]),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame('/signup/check-email?email=test%40example.com', $response->getTargetUrl());

        $accounts = $entityTypeManager->getStorage('account')->loadMultiple(
            $entityTypeManager->getStorage('account')->getQuery()->execute(),
        );
        $account = array_values($accounts)[0] ?? null;

        self::assertInstanceOf(Account::class, $account);
        self::assertSame('pending_verification', $account->get('status'));
        self::assertNull($account->get('email_verified_at'));
        self::assertTrue(password_verify('correct horse battery staple', (string) $account->get('password_hash')));

        $tokens = $entityTypeManager->getStorage('account_verification_token')->loadMultiple(
            $entityTypeManager->getStorage('account_verification_token')->getQuery()->execute(),
        );
        $token = array_values($tokens)[0] ?? null;

        self::assertInstanceOf(AccountVerificationToken::class, $token);
        self::assertSame($account->get('uuid'), $token->get('account_uuid'));
        self::assertCount(1, $transport->messages);
        self::assertSame('Verify your Claudriel account', $transport->messages[0]['subject']);
        self::assertStringContainsString('/verify-email/', (string) $transport->messages[0]['verification_url']);
    }

    public function test_verification_link_is_single_use_and_redirects_to_onboarding(): void
    {
        $transport = new InMemoryMailTransport;
        $entityTypeManager = $this->buildEntityTypeManager();
        $controller = $this->controller($entityTypeManager, $transport);

        $controller->signup(
            httpRequest: Request::create('/signup', 'POST', [
                'name' => 'Test User',
                'email' => 'test@example.com',
                'password' => 'correct horse battery staple',
            ]),
        );

        $verificationUrl = (string) $transport->messages[0]['verification_url'];
        $token = basename($verificationUrl);

        $first = $controller->verifyEmail(['token' => $token]);
        self::assertInstanceOf(RedirectResponse::class, $first);
        self::assertSame('/onboarding/bootstrap?verified=1', $first->getTargetUrl());

        $accounts = $entityTypeManager->getStorage('account')->loadMultiple(
            $entityTypeManager->getStorage('account')->getQuery()->execute(),
        );
        $account = array_values($accounts)[0] ?? null;
        self::assertInstanceOf(Account::class, $account);
        self::assertSame('active', $account->get('status'));
        self::assertNotNull($account->get('email_verified_at'));

        $second = $controller->verifyEmail(['token' => $token]);
        self::assertSame('/signup/verification-result?status=invalid', $second->getTargetUrl());
    }

    private function controller(?EntityTypeManager $entityTypeManager = null, ?MailTransportInterface $transport = null): PublicAccountController
    {
        return new PublicAccountController(
            $entityTypeManager ?? $this->buildEntityTypeManager(),
            new Environment(new FilesystemLoader(dirname(__DIR__, 3).'/templates')),
            $transport,
            'https://claudriel.test',
            sys_get_temp_dir().'/claudriel-signup-tests',
        );
    }

    private function buildEntityTypeManager(): EntityTypeManager
    {
        $db = PdoDatabase::createSqlite(':memory:');
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

final class InMemoryMailTransport implements MailTransportInterface
{
    /** @var list<array<string, mixed>> */
    public array $messages = [];

    public function send(array $message): array
    {
        $this->messages[] = $message;

        return [
            'transport' => 'memory',
            'status' => 'queued',
        ];
    }
}
