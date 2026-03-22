<?php

declare(strict_types=1);

namespace Claudriel\Service;

use Claudriel\Entity\Account;
use Claudriel\Entity\AccountVerificationToken;
use Claudriel\Service\Mail\LoggedMailTransport;
use Claudriel\Service\Mail\MailTransportInterface;
use Claudriel\Service\Mail\SendGridMailTransport;
use Waaseyaa\Entity\EntityTypeManager;

final class PublicAccountSignupService
{
    public function __construct(
        private readonly EntityTypeManager $entityTypeManager,
        private readonly ?MailTransportInterface $mailTransport = null,
        private readonly ?string $appUrl = null,
        private readonly ?string $storageDir = null,
        private readonly ?string $adminEmail = null,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $input
     * @return array{account: Account, token: AccountVerificationToken, verification_token: string, delivery: array<string, mixed>}
     */
    public function signup(array $input): array
    {
        $email = strtolower(trim($input['email']));
        $name = trim($input['name']);

        if ($this->findAccountByEmail($email) instanceof Account) {
            throw new \RuntimeException('An account with that email already exists.');
        }

        $account = new Account([
            'name' => $name,
            'email' => $email,
            'password_hash' => password_hash($input['password'], PASSWORD_DEFAULT),
            'status' => 'pending_verification',
            'roles' => [],
            'permissions' => [],
        ]);
        $this->entityTypeManager->getStorage('account')->save($account);

        $token = bin2hex(random_bytes(32));
        $tokenEntity = new AccountVerificationToken([
            'account_uuid' => $account->get('uuid'),
            'token_hash' => hash('sha256', $token),
            'expires_at' => (new \DateTimeImmutable('+24 hours'))->format(\DateTimeInterface::ATOM),
            'redirect_path' => '/onboarding/bootstrap',
        ]);
        $this->entityTypeManager->getStorage('account_verification_token')->save($tokenEntity);

        $verifyUrl = rtrim($this->appUrl(), '/').'/verify-email/'.$token;
        $delivery = $this->mailTransport()->send([
            'template' => 'signup_verification',
            'to_email' => $email,
            'to_name' => $name,
            'subject' => 'Verify your Claudriel account',
            'text' => "Verify your Claudriel account: {$verifyUrl}",
            'verification_url' => $verifyUrl,
            'account_uuid' => $account->get('uuid'),
        ]);

        return [
            'account' => $account,
            'token' => $tokenEntity,
            'verification_token' => $token,
            'delivery' => $delivery,
        ];
    }

    /**
     * @return array{account: Account, redirect_path: string}
     */
    public function verify(string $token): array
    {
        $tokenEntity = $this->findActiveToken($token);
        if (! $tokenEntity instanceof AccountVerificationToken) {
            throw new \RuntimeException('Verification link is invalid or expired.');
        }

        $account = $this->findAccountByUuid((string) $tokenEntity->get('account_uuid'));
        if (! $account instanceof Account) {
            throw new \RuntimeException('Account for verification link was not found.');
        }

        $now = new \DateTimeImmutable;
        $account->set('status', 'active');
        $account->set('email_verified_at', $now->format(\DateTimeInterface::ATOM));
        $this->entityTypeManager->getStorage('account')->save($account);

        $tokenEntity->set('used_at', $now->format(\DateTimeInterface::ATOM));
        $this->entityTypeManager->getStorage('account_verification_token')->save($tokenEntity);

        $this->notifyAdminOfVerifiedRegistration($account, $now);

        return [
            'account' => $account,
            'redirect_path' => (string) ($tokenEntity->get('redirect_path') ?? '/'),
        ];
    }

    public function findAccountByEmail(string $email): ?Account
    {
        $ids = $this->entityTypeManager->getStorage('account')->getQuery()
            ->condition('email', strtolower(trim($email)))
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $account = $this->entityTypeManager->getStorage('account')->load(reset($ids));

        return $account instanceof Account ? $account : null;
    }

    private function findAccountByUuid(string $uuid): ?Account
    {
        $ids = $this->entityTypeManager->getStorage('account')->getQuery()
            ->condition('uuid', $uuid)
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $account = $this->entityTypeManager->getStorage('account')->load(reset($ids));

        return $account instanceof Account ? $account : null;
    }

    private function findActiveToken(string $rawToken): ?AccountVerificationToken
    {
        $ids = $this->entityTypeManager->getStorage('account_verification_token')->getQuery()
            ->condition('token_hash', hash('sha256', $rawToken))
            ->range(0, 1)
            ->execute();

        if ($ids === []) {
            return null;
        }

        $token = $this->entityTypeManager->getStorage('account_verification_token')->load(reset($ids));
        if (! $token instanceof AccountVerificationToken) {
            return null;
        }

        if ($token->get('used_at') !== null) {
            return null;
        }

        $expiresAt = (string) ($token->get('expires_at') ?? '');
        if ($expiresAt !== '' && new \DateTimeImmutable($expiresAt) < new \DateTimeImmutable) {
            return null;
        }

        return $token;
    }

    private function appUrl(): string
    {
        if ($this->appUrl !== null && trim($this->appUrl) !== '') {
            return trim($this->appUrl);
        }

        $appUrl = $_ENV['CLAUDRIEL_APP_URL'] ?? getenv('CLAUDRIEL_APP_URL') ?: 'http://localhost:9889';

        return is_string($appUrl) ? $appUrl : 'http://localhost:9889';
    }

    private function notifyAdminOfVerifiedRegistration(Account $account, \DateTimeImmutable $verifiedAt): void
    {
        $adminEmail = $this->resolveAdminEmail();
        if ($adminEmail === null) {
            return;
        }

        $name = (string) $account->get('name');
        $email = (string) $account->get('email');
        $timestamp = $verifiedAt->format('Y-m-d H:i:s T');

        try {
            $this->mailTransport()->send([
                'to_email' => $adminEmail,
                'to_name' => 'Admin',
                'subject' => "New user registration: {$name}",
                'text' => "A new user has verified their Claudriel account.\n\nName: {$name}\nEmail: {$email}\nVerified at: {$timestamp}",
            ]);
        } catch (\RuntimeException) {
            // Admin notification is best-effort; do not fail the verification flow.
        }
    }

    private function resolveAdminEmail(): ?string
    {
        if ($this->adminEmail !== null && trim($this->adminEmail) !== '') {
            return trim($this->adminEmail);
        }

        $envEmail = $_ENV['CLAUDRIEL_ADMIN_EMAIL'] ?? getenv('CLAUDRIEL_ADMIN_EMAIL') ?: null;

        return is_string($envEmail) && trim($envEmail) !== '' ? trim($envEmail) : null;
    }

    private function mailTransport(): MailTransportInterface
    {
        if ($this->mailTransport instanceof MailTransportInterface) {
            return $this->mailTransport;
        }

        $storageDir = $this->storageDir
            ?? (getenv('CLAUDRIEL_STORAGE') ?: dirname(__DIR__, 2).'/storage');
        $fallback = new LoggedMailTransport($storageDir.'/mail-delivery.log');

        return new SendGridMailTransport(
            apiKey: (string) ($_ENV['SENDGRID_API_KEY'] ?? getenv('SENDGRID_API_KEY') ?: ''),
            fromEmail: (string) ($_ENV['CLAUDRIEL_MAIL_FROM_EMAIL'] ?? getenv('CLAUDRIEL_MAIL_FROM_EMAIL') ?: 'hello@claudriel.ai'),
            fromName: (string) ($_ENV['CLAUDRIEL_MAIL_FROM_NAME'] ?? getenv('CLAUDRIEL_MAIL_FROM_NAME') ?: 'Claudriel'),
            fallback: $fallback,
        );
    }
}
