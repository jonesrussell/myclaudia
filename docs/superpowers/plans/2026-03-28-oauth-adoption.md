# OAuth Provider Adoption Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the `waaseyaa/oauth-provider` package and refactor Claudriel to use it, replacing two provider-specific OAuth controllers and two token managers with unified, provider-agnostic implementations.

**Architecture:** The `waaseyaa/oauth-provider` package handles OAuth protocol (auth URLs, token exchange, refresh, user profiles). Claudriel's `OAuthController` uses the package via `ProviderRegistry` for all OAuth flows. `OAuthTokenManager` uses the package for token refresh. Both Google and GitHub share the same code paths, differentiated only by a flow config map.

**Tech Stack:** PHP 8.4, Waaseyaa framework, `waaseyaa/http-client` (`StreamHttpClient`), PHPUnit/Pest

---

## Phase 1: Build waaseyaa/oauth-provider Package

All Phase 1 work happens in `/home/jones/dev/waaseyaa/`.

### Task 1: Package Scaffold + OAuthToken Value Object

**Files:**
- Create: `packages/oauth-provider/composer.json`
- Create: `packages/oauth-provider/src/OAuthToken.php`
- Create: `packages/oauth-provider/tests/OAuthTokenTest.php`

- [ ] **Step 1: Create package composer.json**

```json
{
    "name": "waaseyaa/oauth-provider",
    "description": "OAuth 2.0 provider abstraction for Waaseyaa applications",
    "type": "library",
    "license": "MIT",
    "require": {
        "php": ">=8.4",
        "waaseyaa/http-client": "dev-main"
    },
    "require-dev": {
        "phpunit/phpunit": "^11.0"
    },
    "autoload": {
        "psr-4": {
            "Waaseyaa\\OAuthProvider\\": "src/"
        }
    },
    "autoload-dev": {
        "psr-4": {
            "Waaseyaa\\OAuthProvider\\Tests\\": "tests/"
        }
    }
}
```

- [ ] **Step 2: Write failing test for OAuthToken**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests;

use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthToken;

final class OAuthTokenTest extends TestCase
{
    public function testCreateWithAllFields(): void
    {
        $expiresAt = new \DateTimeImmutable('+3600 seconds');
        $token = new OAuthToken(
            accessToken: 'ya29.access',
            refreshToken: 'refresh-123',
            expiresAt: $expiresAt,
            scopes: ['email', 'profile'],
            tokenType: 'Bearer',
        );

        self::assertSame('ya29.access', $token->accessToken);
        self::assertSame('refresh-123', $token->refreshToken);
        self::assertSame($expiresAt, $token->expiresAt);
        self::assertSame(['email', 'profile'], $token->scopes);
        self::assertSame('Bearer', $token->tokenType);
    }

