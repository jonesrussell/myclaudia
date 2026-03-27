<?php

declare(strict_types=1);

namespace Claudriel\Provider;

use Claudriel\Controller\GitHubOAuthController;
use Claudriel\Controller\GoogleOAuthController;
use Claudriel\Controller\PublicAccountController;
use Claudriel\Controller\PublicPasswordResetController;
use Claudriel\Controller\PublicSessionController;
use Claudriel\Entity\Account;
use Claudriel\Entity\AccountPasswordResetToken;
use Claudriel\Entity\AccountVerificationToken;
use Claudriel\Entity\Tenant;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class AccountServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(new EntityType(
            id: 'account',
            label: 'Account',
            class: Account::class,
            keys: ['id' => 'aid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'aid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'email' => ['type' => 'email', 'required' => true],
                'password' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'roles' => ['type' => 'string'],
                'permissions' => ['type' => 'string'],
                'email_verified_at' => ['type' => 'datetime'],
                'settings' => ['type' => 'text_long'],
                'metadata' => ['type' => 'text_long'],
                'decay_rate_daily' => ['type' => 'float'],
                'min_importance_threshold' => ['type' => 'float'],
                'tenant_id' => ['type' => 'string'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'account_verification_token',
            label: 'Account Verification Token',
            class: AccountVerificationToken::class,
            keys: ['id' => 'avtid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'avtid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'account_uuid' => ['type' => 'string', 'required' => true],
                'token' => ['type' => 'string', 'required' => true],
                'expires_at' => ['type' => 'datetime'],
                'used_at' => ['type' => 'datetime'],
                'metadata' => ['type' => 'text_long'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'account_password_reset_token',
            label: 'Account Password Reset Token',
            class: AccountPasswordResetToken::class,
            keys: ['id' => 'aprtid', 'uuid' => 'uuid'],
            fieldDefinitions: [
                'aprtid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'account_uuid' => ['type' => 'string', 'required' => true],
                'token' => ['type' => 'string', 'required' => true],
                'expires_at' => ['type' => 'datetime'],
                'used_at' => ['type' => 'datetime'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));

        $this->entityType(new EntityType(
            id: 'tenant',
            label: 'Tenant',
            class: Tenant::class,
            keys: ['id' => 'tid', 'uuid' => 'uuid', 'label' => 'name'],
            fieldDefinitions: [
                'tid' => ['type' => 'integer', 'readOnly' => true],
                'uuid' => ['type' => 'string', 'readOnly' => true],
                'name' => ['type' => 'string', 'required' => true],
                'slug' => ['type' => 'string'],
                'metadata' => ['type' => 'text_long'],
                'created_at' => ['type' => 'timestamp', 'readOnly' => true],
                'updated_at' => ['type' => 'timestamp', 'readOnly' => true],
            ],
        ));
    }

    public function routes(WaaseyaaRouter $router, ?EntityTypeManager $entityTypeManager = null): void
    {
        $router->addRoute(
            'claudriel.public.signup_form',
            RouteBuilder::create('/signup')
                ->controller(PublicAccountController::class.'::signupForm')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.login_form',
            RouteBuilder::create('/login')
                ->controller(PublicSessionController::class.'::loginForm')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.password_reset_request_form',
            RouteBuilder::create('/forgot-password')
                ->controller(PublicPasswordResetController::class.'::requestForm')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $forgotPasswordRoute = RouteBuilder::create('/forgot-password')
            ->controller(PublicPasswordResetController::class.'::requestReset')
            ->allowAll()
            ->methods('POST')
            ->render()
            ->build();
        $router->addRoute('claudriel.public.password_reset_request', $forgotPasswordRoute);

        $router->addRoute(
            'claudriel.public.password_reset_check_email',
            RouteBuilder::create('/forgot-password/check-email')
                ->controller(PublicPasswordResetController::class.'::checkEmail')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.password_reset_form',
            RouteBuilder::create('/reset-password/{token}')
                ->controller(PublicPasswordResetController::class.'::resetForm')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $passwordResetRoute = RouteBuilder::create('/reset-password/{token}')
            ->controller(PublicPasswordResetController::class.'::resetPassword')
            ->allowAll()
            ->methods('POST')
            ->render()
            ->build();
        $router->addRoute('claudriel.public.password_reset_submit', $passwordResetRoute);

        $router->addRoute(
            'claudriel.public.password_reset_complete',
            RouteBuilder::create('/reset-password/complete')
                ->controller(PublicPasswordResetController::class.'::resetComplete')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $loginRoute = RouteBuilder::create('/login')
            ->controller(PublicSessionController::class.'::login')
            ->allowAll()
            ->methods('POST')
            ->render()
            ->build();
        $router->addRoute('claudriel.public.login_submit', $loginRoute);

        $waitlistRoute = RouteBuilder::create('/api/waitlist')
            ->controller(PublicAccountController::class.'::waitlistSignup')
            ->allowAll()
            ->methods('POST')
            ->build();
        $waitlistRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.api.waitlist', $waitlistRoute);

        $logoutRoute = RouteBuilder::create('/logout')
            ->controller(PublicSessionController::class.'::logout')
            ->allowAll()
            ->methods('POST')
            ->render()
            ->build();
        $router->addRoute('claudriel.public.logout', $logoutRoute);

        $router->addRoute(
            'claudriel.public.session_state',
            RouteBuilder::create('/account/session')
                ->controller(PublicSessionController::class.'::sessionState')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.signup_check_email',
            RouteBuilder::create('/signup/check-email')
                ->controller(PublicAccountController::class.'::checkEmail')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $signupRoute = RouteBuilder::create('/signup')
            ->controller(PublicAccountController::class.'::signup')
            ->allowAll()
            ->methods('POST')
            ->render()
            ->build();
        $router->addRoute('claudriel.public.signup_submit', $signupRoute);

        $router->addRoute(
            'claudriel.public.verify_email',
            RouteBuilder::create('/verify-email/{token}')
                ->controller(PublicAccountController::class.'::verifyEmail')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.verification_result',
            RouteBuilder::create('/signup/verification-result')
                ->controller(PublicAccountController::class.'::verificationResult')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        $router->addRoute(
            'claudriel.public.onboarding_bootstrap',
            RouteBuilder::create('/onboarding/bootstrap')
                ->controller(PublicAccountController::class.'::onboardingBootstrap')
                ->allowAll()
                ->methods('GET')
                ->render()
                ->build(),
        );

        // Google OAuth
        $router->addRoute(
            'claudriel.auth.google.redirect',
            RouteBuilder::create('/auth/google')
                ->controller(GoogleOAuthController::class.'::redirect')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $googleCallbackRoute = RouteBuilder::create('/auth/google/callback')
            ->controller(GoogleOAuthController::class.'::callback')
            ->allowAll()
            ->methods('GET')
            ->build();
        $googleCallbackRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.auth.google.callback', $googleCallbackRoute);

        // Google OAuth Sign-In (identity only, no service scopes)
        $router->addRoute(
            'claudriel.auth.google.signin',
            RouteBuilder::create('/auth/google/signin')
                ->controller(GoogleOAuthController::class.'::signin')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $googleSigninCallbackRoute = RouteBuilder::create('/auth/google/signin/callback')
            ->controller(GoogleOAuthController::class.'::signinCallback')
            ->allowAll()
            ->methods('GET')
            ->build();
        $googleSigninCallbackRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.auth.google.signin.callback', $googleSigninCallbackRoute);

        // GitHub OAuth
        $router->addRoute(
            'claudriel.auth.github.connect',
            RouteBuilder::create('/github/connect')
                ->controller(GitHubOAuthController::class.'::connect')
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $githubCallbackRoute = RouteBuilder::create('/github/callback')
            ->controller(GitHubOAuthController::class.'::callback')
            ->allowAll()
            ->methods('GET')
            ->build();
        $githubCallbackRoute->setOption('_csrf', false);
        $router->addRoute('claudriel.auth.github.callback', $githubCallbackRoute);
    }
}
