<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\ChatController;
use Claudriel\Controller\ChatStreamController;
use Claudriel\Controller\ContextController;
use Claudriel\Controller\InternalGithubController;
use Claudriel\Controller\InternalGoogleController;
use Claudriel\Controller\InternalSessionController;
use Claudriel\Controller\OAuthController;
use Claudriel\Domain\Chat\InternalApiTokenGenerator;
use Claudriel\Domain\IssueOrchestrator;
use Claudriel\Domain\Memory\RehearsalService;
use Claudriel\Entity\ChatMessage;
use Claudriel\Entity\ChatSession;
use Claudriel\Entity\ChatTokenUsage;
use Claudriel\Service\PublicAccountSignupService;
use Claudriel\Support\NativeSessionAdapter;
use Claudriel\Support\OAuthTokenManager;
use Claudriel\Support\OAuthTokenManagerInterface;
use Claudriel\Support\StorageRepositoryAdapter;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\HttpClient\StreamHttpClient;
use Waaseyaa\OAuthProvider\OAuthStateManager;
use Waaseyaa\OAuthProvider\Provider\GitHubOAuthProvider;
use Waaseyaa\OAuthProvider\Provider\GoogleOAuthProvider;
use Waaseyaa\OAuthProvider\ProviderRegistry;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'chat_session',
            label: 'Chat Session',
            class: ChatSession::class,
            keys: ['id' => 'csid', 'uuid' => 'uuid', 'label' => 'title'],
            fieldDefinitions: [
                'csid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'title' => ['type' => 'string'],
                'model' => ['type' => 'string'],
                'tenant_id' => ['type' => 'string'],
                'account_uuid' => ['type' => 'string'],
                'workspace_id' => ['type' => 'string'],
                'turns_consumed' => ['type' => 'integer'],
                'task_type' => ['type' => 'string'],
                'continued_count' => ['type' => 'integer'],
                'turn_limit_applied' => ['type' => 'integer'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'chat_message',
            label: 'Chat Message',
            class: ChatMessage::class,
            keys: ['id' => 'cmid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'cmid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'session_uuid' => ['type' => 'string', 'required' => true],
                'role' => ['type' => 'string', 'required' => true],
                'content' => ['type' => 'text_long'],
                'tool_calls' => ['type' => 'text_long'],
                'tool_results' => ['type' => 'text_long'],
                'token_count' => ['type' => 'integer'],
                'tenant_id' => ['type' => 'string'],
                'workspace_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'chat_token_usage',
            label: 'Chat Token Usage',
            class: ChatTokenUsage::class,
            keys: ['id' => 'ctuid', 'uuid' => 'uuid', 'label' => 'session_uuid'],
            fieldDefinitions: [
                'ctuid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'session_uuid' => ['type' => 'string', 'required' => true],
                'turn_number' => ['type' => 'integer'],
                'model' => ['type' => 'string'],
                'input_tokens' => ['type' => 'integer'],
                'output_tokens' => ['type' => 'integer'],
                'cache_read_tokens' => ['type' => 'integer'],
                'cache_write_tokens' => ['type' => 'integer'],
                'tenant_id' => ['type' => 'string'],
                'workspace_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->singleton(ProviderRegistry::class, function () {
            $httpClient = new StreamHttpClient;
            $registry = new ProviderRegistry;

            $googleClientId = $_ENV['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: '';
            $googleClientSecret = $_ENV['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: '';

            $googleRedirectUri = $_ENV['GOOGLE_REDIRECT_URI'] ?? getenv('GOOGLE_REDIRECT_URI') ?: '';
            $registry->register('google', new GoogleOAuthProvider(
                $googleClientId, $googleClientSecret, $googleRedirectUri, $httpClient,
            ));

            $googleSigninRedirectUri = $_ENV['GOOGLE_SIGNIN_REDIRECT_URI'] ?? getenv('GOOGLE_SIGNIN_REDIRECT_URI') ?: '';
            $registry->register('google-signin', new GoogleOAuthProvider(
                $googleClientId, $googleClientSecret, $googleSigninRedirectUri, $httpClient,
            ));

            $githubClientId = $_ENV['GITHUB_CLIENT_ID'] ?? getenv('GITHUB_CLIENT_ID') ?: '';
            $githubClientSecret = $_ENV['GITHUB_CLIENT_SECRET'] ?? getenv('GITHUB_CLIENT_SECRET') ?: '';

            $githubRedirectUri = $_ENV['GITHUB_REDIRECT_URI'] ?? getenv('GITHUB_REDIRECT_URI') ?: '';
            $registry->register('github', new GitHubOAuthProvider(
                $githubClientId, $githubClientSecret, $githubRedirectUri, $httpClient,
            ));

            $githubSigninRedirectUri = $_ENV['GITHUB_SIGNIN_REDIRECT_URI'] ?? getenv('GITHUB_SIGNIN_REDIRECT_URI') ?: '';
            $registry->register('github-signin', new GitHubOAuthProvider(
                $githubClientId, $githubClientSecret, $githubSigninRedirectUri, $httpClient,
            ));

            return $registry;
        });

        $this->singleton(OAuthTokenManagerInterface::class, function () {
            $integrationStorage = $this->resolve(EntityTypeManager::class)->getStorage('integration');
            $integrationRepo = new StorageRepositoryAdapter($integrationStorage);

            return new OAuthTokenManager(
                $integrationRepo,
                $this->resolve(ProviderRegistry::class),
            );
        });

        $this->singleton(OAuthController::class, function () {
            return new OAuthController(
                providerRegistry: $this->resolve(ProviderRegistry::class),
                stateManager: new OAuthStateManager,
                entityTypeManager: $this->resolve(EntityTypeManager::class),
                signupService: new PublicAccountSignupService($this->resolve(EntityTypeManager::class)),
                session: new NativeSessionAdapter,
            );
        });

        $this->singleton(InternalApiTokenGenerator::class, function () {
            $secret = $_ENV['AGENT_INTERNAL_SECRET'] ?? getenv('AGENT_INTERNAL_SECRET') ?: '';
            $env = $_ENV['CLAUDRIEL_ENV'] ?? getenv('CLAUDRIEL_ENV') ?: 'development';

            if ($secret === '' || strlen($secret) < 32 || $secret === 'change-me-to-a-random-string-at-least-32-bytes') {
                $message = 'AGENT_INTERNAL_SECRET is missing, too short (min 32 bytes), or still set to the example default. Internal API endpoints are unprotected.';
                if ($env === 'production') {
                    throw new \RuntimeException($message);
                }
                error_log('[claudriel] WARNING: '.$message);
            }

            return new InternalApiTokenGenerator($secret);
        });

        $this->singleton(InternalSessionController::class, function () {
            $entityTypeManager = $this->resolve(EntityTypeManager::class);

            return new InternalSessionController(
                new StorageRepositoryAdapter($entityTypeManager->getStorage('chat_session')),
                $this->resolve(InternalApiTokenGenerator::class),
                $_ENV['CLAUDRIEL_DEFAULT_TENANT'] ?? getenv('CLAUDRIEL_DEFAULT_TENANT') ?: 'default',
                $entityTypeManager,
            );
        });

        $this->singleton(InternalGoogleController::class, function () {
            return new InternalGoogleController(
                $this->resolve(OAuthTokenManagerInterface::class),
                $this->resolve(InternalApiTokenGenerator::class),
            );
        });

        $this->singleton(InternalGithubController::class, function () {
            return new InternalGithubController(
                $this->resolve(OAuthTokenManagerInterface::class),
                $this->resolve(InternalApiTokenGenerator::class),
            );
        });

        $this->singleton(ChatStreamController::class, function () {
            $orchestrator = null;
            try {
                $orchestrator = $this->resolve(IssueOrchestrator::class);
            } catch (\Throwable) {
            }

            $rehearsal = null;
            try {
                $rehearsal = $this->resolve(RehearsalService::class);
            } catch (\Throwable) {
            }

            return new ChatStreamController(
                $this->resolve(EntityTypeManager::class),
                null,
                $orchestrator,
                $rehearsal,
            );
        });
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        // Make EntityTypeManager available to lazy singleton factories that
        // call $this->resolve(EntityTypeManager::class). The kernel provides
        // it here but not during register(), so bridge it into the bindings.
        if ($entityTypeManager !== null) {
            $this->singleton(EntityTypeManager::class, static fn () => $entityTypeManager);
        }

        $router->addRoute(
            'claudriel.stream.chat',
            RouteBuilder::create('/stream/chat/{messageId}')
                ->controller(ChatStreamController::class.'::stream')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        // Internal API routes (agent subprocess -> PHP)
        $internalGmailListRoute = RouteBuilder::create('/api/internal/gmail/list')
            ->controller(InternalGoogleController::class.'::gmailList')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalGmailListRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.gmail.list', $internalGmailListRoute);

        $internalGmailReadRoute = RouteBuilder::create('/api/internal/gmail/read/{id}')
            ->controller(InternalGoogleController::class.'::gmailRead')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalGmailReadRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.gmail.read', $internalGmailReadRoute);

        $internalGmailSendRoute = RouteBuilder::create('/api/internal/gmail/send')
            ->controller(InternalGoogleController::class.'::gmailSend')
            ->allowAll()
            ->methods('POST')
            ->build();
        $internalGmailSendRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.gmail.send', $internalGmailSendRoute);

        $internalCalendarListRoute = RouteBuilder::create('/api/internal/calendar/list')
            ->controller(InternalGoogleController::class.'::calendarList')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalCalendarListRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.calendar.list', $internalCalendarListRoute);

        $internalCalendarCreateRoute = RouteBuilder::create('/api/internal/calendar/create')
            ->controller(InternalGoogleController::class.'::calendarCreate')
            ->allowAll()
            ->methods('POST')
            ->build();
        $internalCalendarCreateRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.calendar.create', $internalCalendarCreateRoute);

        $internalSessionLimitsRoute = RouteBuilder::create('/api/internal/session/limits')
            ->controller(InternalSessionController::class.'::getLimits')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalSessionLimitsRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.session.limits', $internalSessionLimitsRoute);

        $internalSessionContinueRoute = RouteBuilder::create('/api/internal/session/{id}/continue')
            ->controller(InternalSessionController::class.'::continueSession')
            ->allowAll()
            ->methods('POST')
            ->build();
        $internalSessionContinueRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.session.continue', $internalSessionContinueRoute);

        // Internal GitHub API (agent subprocess)
        $internalGithubNotificationsRoute = RouteBuilder::create('/api/internal/github/notifications')
            ->controller(InternalGithubController::class.'::notifications')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalGithubNotificationsRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.github.notifications', $internalGithubNotificationsRoute);

        $internalGithubIssuesRoute = RouteBuilder::create('/api/internal/github/issues')
            ->controller(InternalGithubController::class.'::listIssues')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalGithubIssuesRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.github.issues', $internalGithubIssuesRoute);

        $internalGithubIssueRoute = RouteBuilder::create('/api/internal/github/issue/{owner}/{repo}/{number}')
            ->controller(InternalGithubController::class.'::readIssue')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalGithubIssueRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.github.issue', $internalGithubIssueRoute);

        $internalGithubPullsRoute = RouteBuilder::create('/api/internal/github/pulls')
            ->controller(InternalGithubController::class.'::listPulls')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalGithubPullsRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.github.pulls', $internalGithubPullsRoute);

        $internalGithubPullRoute = RouteBuilder::create('/api/internal/github/pull/{owner}/{repo}/{number}')
            ->controller(InternalGithubController::class.'::readPull')
            ->allowAll()
            ->methods('GET')
            ->build();
        $internalGithubPullRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.github.pull', $internalGithubPullRoute);

        $internalGithubCreateIssueRoute = RouteBuilder::create('/api/internal/github/issue/{owner}/{repo}')
            ->controller(InternalGithubController::class.'::createIssue')
            ->allowAll()
            ->methods('POST')
            ->build();
        $internalGithubCreateIssueRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.github.create_issue', $internalGithubCreateIssueRoute);

        $internalGithubCommentRoute = RouteBuilder::create('/api/internal/github/comment/{owner}/{repo}/{number}')
            ->controller(InternalGithubController::class.'::addComment')
            ->allowAll()
            ->methods('POST')
            ->build();
        $internalGithubCommentRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.internal.github.comment', $internalGithubCommentRoute);

        $router->addRoute(
            'claudriel.api.context',
            RouteBuilder::create('/api/context')
                ->controller(ContextController::class.'::show')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.api.chat.sessions.list',
            RouteBuilder::create('/api/chat/sessions')
                ->controller(ChatController::class.'::sessionsList')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.api.chat.sessions.messages',
            RouteBuilder::create('/api/chat/sessions/{uuid}/messages')
                ->controller(ChatController::class.'::messages')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.api.chat.send',
            RouteBuilder::create('/api/chat/send')
                ->controller(ChatController::class.'::send')
                ->allowAll()
                ->methods('POST')
                ->render()
                ->build(),
        );
    }
}