    public function testCreateWithNullableFields(): void
    {
        $token = new OAuthToken(
            accessToken: 'gho_abc123',
            refreshToken: null,
            expiresAt: null,
            scopes: ['repo'],
            tokenType: 'bearer',
        );

        self::assertSame('gho_abc123', $token->accessToken);
        self::assertNull($token->refreshToken);
        self::assertNull($token->expiresAt);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/OAuthTokenTest.php`
Expected: FAIL — class not found

- [ ] **Step 4: Implement OAuthToken**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final readonly class OAuthToken
{
    /**
     * @param list<string> $scopes
     */
    public function __construct(
        public string $accessToken,
        public ?string $refreshToken,
        public ?\DateTimeImmutable $expiresAt,
        public array $scopes,
        public string $tokenType = 'Bearer',
    ) {}
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/OAuthTokenTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd /home/jones/dev/waaseyaa && git add packages/oauth-provider/composer.json packages/oauth-provider/src/OAuthToken.php packages/oauth-provider/tests/OAuthTokenTest.php && git commit -m "feat(oauth-provider): scaffold package + OAuthToken value object"
```

### Task 2: OAuthUserProfile Value Object

**Files:**
- Create: `packages/oauth-provider/src/OAuthUserProfile.php`
- Create: `packages/oauth-provider/tests/OAuthUserProfileTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests;

use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthUserProfile;

final class OAuthUserProfileTest extends TestCase
{
    public function testCreateWithAllFields(): void
    {
        $profile = new OAuthUserProfile(
            providerId: '12345',
            email: 'user@example.com',
            name: 'Jane Doe',
            avatarUrl: 'https://example.com/avatar.jpg',
        );

        self::assertSame('12345', $profile->providerId);
        self::assertSame('user@example.com', $profile->email);
        self::assertSame('Jane Doe', $profile->name);
        self::assertSame('https://example.com/avatar.jpg', $profile->avatarUrl);
    }

    public function testCreateWithNullAvatar(): void
    {
        $profile = new OAuthUserProfile(
            providerId: '67890',
            email: 'user@example.com',
            name: 'Jane',
        );

        self::assertNull($profile->avatarUrl);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/OAuthUserProfileTest.php`
Expected: FAIL

- [ ] **Step 3: Implement OAuthUserProfile**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final readonly class OAuthUserProfile
{
    public function __construct(
        public string $providerId,
        public string $email,
        public string $name,
        public ?string $avatarUrl = null,
    ) {}
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/OAuthUserProfileTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
cd /home/jones/dev/waaseyaa && git add packages/oauth-provider/src/OAuthUserProfile.php packages/oauth-provider/tests/OAuthUserProfileTest.php && git commit -m "feat(oauth-provider): add OAuthUserProfile value object"
```

### Task 3: OAuthProviderInterface + UnsupportedOperationException

**Files:**
- Create: `packages/oauth-provider/src/OAuthProviderInterface.php`
- Create: `packages/oauth-provider/src/UnsupportedOperationException.php`

- [ ] **Step 1: Create the interface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

interface OAuthProviderInterface
{
    public function getName(): string;

    /**
     * @param list<string> $scopes
     */
    public function getAuthorizationUrl(array $scopes, string $state): string;

    public function exchangeCode(string $code): OAuthToken;

    /**
     * @throws UnsupportedOperationException if the provider does not support token refresh
     */
    public function refreshToken(string $refreshToken): OAuthToken;

    public function getUserProfile(string $accessToken): OAuthUserProfile;
}
```

- [ ] **Step 2: Create the exception**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final class UnsupportedOperationException extends \RuntimeException
{
    public static function refreshNotSupported(string $provider): self
    {
        return new self("Token refresh is not supported by the '{$provider}' provider");
    }
}
```

- [ ] **Step 3: Commit**

```bash
cd /home/jones/dev/waaseyaa && git add packages/oauth-provider/src/OAuthProviderInterface.php packages/oauth-provider/src/UnsupportedOperationException.php && git commit -m "feat(oauth-provider): add OAuthProviderInterface + UnsupportedOperationException"
```

### Task 4: SessionInterface + OAuthStateManager

**Files:**
- Create: `packages/oauth-provider/src/SessionInterface.php`
- Create: `packages/oauth-provider/src/OAuthStateManager.php`
- Create: `packages/oauth-provider/tests/OAuthStateManagerTest.php`

- [ ] **Step 1: Create SessionInterface**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

interface SessionInterface
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function remove(string $key): void;
}
```

- [ ] **Step 2: Write failing test for OAuthStateManager**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests;

use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthStateManager;
use Waaseyaa\OAuthProvider\SessionInterface;

final class OAuthStateManagerTest extends TestCase
{
    public function testGenerateCreatesHexState(): void
    {
        $session = new InMemorySession();
        $manager = new OAuthStateManager();

        $state = $manager->generate($session);

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $state);
    }

    public function testValidateReturnsTrueForMatchingState(): void
    {
        $session = new InMemorySession();
        $manager = new OAuthStateManager();

        $state = $manager->generate($session);
        $result = $manager->validate($session, $state);

        self::assertTrue($result);
    }

    public function testValidateConsumesState(): void
    {
        $session = new InMemorySession();
        $manager = new OAuthStateManager();

        $state = $manager->generate($session);
        $manager->validate($session, $state);

        self::assertFalse($manager->validate($session, $state));
    }

    public function testValidateReturnsFalseForWrongState(): void
    {
        $session = new InMemorySession();
        $manager = new OAuthStateManager();

        $manager->generate($session);

        self::assertFalse($manager->validate($session, 'wrong-state'));
    }

    public function testValidateReturnsFalseWhenNoStateGenerated(): void
    {
        $session = new InMemorySession();
        $manager = new OAuthStateManager();

        self::assertFalse($manager->validate($session, 'any-state'));
    }
}

/**
 * @internal
 */
final class InMemorySession implements SessionInterface
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/OAuthStateManagerTest.php`
Expected: FAIL — class not found

- [ ] **Step 4: Implement OAuthStateManager**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final class OAuthStateManager
{
    private const SESSION_KEY = 'oauth_state';

    public function generate(SessionInterface $session): string
    {
        $state = bin2hex(random_bytes(32));
        $session->set(self::SESSION_KEY, $state);

        return $state;
    }

    public function validate(SessionInterface $session, string $state): bool
    {
        $expected = $session->get(self::SESSION_KEY);
        $session->remove(self::SESSION_KEY);

        if ($expected === null || ! is_string($expected)) {
            return false;
        }

        return hash_equals($expected, $state);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/OAuthStateManagerTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd /home/jones/dev/waaseyaa && git add packages/oauth-provider/src/SessionInterface.php packages/oauth-provider/src/OAuthStateManager.php packages/oauth-provider/tests/OAuthStateManagerTest.php && git commit -m "feat(oauth-provider): add SessionInterface + OAuthStateManager"
```

### Task 5: ProviderRegistry

**Files:**
- Create: `packages/oauth-provider/src/ProviderRegistry.php`
- Create: `packages/oauth-provider/tests/ProviderRegistryTest.php`

- [ ] **Step 1: Write failing test**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests;

use PHPUnit\Framework\TestCase;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;
use Waaseyaa\OAuthProvider\ProviderRegistry;

final class ProviderRegistryTest extends TestCase
{
    public function testRegisterAndGet(): void
    {
        $provider = $this->createStub(OAuthProviderInterface::class);
        $provider->method('getName')->willReturn('google');

        $registry = new ProviderRegistry();
        $registry->register('google', $provider);

        self::assertSame($provider, $registry->get('google'));
    }

    public function testHasReturnsTrueForRegistered(): void
    {
        $provider = $this->createStub(OAuthProviderInterface::class);

        $registry = new ProviderRegistry();
        $registry->register('google', $provider);

        self::assertTrue($registry->has('google'));
        self::assertFalse($registry->has('github'));
    }

    public function testGetThrowsForUnregistered(): void
    {
        $registry = new ProviderRegistry();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("OAuth provider 'unknown' is not registered");

        $registry->get('unknown');
    }

    public function testAllReturnsAllProviders(): void
    {
        $google = $this->createStub(OAuthProviderInterface::class);
        $github = $this->createStub(OAuthProviderInterface::class);

        $registry = new ProviderRegistry();
        $registry->register('google', $google);
        $registry->register('github', $github);

        self::assertCount(2, $registry->all());
        self::assertSame(['google' => $google, 'github' => $github], $registry->all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/ProviderRegistryTest.php`
Expected: FAIL

- [ ] **Step 3: Implement ProviderRegistry**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider;

final class ProviderRegistry
{
    /** @var array<string, OAuthProviderInterface> */
    private array $providers = [];

    public function register(string $name, OAuthProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
    }

    public function get(string $name): OAuthProviderInterface
    {
        if (! isset($this->providers[$name])) {
            throw new \InvalidArgumentException("OAuth provider '{$name}' is not registered");
        }

        return $this->providers[$name];
    }

    public function has(string $name): bool
    {
        return isset($this->providers[$name]);
    }

    /**
     * @return array<string, OAuthProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/ProviderRegistryTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
cd /home/jones/dev/waaseyaa && git add packages/oauth-provider/src/ProviderRegistry.php packages/oauth-provider/tests/ProviderRegistryTest.php && git commit -m "feat(oauth-provider): add ProviderRegistry"
```

### Task 6: GoogleOAuthProvider

**Files:**
- Create: `packages/oauth-provider/src/Provider/GoogleOAuthProvider.php`
- Create: `packages/oauth-provider/tests/Provider/GoogleOAuthProviderTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests\Provider;

use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;
use Waaseyaa\OAuthProvider\Provider\GoogleOAuthProvider;

final class GoogleOAuthProviderTest extends TestCase
{
    public function testGetName(): void
    {
        $provider = new GoogleOAuthProvider('client-id', 'client-secret', 'https://app/callback', $this->createStub(HttpClientInterface::class));

        self::assertSame('google', $provider->getName());
    }

    public function testGetAuthorizationUrl(): void
    {
        $provider = new GoogleOAuthProvider('client-id', 'client-secret', 'https://app/callback', $this->createStub(HttpClientInterface::class));

        $url = $provider->getAuthorizationUrl(['openid', 'email'], 'state-abc');

        self::assertStringContainsString('accounts.google.com/o/oauth2/v2/auth', $url);
        self::assertStringContainsString('client_id=client-id', $url);
        self::assertStringContainsString('redirect_uri=', $url);
        self::assertStringContainsString('state=state-abc', $url);
        self::assertStringContainsString('access_type=offline', $url);
        self::assertStringContainsString('prompt=consent', $url);
        self::assertStringContainsString('scope=openid+email', $url);
    }

    public function testExchangeCode(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('post')
            ->willReturn(new HttpResponse(200, [], json_encode([
                'access_token' => 'ya29.access',
                'refresh_token' => 'refresh-123',
                'expires_in' => 3600,
                'scope' => 'openid email',
                'token_type' => 'Bearer',
            ])));

        $provider = new GoogleOAuthProvider('client-id', 'client-secret', 'https://app/callback', $httpClient);
        $token = $provider->exchangeCode('auth-code-123');

        self::assertSame('ya29.access', $token->accessToken);
        self::assertSame('refresh-123', $token->refreshToken);
        self::assertSame(['openid', 'email'], $token->scopes);
        self::assertSame('Bearer', $token->tokenType);
        self::assertNotNull($token->expiresAt);
    }

    public function testExchangeCodeThrowsOnHttpError(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('post')
            ->willReturn(new HttpResponse(401, [], json_encode([
                'error' => 'invalid_grant',
                'error_description' => 'Code has expired',
            ])));

        $provider = new GoogleOAuthProvider('client-id', 'client-secret', 'https://app/callback', $httpClient);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Code has expired');

        $provider->exchangeCode('expired-code');
    }

    public function testRefreshToken(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('post')
            ->willReturn(new HttpResponse(200, [], json_encode([
                'access_token' => 'ya29.refreshed',
                'expires_in' => 3600,
                'scope' => 'openid email',
                'token_type' => 'Bearer',
            ])));

        $provider = new GoogleOAuthProvider('client-id', 'client-secret', 'https://app/callback', $httpClient);
        $token = $provider->refreshToken('refresh-123');

        self::assertSame('ya29.refreshed', $token->accessToken);
        self::assertNull($token->refreshToken);
    }

    public function testGetUserProfile(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('get')
            ->willReturn(new HttpResponse(200, [], json_encode([
                'id' => '12345',
                'email' => 'user@gmail.com',
                'name' => 'Jane Doe',
                'picture' => 'https://lh3.googleusercontent.com/photo.jpg',
                'verified_email' => true,
            ])));

        $provider = new GoogleOAuthProvider('client-id', 'client-secret', 'https://app/callback', $httpClient);
        $profile = $provider->getUserProfile('ya29.access');

        self::assertSame('12345', $profile->providerId);
        self::assertSame('user@gmail.com', $profile->email);
        self::assertSame('Jane Doe', $profile->name);
        self::assertSame('https://lh3.googleusercontent.com/photo.jpg', $profile->avatarUrl);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/Provider/GoogleOAuthProviderTest.php`
Expected: FAIL

- [ ] **Step 3: Check HttpClientInterface and HttpResponse exist in waaseyaa/http-client**

Run: `ls /home/jones/dev/waaseyaa/packages/http-client/src/`

If `HttpResponse` does not exist, check what the `HttpClientInterface` returns and adapt accordingly. The test code above assumes `HttpResponse` is a value object with `(int $statusCode, array $headers, string $body)`. Adjust the implementation to match whatever `waaseyaa/http-client` actually provides.

- [ ] **Step 4: Implement GoogleOAuthProvider**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Provider;

use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;

final class GoogleOAuthProvider implements OAuthProviderInterface
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function getName(): string
    {
        return 'google';
    }

    public function getAuthorizationUrl(array $scopes, string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', $scopes),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): OAuthToken
    {
        $response = $this->httpClient->post(self::TOKEN_ENDPOINT, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]));

        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        if ($response->statusCode >= 400) {
            throw new \RuntimeException($data['error_description'] ?? $data['error'] ?? 'Token exchange failed');
        }

        return $this->buildToken($data);
    }

    public function refreshToken(string $refreshToken): OAuthToken
    {
        $response = $this->httpClient->post(self::TOKEN_ENDPOINT, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ]));

        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        if ($response->statusCode >= 400) {
            throw new \RuntimeException($data['error_description'] ?? $data['error'] ?? 'Token refresh failed');
        }

        return $this->buildToken($data);
    }

    public function getUserProfile(string $accessToken): OAuthUserProfile
    {
        $response = $this->httpClient->get(self::USERINFO_ENDPOINT, [
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        if ($response->statusCode >= 400) {
            throw new \RuntimeException($data['error']['message'] ?? 'Failed to fetch user profile');
        }

        return new OAuthUserProfile(
            providerId: (string) $data['id'],
            email: $data['email'],
            name: $data['name'] ?? '',
            avatarUrl: $data['picture'] ?? null,
        );
    }

    private function buildToken(array $data): OAuthToken
    {
        $expiresAt = isset($data['expires_in'])
            ? new \DateTimeImmutable('+' . $data['expires_in'] . ' seconds')
            : null;

        $scopes = isset($data['scope'])
            ? explode(' ', $data['scope'])
            : [];

        return new OAuthToken(
            accessToken: $data['access_token'],
            refreshToken: $data['refresh_token'] ?? null,
            expiresAt: $expiresAt,
            scopes: $scopes,
            tokenType: $data['token_type'] ?? 'Bearer',
        );
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/Provider/GoogleOAuthProviderTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd /home/jones/dev/waaseyaa && git add packages/oauth-provider/src/Provider/GoogleOAuthProvider.php packages/oauth-provider/tests/Provider/GoogleOAuthProviderTest.php && git commit -m "feat(oauth-provider): add GoogleOAuthProvider"
```

### Task 7: GitHubOAuthProvider

**Files:**
- Create: `packages/oauth-provider/src/Provider/GitHubOAuthProvider.php`
- Create: `packages/oauth-provider/tests/Provider/GitHubOAuthProviderTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Tests\Provider;

use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\OAuthProvider\Provider\GitHubOAuthProvider;
use Waaseyaa\OAuthProvider\UnsupportedOperationException;

final class GitHubOAuthProviderTest extends TestCase
{
    public function testGetName(): void
    {
        $provider = new GitHubOAuthProvider('client-id', 'client-secret', 'https://app/callback', $this->createStub(HttpClientInterface::class));

        self::assertSame('github', $provider->getName());
    }

    public function testGetAuthorizationUrl(): void
    {
        $provider = new GitHubOAuthProvider('client-id', 'client-secret', 'https://app/callback', $this->createStub(HttpClientInterface::class));

        $url = $provider->getAuthorizationUrl(['repo', 'user:email'], 'state-xyz');

        self::assertStringContainsString('github.com/login/oauth/authorize', $url);
        self::assertStringContainsString('client_id=client-id', $url);
        self::assertStringContainsString('state=state-xyz', $url);
    }

    public function testExchangeCode(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::once())
            ->method('post')
            ->willReturn(new HttpResponse(200, [], json_encode([
                'access_token' => 'gho_abc123',
                'token_type' => 'bearer',
                'scope' => 'repo,user:email',
            ])));

        $provider = new GitHubOAuthProvider('client-id', 'client-secret', 'https://app/callback', $httpClient);
        $token = $provider->exchangeCode('code-123');

        self::assertSame('gho_abc123', $token->accessToken);
        self::assertNull($token->refreshToken);
        self::assertNull($token->expiresAt);
        self::assertSame(['repo', 'user:email'], $token->scopes);
    }

    public function testRefreshTokenThrowsUnsupportedException(): void
    {
        $provider = new GitHubOAuthProvider('client-id', 'client-secret', 'https://app/callback', $this->createStub(HttpClientInterface::class));

        $this->expectException(UnsupportedOperationException::class);

        $provider->refreshToken('some-token');
    }

    public function testGetUserProfile(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->expects(self::exactly(2))
            ->method('get')
            ->willReturnOnConsecutiveCalls(
                new HttpResponse(200, [], json_encode([
                    'id' => 67890,
                    'login' => 'janedoe',
                    'name' => 'Jane Doe',
                    'avatar_url' => 'https://avatars.githubusercontent.com/u/67890',
                ])),
                new HttpResponse(200, [], json_encode([
                    ['email' => 'jane@example.com', 'primary' => true, 'verified' => true],
                    ['email' => 'jane@work.com', 'primary' => false, 'verified' => true],
                ])),
            );

        $provider = new GitHubOAuthProvider('client-id', 'client-secret', 'https://app/callback', $httpClient);
        $profile = $provider->getUserProfile('gho_abc123');

        self::assertSame('67890', $profile->providerId);
        self::assertSame('jane@example.com', $profile->email);
        self::assertSame('Jane Doe', $profile->name);
        self::assertSame('https://avatars.githubusercontent.com/u/67890', $profile->avatarUrl);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/Provider/GitHubOAuthProviderTest.php`
Expected: FAIL

- [ ] **Step 3: Implement GitHubOAuthProvider**

```php
<?php

declare(strict_types=1);

namespace Waaseyaa\OAuthProvider\Provider;

use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;
use Waaseyaa\OAuthProvider\UnsupportedOperationException;

final class GitHubOAuthProvider implements OAuthProviderInterface
{
    private const AUTH_ENDPOINT = 'https://github.com/login/oauth/authorize';

    private const TOKEN_ENDPOINT = 'https://github.com/login/oauth/access_token';

    private const USER_ENDPOINT = 'https://api.github.com/user';

    private const EMAILS_ENDPOINT = 'https://api.github.com/user/emails';

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri,
        private readonly HttpClientInterface $httpClient,
    ) {}

    public function getName(): string
    {
        return 'github';
    }

    public function getAuthorizationUrl(array $scopes, string $state): string
    {
        return self::AUTH_ENDPOINT . '?' . http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => implode(' ', $scopes),
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): OAuthToken
    {
        $response = $this->httpClient->post(self::TOKEN_ENDPOINT, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri,
        ]));

        $data = json_decode($response->body, true, 512, JSON_THROW_ON_ERROR);

        if ($response->statusCode >= 400 || isset($data['error'])) {
            throw new \RuntimeException($data['error_description'] ?? $data['error'] ?? 'Token exchange failed');
        }

        $scopes = isset($data['scope']) && $data['scope'] !== ''
            ? explode(',', $data['scope'])
            : [];

        return new OAuthToken(
            accessToken: $data['access_token'],
            refreshToken: null,
            expiresAt: null,
            scopes: $scopes,
            tokenType: $data['token_type'] ?? 'bearer',
        );
    }

    public function refreshToken(string $refreshToken): OAuthToken
    {
        throw UnsupportedOperationException::refreshNotSupported('github');
    }

    public function getUserProfile(string $accessToken): OAuthUserProfile
    {
        $headers = [
            'Authorization' => 'Bearer ' . $accessToken,
            'User-Agent' => 'Waaseyaa/1.0',
            'Accept' => 'application/json',
        ];

        $userResponse = $this->httpClient->get(self::USER_ENDPOINT, $headers);
        $userData = json_decode($userResponse->body, true, 512, JSON_THROW_ON_ERROR);

        if ($userResponse->statusCode >= 400) {
            throw new \RuntimeException($userData['message'] ?? 'Failed to fetch GitHub user');
        }

        $emailsResponse = $this->httpClient->get(self::EMAILS_ENDPOINT, $headers);
        $emailsData = json_decode($emailsResponse->body, true, 512, JSON_THROW_ON_ERROR);

        $primaryEmail = '';
        foreach ($emailsData as $emailEntry) {
            if (($emailEntry['primary'] ?? false) && ($emailEntry['verified'] ?? false)) {
                $primaryEmail = $emailEntry['email'];
                break;
            }
        }

        return new OAuthUserProfile(
            providerId: (string) $userData['id'],
            email: $primaryEmail ?: ($userData['email'] ?? ''),
            name: $userData['name'] ?? $userData['login'] ?? '',
            avatarUrl: $userData['avatar_url'] ?? null,
        );
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/Provider/GitHubOAuthProviderTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
cd /home/jones/dev/waaseyaa && git add packages/oauth-provider/src/Provider/GitHubOAuthProvider.php packages/oauth-provider/tests/Provider/GitHubOAuthProviderTest.php && git commit -m "feat(oauth-provider): add GitHubOAuthProvider"
```

### Task 8: Run All Package Tests + Tag Release

- [ ] **Step 1: Run all package tests**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpunit packages/oauth-provider/tests/`
Expected: All tests PASS

- [ ] **Step 2: Run PHPStan on the package**

Run: `cd /home/jones/dev/waaseyaa && vendor/bin/phpstan analyse packages/oauth-provider/src/ --level=8`
Expected: No errors (or address any that appear)

- [ ] **Step 3: Tag and push**

```bash
cd /home/jones/dev/waaseyaa && git push origin main
```

Wait for the "Split Monorepo" GitHub Action to publish `waaseyaa/oauth-provider` as a sub-package. Then tag:

```bash
git tag v0.1.0-alpha.XX && git push origin v0.1.0-alpha.XX
```

(Replace XX with the next alpha version number. Check current latest with `git tag --sort=-v:refname | head -5`.)

---

## Phase 2: Claudriel Adoption

All Phase 2 work happens in `/home/jones/dev/claudriel/`.

### Task 9: Add waaseyaa/oauth-provider Dependency

**Files:**
- Modify: `composer.json`

- [ ] **Step 1: Update composer.json and install**

Run: `cd /home/jones/dev/claudriel && composer require waaseyaa/oauth-provider:dev-main`

- [ ] **Step 2: Verify the package is available**

Run: `cd /home/jones/dev/claudriel && php -r "require 'vendor/autoload.php'; echo class_exists('Waaseyaa\OAuthProvider\ProviderRegistry') ? 'OK' : 'MISSING';"`
Expected: `OK`

- [ ] **Step 3: Commit**

```bash
cd /home/jones/dev/claudriel && git add composer.json composer.lock && git commit -m "chore: add waaseyaa/oauth-provider dependency"
```

### Task 10: NativeSessionAdapter

**Files:**
- Create: `src/Support/NativeSessionAdapter.php`

- [ ] **Step 1: Create NativeSessionAdapter**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Waaseyaa\OAuthProvider\SessionInterface;

final class NativeSessionAdapter implements SessionInterface
{
    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }
}
```

- [ ] **Step 2: Commit**

```bash
cd /home/jones/dev/claudriel && git add src/Support/NativeSessionAdapter.php && git commit -m "feat(#637): add NativeSessionAdapter for oauth-provider package"
```

### Task 11: OAuthTokenManager (replaces Google + GitHub token managers)

**Files:**
- Create: `src/Support/OAuthTokenManagerInterface.php`
- Create: `src/Support/OAuthTokenManager.php`
- Create: `tests/Unit/Support/OAuthTokenManagerTest.php`

- [ ] **Step 1: Create OAuthTokenManagerInterface**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Support;

interface OAuthTokenManagerInterface
{
    /**
     * Returns a valid access token for the given account and provider.
     *
     * Refreshes transparently if expired (for providers that support refresh).
     *
     * @throws \RuntimeException if no active integration, integration is revoked, or refresh fails
     */
    public function getValidAccessToken(string $accountId, string $provider = 'google'): string;

    /**
     * Check if an account has an active integration for the given provider.
     */
    public function hasActiveIntegration(string $accountId, string $provider = 'google'): bool;

    /**
     * Mark all integrations for the given account and provider as revoked.
     */
    public function markRevoked(string $accountId, string $provider): void;
}
```

- [ ] **Step 2: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Claudriel\Entity\Integration;
use Claudriel\Support\OAuthTokenManager;
use Claudriel\Support\OAuthTokenManagerInterface;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\ProviderRegistry;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;

final class OAuthTokenManagerTest extends TestCase
{
    public function testGetValidAccessTokenReturnsCurrentTokenWhenNotExpired(): void
    {
        $integration = new Integration([
            'iid' => 1,
            'uuid' => 'int-uuid',
            'name' => 'google',
            'account_id' => 'acc-uuid',
            'provider' => 'google',
            'access_token' => 'ya29.valid',
            'refresh_token' => 'refresh-123',
            'token_expires_at' => (new \DateTimeImmutable('+3600 seconds'))->format('c'),
            'status' => 'active',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$integration]);

        $registry = new ProviderRegistry();
        $manager = new OAuthTokenManager($repo, $registry);

        $token = $manager->getValidAccessToken('acc-uuid', 'google');

        self::assertSame('ya29.valid', $token);
    }

    public function testGetValidAccessTokenRefreshesExpiredGoogleToken(): void
    {
        $integration = new Integration([
            'iid' => 1,
            'uuid' => 'int-uuid',
            'name' => 'google',
            'account_id' => 'acc-uuid',
            'provider' => 'google',
            'access_token' => 'ya29.expired',
            'refresh_token' => 'refresh-123',
            'token_expires_at' => (new \DateTimeImmutable('-60 seconds'))->format('c'),
            'status' => 'active',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$integration]);
        $repo->expects(self::once())->method('save');

        $googleProvider = $this->createMock(OAuthProviderInterface::class);
        $googleProvider->method('refreshToken')->willReturn(new OAuthToken(
            accessToken: 'ya29.refreshed',
            refreshToken: null,
            expiresAt: new \DateTimeImmutable('+3600 seconds'),
            scopes: ['email'],
            tokenType: 'Bearer',
        ));

        $registry = new ProviderRegistry();
        $registry->register('google', $googleProvider);

        $manager = new OAuthTokenManager($repo, $registry);
        $token = $manager->getValidAccessToken('acc-uuid', 'google');

        self::assertSame('ya29.refreshed', $token);
    }

    public function testGetValidAccessTokenReturnsGitHubTokenWithoutRefresh(): void
    {
        $integration = new Integration([
            'iid' => 1,
            'uuid' => 'int-uuid',
            'name' => 'github',
            'account_id' => 'acc-uuid',
            'provider' => 'github',
            'access_token' => 'gho_abc123',
            'refresh_token' => null,
            'token_expires_at' => null,
            'status' => 'active',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$integration]);

        $registry = new ProviderRegistry();
        $manager = new OAuthTokenManager($repo, $registry);

        $token = $manager->getValidAccessToken('acc-uuid', 'github');

        self::assertSame('gho_abc123', $token);
    }

    public function testGetValidAccessTokenThrowsWhenNoIntegration(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([]);

        $registry = new ProviderRegistry();
        $manager = new OAuthTokenManager($repo, $registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No active google integration');

        $manager->getValidAccessToken('acc-uuid', 'google');
    }

    public function testGetValidAccessTokenThrowsForRevokedIntegration(): void
    {
        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturnCallback(function (array $criteria) {
            if (($criteria['status'] ?? '') === 'revoked') {
                return [new Integration([
                    'iid' => 1,
                    'uuid' => 'int-uuid',
                    'name' => 'github',
                    'account_id' => 'acc-uuid',
                    'provider' => 'github',
                    'access_token' => 'old',
                    'status' => 'revoked',
                ])];
            }
            return [];
        });

        $registry = new ProviderRegistry();
        $manager = new OAuthTokenManager($repo, $registry);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('revoked');

        $manager->getValidAccessToken('acc-uuid', 'github');
    }

    public function testHasActiveIntegration(): void
    {
        $integration = new Integration([
            'iid' => 1,
            'uuid' => 'int-uuid',
            'name' => 'google',
            'account_id' => 'acc-uuid',
            'provider' => 'google',
            'access_token' => 'ya29.valid',
            'status' => 'active',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturnCallback(function (array $criteria) use ($integration) {
            if ($criteria['provider'] === 'google' && $criteria['status'] === 'active') {
                return [$integration];
            }
            return [];
        });

        $registry = new ProviderRegistry();
        $manager = new OAuthTokenManager($repo, $registry);

        self::assertTrue($manager->hasActiveIntegration('acc-uuid', 'google'));
        self::assertFalse($manager->hasActiveIntegration('acc-uuid', 'github'));
    }

    public function testMarkRevoked(): void
    {
        $integration = new Integration([
            'iid' => 1,
            'uuid' => 'int-uuid',
            'name' => 'github',
            'account_id' => 'acc-uuid',
            'provider' => 'github',
            'access_token' => 'gho_abc',
            'status' => 'active',
        ]);

        $repo = $this->createMock(EntityRepositoryInterface::class);
        $repo->method('findBy')->willReturn([$integration]);
        $repo->expects(self::once())->method('save');

        $registry = new ProviderRegistry();
        $manager = new OAuthTokenManager($repo, $registry);

        $manager->markRevoked('acc-uuid', 'github');

        self::assertSame('revoked', $integration->get('status'));
    }
}
```

- [ ] **Step 3: Run tests to verify they fail**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Support/OAuthTokenManagerTest.php`
Expected: FAIL

- [ ] **Step 4: Implement OAuthTokenManager**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Support;

use Claudriel\Entity\Integration;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\OAuthProvider\ProviderRegistry;

final class OAuthTokenManager implements OAuthTokenManagerInterface
{
    private const EXPIRY_BUFFER_SECONDS = 60;

    public function __construct(
        private readonly EntityRepositoryInterface $integrationRepo,
        private readonly ProviderRegistry $providerRegistry,
    ) {}

    public function getValidAccessToken(string $accountId, string $provider = 'google'): string
    {
        $revokedIntegrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => $provider,
            'status' => 'revoked',
        ], null, 1);

        if ($revokedIntegrations !== []) {
            throw new \RuntimeException(
                ucfirst($provider) . ' integration has been revoked. Re-authorize at /oauth/' . $provider . '/connect'
            );
        }

        $integration = $this->findActiveIntegration($accountId, $provider);

        if ($integration === null) {
            throw new \RuntimeException(
                'No active ' . $provider . ' integration found for this account. Connect at /oauth/' . $provider . '/connect'
            );
        }

        $expiresAt = $integration->get('token_expires_at');

        if ($expiresAt === null || ! $this->isExpired((string) $expiresAt)) {
            return (string) $integration->get('access_token');
        }

        $refreshToken = $integration->get('refresh_token');

        if ($refreshToken === null || $refreshToken === '') {
            $integration->set('status', 'error');
            $this->integrationRepo->save($integration);

            throw new \RuntimeException('No refresh token available for ' . $provider . ' account ' . $accountId);
        }

        return $this->refreshAccessToken($integration, (string) $refreshToken, $provider);
    }

    public function hasActiveIntegration(string $accountId, string $provider = 'google'): bool
    {
        return $this->findActiveIntegration($accountId, $provider) !== null;
    }

    public function markRevoked(string $accountId, string $provider): void
    {
        $integrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => $provider,
        ]);

        foreach ($integrations as $integration) {
            assert($integration instanceof Integration);
            $integration->set('status', 'revoked');
            $this->integrationRepo->save($integration);
        }
    }

    private function findActiveIntegration(string $accountId, string $provider): ?Integration
    {
        $integrations = $this->integrationRepo->findBy([
            'account_id' => $accountId,
            'provider' => $provider,
            'status' => 'active',
        ], null, 1);

        if ($integrations === []) {
            return null;
        }

        $integration = reset($integrations);
        assert($integration instanceof Integration);

        return $integration;
    }

    private function isExpired(string $expiresAt): bool
    {
        $expiry = new \DateTimeImmutable($expiresAt);
        $now = new \DateTimeImmutable();

        return $expiry->getTimestamp() - $now->getTimestamp() < self::EXPIRY_BUFFER_SECONDS;
    }

    private function refreshAccessToken(Integration $integration, string $refreshToken, string $provider): string
    {
        try {
            $oauthToken = $this->providerRegistry->get($provider)->refreshToken($refreshToken);
        } catch (\Throwable $e) {
            $integration->set('status', 'error');
            $this->integrationRepo->save($integration);

            throw new \RuntimeException(
                ucfirst($provider) . ' token refresh failed for account ' . $integration->get('account_id') . ': ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $integration->set('access_token', $oauthToken->accessToken);

        if ($oauthToken->expiresAt !== null) {
            $integration->set('token_expires_at', $oauthToken->expiresAt->format('c'));
        }

        if ($oauthToken->refreshToken !== null) {
            $integration->set('refresh_token', $oauthToken->refreshToken);
        }

        $this->integrationRepo->save($integration);

        return $oauthToken->accessToken;
    }
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Support/OAuthTokenManagerTest.php`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
cd /home/jones/dev/claudriel && git add src/Support/OAuthTokenManagerInterface.php src/Support/OAuthTokenManager.php tests/Unit/Support/OAuthTokenManagerTest.php && git commit -m "feat(#637): add unified OAuthTokenManager replacing Google + GitHub token managers"
```

### Task 12: OAuthController (replaces Google + GitHub OAuth controllers)

**Files:**
- Create: `src/Controller/OAuthController.php`
- Create: `tests/Unit/Controller/OAuthControllerTest.php`

- [ ] **Step 1: Write failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Controller;

use Claudriel\Controller\OAuthController;
use Claudriel\Service\PublicAccountSignupService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AnonymousUser;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\OAuthProvider\OAuthProviderInterface;
use Waaseyaa\OAuthProvider\OAuthStateManager;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;
use Waaseyaa\OAuthProvider\ProviderRegistry;
use Waaseyaa\OAuthProvider\SessionInterface;

final class OAuthControllerTest extends TestCase
{
    public function testConnectRedirectsToProviderAuthUrl(): void
    {
        $provider = $this->createMock(OAuthProviderInterface::class);
        $provider->method('getAuthorizationUrl')->willReturn('https://accounts.google.com/auth?client_id=test');

        $registry = new ProviderRegistry();
        $registry->register('google', $provider);

        $stateManager = $this->createMock(OAuthStateManager::class);
        $stateManager->method('generate')->willReturn('state-abc');

        $controller = $this->buildController($registry, $stateManager);

        $response = $controller->connect(
            params: ['provider' => 'google'],
            query: [],
            account: $this->createAuthenticatedAccount(),
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertStringContainsString('accounts.google.com', $response->getTargetUrl());
    }

    public function testConnectRedirectsToLoginWhenUnauthenticated(): void
    {
        $controller = $this->buildController(new ProviderRegistry(), $this->createStub(OAuthStateManager::class));

        $response = $controller->connect(
            params: ['provider' => 'google'],
            query: [],
            account: new AnonymousUser(),
        );

        self::assertSame('/login', $response->getTargetUrl());
    }

    public function testConnectReturns404ForUnknownProvider(): void
    {
        $controller = $this->buildController(new ProviderRegistry(), $this->createStub(OAuthStateManager::class));

        $response = $controller->connect(
            params: ['provider' => 'unknown'],
            query: [],
            account: $this->createAuthenticatedAccount(),
        );

        self::assertSame('/app', $response->getTargetUrl());
    }

    public function testConnectCallbackHandlesErrorParam(): void
    {
        $controller = $this->buildController(new ProviderRegistry(), $this->createStub(OAuthStateManager::class));

        $response = $controller->connectCallback(
            params: ['provider' => 'google'],
            query: ['error' => 'access_denied'],
            account: $this->createAuthenticatedAccount(),
        );

        self::assertSame('/app', $response->getTargetUrl());
    }

    private function buildController(
        ProviderRegistry $registry,
        OAuthStateManager $stateManager,
        ?EntityTypeManager $entityTypeManager = null,
        ?PublicAccountSignupService $signupService = null,
        ?SessionInterface $session = null,
    ): OAuthController {
        return new OAuthController(
            providerRegistry: $registry,
            stateManager: $stateManager,
            entityTypeManager: $entityTypeManager ?? $this->createStub(EntityTypeManager::class),
            signupService: $signupService ?? $this->createStub(PublicAccountSignupService::class),
            session: $session ?? $this->createInMemorySession(),
        );
    }

    private function createAuthenticatedAccount(): \Claudriel\Access\AuthenticatedAccount
    {
        $account = new \Claudriel\Entity\Account([
            'aid' => 1,
            'uuid' => 'acc-uuid-123',
            'name' => 'Test User',
            'email' => 'test@example.com',
            'status' => 'active',
        ]);

        return new \Claudriel\Access\AuthenticatedAccount($account);
    }

    private function createInMemorySession(): SessionInterface
    {
        return new class implements SessionInterface {
            private array $data = [];

            public function get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function remove(string $key): void
            {
                unset($this->data[$key]);
            }
        };
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Controller/OAuthControllerTest.php`
Expected: FAIL

- [ ] **Step 3: Implement OAuthController**

```php
<?php

declare(strict_types=1);

namespace Claudriel\Controller;

use Claudriel\Access\AuthenticatedAccount;
use Claudriel\Entity\Integration;
use Claudriel\Service\PublicAccountSignupService;
use Claudriel\Support\AuthenticatedAccountSessionResolver;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Twig\Environment;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\OAuthProvider\OAuthStateManager;
use Waaseyaa\OAuthProvider\OAuthToken;
use Waaseyaa\OAuthProvider\OAuthUserProfile;
use Waaseyaa\OAuthProvider\ProviderRegistry;
use Waaseyaa\OAuthProvider\SessionInterface;
use Waaseyaa\User\Middleware\CsrfMiddleware;

final class OAuthController
{
    private const FLOW_SCOPES = [
        'google' => [
            'connect' => [
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/calendar.readonly',
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/calendar.calendarlist.readonly',
                'https://www.googleapis.com/auth/calendar.freebusy',
                'https://www.googleapis.com/auth/drive.file',
            ],
            'signin' => [
                'openid',
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile',
            ],
        ],
        'github' => [
            'connect' => [
                'repo',
                'notifications',
                'read:org',
            ],
            'signin' => [
                'user:email',
                'read:user',
            ],
        ],
    ];

    /** @phpstan-ignore constructor.unusedParameter */
    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly OAuthStateManager $stateManager,
        private readonly EntityTypeManager $entityTypeManager,
        private readonly PublicAccountSignupService $signupService,
        private readonly SessionInterface $session,
        ?Environment $twig = null,
    ) {}

    public function connect(
        array $params = [],
        array $query = [],
        ?AccountInterface $account = null,
        ?Request $httpRequest = null,
    ): RedirectResponse {
        $authenticatedAccount = $this->resolveAccount($account);
        if ($authenticatedAccount === null) {
            return new RedirectResponse('/login', 302);
        }

        $providerName = $params['provider'] ?? '';

        if (! $this->providerRegistry->has($providerName)) {
            $_SESSION['flash_error'] = 'Unknown OAuth provider.';

            return new RedirectResponse('/app', 302);
        }

        $provider = $this->providerRegistry->get($providerName);
        $scopes = self::FLOW_SCOPES[$providerName]['connect'] ?? [];
        $state = $this->stateManager->generate($this->session);
        $this->session->set('oauth_flow', 'connect');

        $authUrl = $provider->getAuthorizationUrl($scopes, $state);

        return new RedirectResponse($authUrl, 302);
    }

    public function connectCallback(
        array $params = [],
        array $query = [],
        ?AccountInterface $account = null,
        ?Request $httpRequest = null,
    ): RedirectResponse {
        $authenticatedAccount = $this->resolveAccount($account);
        if ($authenticatedAccount === null) {
            return new RedirectResponse('/login', 302);
        }

        $providerName = $params['provider'] ?? '';

        if (isset($query['error'])) {
            $_SESSION['flash_error'] = ucfirst($providerName) . ' authorization denied: ' . $query['error'];

            return new RedirectResponse('/app', 302);
        }

        $flow = $this->session->get('oauth_flow');
        $this->session->remove('oauth_flow');

        if (! $this->stateManager->validate($this->session, $query['state'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid OAuth state. Please try again.';

            return new RedirectResponse('/app', 302);
        }

        if (! $this->providerRegistry->has($providerName)) {
            $_SESSION['flash_error'] = 'Unknown OAuth provider.';

            return new RedirectResponse('/app', 302);
        }

        $provider = $this->providerRegistry->get($providerName);

        try {
            $oauthToken = $provider->exchangeCode($query['code'] ?? '');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to exchange authorization code: ' . $e->getMessage();

            return new RedirectResponse('/app', 302);
        }

        try {
            $profile = $provider->getUserProfile($oauthToken->accessToken);
        } catch (\Throwable) {
            $profile = null;
        }

        $displayIdentity = $profile?->email ?: $profile?->name;

        $this->upsertIntegration($authenticatedAccount, $providerName, $oauthToken, $displayIdentity);

        $_SESSION['flash_success'] = ucfirst($providerName) . ' account connected'
            . ($displayIdentity ? ' as ' . $displayIdentity : '') . '.';

        return new RedirectResponse('/app', 302);
    }

    public function signin(
        array $params = [],
        array $query = [],
        ?AccountInterface $account = null,
        ?Request $httpRequest = null,
    ): RedirectResponse {
        $providerName = $params['provider'] ?? '';

        if (! $this->providerRegistry->has($providerName)) {
            $_SESSION['flash_error'] = 'Unknown OAuth provider.';

            return new RedirectResponse('/login', 302);
        }

        $provider = $this->providerRegistry->get($providerName);
        $scopes = self::FLOW_SCOPES[$providerName]['signin'] ?? [];
        $state = $this->stateManager->generate($this->session);
        $this->session->set('oauth_flow', 'signin');

        $authUrl = $provider->getAuthorizationUrl($scopes, $state);

        return new RedirectResponse($authUrl, 302);
    }

    public function signinCallback(
        array $params = [],
        array $query = [],
        ?AccountInterface $account = null,
        ?Request $httpRequest = null,
    ): RedirectResponse {
        $providerName = $params['provider'] ?? '';

        if (isset($query['error'])) {
            $_SESSION['flash_error'] = ucfirst($providerName) . ' sign-in denied: ' . $query['error'];

            return new RedirectResponse('/login', 302);
        }

        $flow = $this->session->get('oauth_flow');
        $this->session->remove('oauth_flow');

        if ($flow !== 'signin' || ! $this->stateManager->validate($this->session, $query['state'] ?? '')) {
            $_SESSION['flash_error'] = 'Invalid OAuth state. Please try again.';

            return new RedirectResponse('/login', 302);
        }

        if (! $this->providerRegistry->has($providerName)) {
            $_SESSION['flash_error'] = 'Unknown OAuth provider.';

            return new RedirectResponse('/login', 302);
        }

        $provider = $this->providerRegistry->get($providerName);

        try {
            $oauthToken = $provider->exchangeCode($query['code'] ?? '');
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Failed to exchange authorization code: ' . $e->getMessage();

            return new RedirectResponse('/login', 302);
        }

        try {
            $profile = $provider->getUserProfile($oauthToken->accessToken);
        } catch (\Throwable) {
            $_SESSION['flash_error'] = ucfirst($providerName) . ' profile could not be retrieved.';

            return new RedirectResponse('/login', 302);
        }

        if ($profile->email === '') {
            $_SESSION['flash_error'] = ucfirst($providerName) . ' account email is not available.';

            return new RedirectResponse('/login', 302);
        }

        $accountEntity = $this->signupService->createFromOAuth($providerName, $profile->email, $profile->name);

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['claudriel_account_uuid'] = $accountEntity->get('uuid');
        session_regenerate_id(true);
        CsrfMiddleware::regenerate();

        $this->upsertIntegration(
            new AuthenticatedAccount($accountEntity),
            $providerName,
            $oauthToken,
            $profile->email,
        );

        return new RedirectResponse('/app', 302);
    }

    private function upsertIntegration(
        AuthenticatedAccount $account,
        string $providerName,
        OAuthToken $oauthToken,
        ?string $providerEmail,
    ): void {
        $storage = $this->entityTypeManager->getStorage('integration');
        $accountId = $account->getUuid();

        $existingIds = $storage->getQuery()
            ->condition('account_id', $accountId)
            ->condition('provider', $providerName)
            ->range(0, 1)
            ->execute();

        $expiresAt = $oauthToken->expiresAt?->format('c');
        $scopes = json_encode($oauthToken->scopes);

        if ($existingIds !== []) {
            $integration = $storage->load(reset($existingIds));
            assert($integration instanceof Integration);

            $integration->set('access_token', $oauthToken->accessToken);
            $integration->set('token_expires_at', $expiresAt);
            $integration->set('scopes', $scopes);
            $integration->set('status', 'active');
            $integration->set('provider_email', $providerEmail);

            if ($oauthToken->refreshToken !== null) {
                $integration->set('refresh_token', $oauthToken->refreshToken);
            }
        } else {
            $integration = new Integration([
                'uuid' => bin2hex(random_bytes(16)),
                'name' => $providerName,
                'account_id' => $accountId,
                'provider' => $providerName,
                'access_token' => $oauthToken->accessToken,
                'refresh_token' => $oauthToken->refreshToken,
                'token_expires_at' => $expiresAt,
                'scopes' => $scopes,
                'status' => 'active',
                'provider_email' => $providerEmail,
                'metadata' => json_encode([
                    'token_type' => $oauthToken->tokenType,
                ]),
            ]);
        }

        $storage->save($integration);
    }

    private function resolveAccount(mixed $account): ?AuthenticatedAccount
    {
        if ($account instanceof AuthenticatedAccount) {
            return $account;
        }

        return (new AuthenticatedAccountSessionResolver($this->entityTypeManager))->resolve();
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Controller/OAuthControllerTest.php`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
cd /home/jones/dev/claudriel && git add src/Controller/OAuthController.php tests/Unit/Controller/OAuthControllerTest.php && git commit -m "feat(#637): add unified OAuthController replacing Google + GitHub controllers"
```

### Task 13: Update PublicAccountSignupService

**Files:**
- Modify: `src/Service/PublicAccountSignupService.php`

- [ ] **Step 1: Add createFromOAuth method**

Add the following method to `PublicAccountSignupService`, after the existing `createFromGoogle` method at line 134:

```php
public function createFromOAuth(string $provider, string $email, string $name): Account
{
    return $this->createFromGoogle($email, $name);
}
```

The existing `createFromGoogle()` logic is provider-agnostic (find-or-create by email), so `createFromOAuth()` simply delegates to it. This avoids duplicating the logic while providing the generalized interface.

- [ ] **Step 2: Run existing tests to ensure no regression**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/ --filter=SignupService`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
cd /home/jones/dev/claudriel && git add src/Service/PublicAccountSignupService.php && git commit -m "feat(#637): add createFromOAuth() to PublicAccountSignupService"
```

### Task 14: Update Service Wiring (ChatServiceProvider + AccountServiceProvider)

**Files:**
- Modify: `src/Provider/ChatServiceProvider.php`
- Modify: `src/Provider/AccountServiceProvider.php`
- Modify: `src/Provider/ClaudrielServiceProvider.php`

- [ ] **Step 1: Update ChatServiceProvider**

Replace the `GoogleTokenManagerInterface` and `GitHubTokenManagerInterface` singletons and add `ProviderRegistry`, `OAuthStateManager`, and `OAuthController` registration.

In `ChatServiceProvider`, replace the imports and singleton registrations. Remove uses of `GoogleTokenManager`, `GoogleTokenManagerInterface`, `GitHubTokenManager`, `GitHubTokenManagerInterface`. Add uses for `OAuthTokenManager`, `OAuthTokenManagerInterface`, `ProviderRegistry`, `OAuthStateManager`, `OAuthController`, `NativeSessionAdapter`, `PublicAccountSignupService`, and the package provider classes.

Replace the `GoogleTokenManagerInterface` singleton (around line 97-106) and `GitHubTokenManagerInterface` singleton (around line 141-145) with:

```php
$this->singleton(ProviderRegistry::class, function () {
    $httpClient = new \Waaseyaa\HttpClient\StreamHttpClient();
    $registry = new ProviderRegistry();

    $googleClientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '';
    $googleClientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: '';
    $googleRedirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') ?: '';

    $registry->register('google', new \Waaseyaa\OAuthProvider\Provider\GoogleOAuthProvider(
        $googleClientId,
        $googleClientSecret,
        $googleRedirectUri,
        $httpClient,
    ));

    $githubClientId = $_ENV['GITHUB_CLIENT_ID'] ?? getenv('GITHUB_CLIENT_ID') ?: '';
    $githubClientSecret = $_ENV['GITHUB_CLIENT_SECRET'] ?? getenv('GITHUB_CLIENT_SECRET') ?: '';
    $githubRedirectUri = $_ENV['GITHUB_REDIRECT_URI'] ?? getenv('GITHUB_REDIRECT_URI') ?: '';

    $registry->register('github', new \Waaseyaa\OAuthProvider\Provider\GitHubOAuthProvider(
        $githubClientId,
        $githubClientSecret,
        $githubRedirectUri,
        $httpClient,
    ));

    return $registry;
});

$this->singleton(OAuthTokenManagerInterface::class, function () {
    $integrationRepo = $this->buildIntegrationRepo();

    return new OAuthTokenManager(
        $integrationRepo,
        $this->resolve(ProviderRegistry::class),
    );
});

// Backward compatibility aliases
$this->singleton(GoogleTokenManagerInterface::class, function () {
    return $this->resolve(OAuthTokenManagerInterface::class);
});

$this->singleton(GitHubTokenManagerInterface::class, function () {
    return $this->resolve(OAuthTokenManagerInterface::class);
});
```

Note: The backward compatibility aliases allow existing consumers (`GoogleApiTrait`, agent tools, `InternalGoogleController`, `InternalGithubController`, `GitHubSyncCommand`) to keep working without immediate changes. They will be removed in a follow-up cleanup.

Also register the `OAuthController` as a singleton:

```php
$this->singleton(OAuthController::class, function () {
    return new OAuthController(
        providerRegistry: $this->resolve(ProviderRegistry::class),
        stateManager: new OAuthStateManager(),
        entityTypeManager: $this->resolve(EntityTypeManager::class),
        signupService: new PublicAccountSignupService($this->resolve(EntityTypeManager::class)),
        session: new NativeSessionAdapter(),
    );
});
```

- [ ] **Step 2: Update AccountServiceProvider routes**

Replace the Google OAuth and GitHub OAuth route blocks (lines 259-311) with:

```php
// OAuth connect routes (link provider to existing account)
$router->addRoute(
    'claudriel.oauth.connect',
    RouteBuilder::create('/oauth/{provider}/connect')
        ->controller(OAuthController::class . '::connect')
        ->allowAll()
        ->methods('GET')
        ->build(),
);

$connectCallbackRoute = RouteBuilder::create('/oauth/{provider}/connect/callback')
    ->controller(OAuthController::class . '::connectCallback')
    ->allowAll()
    ->methods('GET')
    ->build();
$connectCallbackRoute->setOption('_csrf', false);
$router->addRoute('claudriel.oauth.connect.callback', $connectCallbackRoute);

// OAuth sign-in routes (authenticate/create account via provider)
$router->addRoute(
    'claudriel.oauth.signin',
    RouteBuilder::create('/oauth/{provider}/signin')
        ->controller(OAuthController::class . '::signin')
        ->allowAll()
        ->methods('GET')
        ->build(),
);

$signinCallbackRoute = RouteBuilder::create('/oauth/{provider}/signin/callback')
    ->controller(OAuthController::class . '::signinCallback')
    ->allowAll()
    ->methods('GET')
    ->build();
$signinCallbackRoute->setOption('_csrf', false);
$router->addRoute('claudriel.oauth.signin.callback', $signinCallbackRoute);
```

Update the imports at the top of `AccountServiceProvider`: remove `GoogleOAuthController` and `GitHubOAuthController`, add `OAuthController`.

- [ ] **Step 3: Update ClaudrielServiceProvider env validation**

Add `GITHUB_SIGNIN_REDIRECT_URI` to the `$required` array (around line 99-104):

```php
$required = [
    'ANTHROPIC_API_KEY' => 'Anthropic API key for chat and AI pipelines',
    'AGENT_INTERNAL_SECRET' => 'HMAC secret for agent subprocess internal API auth (min 32 bytes)',
    'GOOGLE_CLIENT_ID' => 'Google OAuth client ID',
    'GOOGLE_CLIENT_SECRET' => 'Google OAuth client secret',
    'GOOGLE_REDIRECT_URI' => 'Google OAuth redirect URI',
    'GITHUB_SIGNIN_REDIRECT_URI' => 'GitHub OAuth sign-in redirect URI',
];
```

Also update the `GitHubTokenManager` instantiation in `ClaudrielServiceProvider` (around line 745) to use `OAuthTokenManager`:

```php
$oauthTokenManager = $this->resolve(OAuthTokenManagerInterface::class);
```

- [ ] **Step 4: Run all tests**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/`
Expected: PASS (existing tests may need token manager type adjustments)

- [ ] **Step 5: Commit**

```bash
cd /home/jones/dev/claudriel && git add src/Provider/ChatServiceProvider.php src/Provider/AccountServiceProvider.php src/Provider/ClaudrielServiceProvider.php && git commit -m "feat(#637): wire unified OAuth services + update routes to /oauth/{provider}/*"
```

### Task 15: Update Template Route References

**Files:**
- Modify: `templates/public/login.twig`
- Modify: `templates/public/signup.twig`
- Modify: `templates/settings.html.twig`

- [ ] **Step 1: Update login template**

Replace `/auth/google/signin` with `/oauth/google/signin`. Add GitHub sign-in link `/oauth/github/signin`.

- [ ] **Step 2: Update signup template**

Replace `/auth/google/signin` with `/oauth/google/signin`. Add GitHub sign-in link `/oauth/github/signin`.

- [ ] **Step 3: Update settings template**

Replace `/auth/google` with `/oauth/google/connect`. Replace `/github/connect` with `/oauth/github/connect`.

- [ ] **Step 4: Commit**

```bash
cd /home/jones/dev/claudriel && git add templates/public/login.twig templates/public/signup.twig templates/settings.html.twig && git commit -m "feat(#637): update template OAuth URLs to /oauth/{provider}/* pattern"
```

### Task 16: Update ChatStreamController

**Files:**
- Modify: `src/Controller/ChatStreamController.php`

- [ ] **Step 1: Replace inline GoogleTokenManager**

`ChatStreamController` has a `resolveGoogleTokenManager()` method (line 568) that creates `new GoogleTokenManager(...)` inline. Replace it to resolve `OAuthTokenManagerInterface` from the service container instead.

Replace the import of `GoogleTokenManager` and `GoogleTokenManagerInterface` with `OAuthTokenManagerInterface`.

Replace the `resolveGoogleTokenManager()` method body:

```php
private function resolveGoogleTokenManager(): ?OAuthTokenManagerInterface
{
    // ... existing env var check ...
    return $this->resolveService(OAuthTokenManagerInterface::class);
}
```

Update callers of this method (line ~486) — the return type changes from `?GoogleTokenManagerInterface` to `?OAuthTokenManagerInterface`, but the `getValidAccessToken()` call signature is compatible (default `$provider = 'google'`).

- [ ] **Step 2: Run tests**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/Unit/Controller/ChatStreamControllerTest.php`
Expected: PASS

- [ ] **Step 3: Commit**

```bash
cd /home/jones/dev/claudriel && git add src/Controller/ChatStreamController.php && git commit -m "refactor(#637): ChatStreamController uses OAuthTokenManagerInterface"
```

### Task 17: Delete Old Files

**Files:**
- Delete: `src/Controller/GoogleOAuthController.php`
- Delete: `src/Controller/GitHubOAuthController.php`
- Delete: `src/Support/GoogleTokenManager.php`
- Delete: `src/Support/GoogleTokenManagerInterface.php`
- Delete: `src/Support/GitHubTokenManager.php`
- Delete: `src/Support/GitHubTokenManagerInterface.php`
- Delete: `tests/Unit/Support/GoogleTokenManagerTest.php`
- Delete: `tests/Unit/Support/GitHubTokenManagerTest.php`
- Delete: `tests/Unit/Controller/GitHubOAuthControllerTest.php`
- Delete: `tests/Unit/Controller/GoogleOAuthControllerTest.php`
- Delete: `tests/Unit/Controller/GoogleOAuthSigninTest.php`

- [ ] **Step 1: Remove backward-compat aliases from ChatServiceProvider**

Remove the `GoogleTokenManagerInterface` and `GitHubTokenManagerInterface` singleton aliases added in Task 14. Update all remaining consumers to use `OAuthTokenManagerInterface` directly:

- `src/Controller/InternalGoogleController.php`: change constructor type-hint from `GoogleTokenManagerInterface` to `OAuthTokenManagerInterface`
- `src/Controller/InternalGithubController.php`: change constructor type-hint from `GitHubTokenManagerInterface` to `OAuthTokenManagerInterface`
- `src/Command/GitHubSyncCommand.php`: change constructor type-hint from `GitHubTokenManagerInterface` to `OAuthTokenManagerInterface`, update `markRevoked()` calls to pass `'github'` as second arg
- `src/Domain/Chat/Tool/CalendarListTool.php`: change `GoogleTokenManagerInterface` to `OAuthTokenManagerInterface`
- `src/Domain/Chat/Tool/CalendarCreateTool.php`: change `GoogleTokenManagerInterface` to `OAuthTokenManagerInterface`
- `src/Domain/Chat/Tool/GmailListTool.php`: change `GoogleTokenManagerInterface` to `OAuthTokenManagerInterface`
- `src/Domain/Chat/Tool/GmailReadTool.php`: change `GoogleTokenManagerInterface` to `OAuthTokenManagerInterface`
- `src/Domain/Chat/Tool/GmailSendTool.php`: change `GoogleTokenManagerInterface` to `OAuthTokenManagerInterface`

- [ ] **Step 2: Delete the old files**

```bash
cd /home/jones/dev/claudriel && git rm \
  src/Controller/GoogleOAuthController.php \
  src/Controller/GitHubOAuthController.php \
  src/Support/GoogleTokenManager.php \
  src/Support/GoogleTokenManagerInterface.php \
  src/Support/GitHubTokenManager.php \
  src/Support/GitHubTokenManagerInterface.php \
  tests/Unit/Support/GoogleTokenManagerTest.php \
  tests/Unit/Support/GitHubTokenManagerTest.php \
  tests/Unit/Controller/GitHubOAuthControllerTest.php \
  tests/Unit/Controller/GoogleOAuthControllerTest.php \
  tests/Unit/Controller/GoogleOAuthSigninTest.php
```

- [ ] **Step 3: Run full test suite**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/`
Expected: PASS

- [ ] **Step 4: Run PHPStan**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpstan analyse`
Expected: Clean (regenerate baseline if needed)

- [ ] **Step 5: Run Pint**

Run: `cd /home/jones/dev/claudriel && vendor/bin/pint`

- [ ] **Step 6: Commit**

```bash
cd /home/jones/dev/claudriel && git add -A && git commit -m "refactor(#637): remove old OAuth controllers + token managers, migrate all consumers to OAuthTokenManagerInterface"
```

### Task 18: Update CLAUDE.md + Specs

**Files:**
- Modify: `CLAUDE.md`
- Modify: `docs/specs/chat.md` (if it references GoogleTokenManager)
- Modify: `docs/specs/infrastructure.md` (if it references token managers)

- [ ] **Step 1: Update CLAUDE.md gotchas**

Update references to:
- `GoogleOAuthController` → `OAuthController`
- `GoogleTokenManager` → `OAuthTokenManager`
- `/auth/google/*` routes → `/oauth/google/*`
- `/github/connect` → `/oauth/github/connect`
- Add `GITHUB_SIGNIN_REDIRECT_URI` to env vars section
- Update the Architecture section to reflect the unified OAuth approach

- [ ] **Step 2: Update relevant specs**

Check and update `docs/specs/chat.md`, `docs/specs/infrastructure.md`, and `docs/specs/agent-subprocess.md` for any references to old class names or routes.

- [ ] **Step 3: Commit**

```bash
cd /home/jones/dev/claudriel && git add CLAUDE.md docs/specs/ && git commit -m "docs(#637): update CLAUDE.md and specs for unified OAuth architecture"
```

### Task 19: Final Verification

- [ ] **Step 1: Run full Claudriel test suite**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpunit tests/`
Expected: All tests PASS

- [ ] **Step 2: Run PHPStan**

Run: `cd /home/jones/dev/claudriel && vendor/bin/phpstan analyse`
Expected: Clean

- [ ] **Step 3: Run Pint**

Run: `cd /home/jones/dev/claudriel && vendor/bin/pint --test`
Expected: Clean

- [ ] **Step 4: Verify no references to old classes remain**

Run: `cd /home/jones/dev/claudriel && grep -rn 'GoogleOAuthController\|GitHubOAuthController\|GoogleTokenManager\b\|GitHubTokenManager\b' src/ tests/ templates/ --include='*.php' --include='*.twig'`
Expected: No matches

- [ ] **Step 5: Run local dev server smoke test**

Run: `cd /home/jones/dev/claudriel && PHP_CLI_SERVER_WORKERS=4 php -S 0.0.0.0:8081 -t public &`

Verify:
- `curl -s http://localhost:8081/oauth/google/connect` → 302 redirect (to login or Google)
- `curl -s http://localhost:8081/oauth/github/connect` → 302 redirect (to login or GitHub)
- `curl -s http://localhost:8081/oauth/google/signin` → 302 redirect to Google
- `curl -s http://localhost:8081/oauth/github/signin` → 302 redirect to GitHub
