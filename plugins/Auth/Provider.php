<?php

declare(strict_types=1);

namespace Plugins\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\Application\Services\AuthService;
use Plugins\Auth\Infrastructure\Http\Controllers\SessionAuthController;
use Plugins\Auth\Infrastructure\Persistence\PersonalAccessTokenRepository;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * Auth plugin — GDA-native authentication.
 *
 * Credential ISSUANCE (login, token minting) lives in AuthService, exposed via
 * AuthServiceContract. Credential VERIFICATION lives in the SecurityLayer
 * classes (JwtAuthLayer, PersonalAccessTokenLayer) which a project wires into
 * the kernel's withSecurity([...]) chain — the kernel runs them before any
 * module loads, exactly where the GDA design expects the AuthModule layer.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'auth.identity';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        // Mirrors module.json "requires". personal_access_tokens is a control-plane
        // table, pinned to central. UserServiceContract verifies credentials for the
        // session login flow; SessionPort (essential) carries the web/AJAX session.
        return ['database.management', 'crypto.services', 'user.management', 'authorization.policy'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [
            AuthServiceContract::class,
            \Plugins\Auth\API\Contracts\RefreshTokenServiceContract::class,
        ];
    }

    public function register(ModuleContainer $container): void
    {
        // ONE nesting-aware transaction manager for ALL Auth writes. Every Auth
        // repository is pinned to the CENTRAL connection, so transactions must
        // bracket that same connection — the kernel's request TransactionManager
        // wraps the (possibly tenant-rebound) DatabasePort and would open the
        // transaction on the wrong database. Shared (singleton) so composed
        // flows (revokeOthers → establish) nest instead of double-beginning.
        $container->singleton('auth.transaction', static fn(ModuleContainer $c) =>
            new \AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager(
                $c->make(DatabaseConnectionManagerContract::class)->default(),
            )
        );

        $container->bindInternal(PersonalAccessTokenRepository::class, static fn(ModuleContainer $c) =>
            new PersonalAccessTokenRepository(
                // Central connection — tokens belong to the control plane, not a
                // tenant DB, so resolve the ConnectionManager default rather than
                // the per-request (tenant-rebound) DatabasePort.
                $c->make(DatabasePort::class),
                env('AUTH_PAT_TABLE') ?: 'personal_access_tokens',
            )
        );

        // Device-session registry (central — auth_sessions is control-plane).
        $container->bindInternal(\Plugins\Auth\Infrastructure\Persistence\DeviceSessionRepository::class,
            static fn(ModuleContainer $c) =>
                new \Plugins\Auth\Infrastructure\Persistence\DeviceSessionRepository(
                    $c->make(DatabasePort::class),
                )
        );

        // Fingerprint + server-side session validation. Public bind (not exposed
        // cross-module) so SessionAuthStage can resolve it from the request
        // container on every stateful request.
        $container->bind(\Plugins\Auth\Application\Services\DeviceSessionService::class,
            static fn(ModuleContainer $c) =>
                new \Plugins\Auth\Application\Services\DeviceSessionService(
                    sessions:          $c->make(\Plugins\Auth\Infrastructure\Persistence\DeviceSessionRepository::class),
                    ttlDays:           (int) (\auth_config('session.ttl_days') ?? 30),
                    refreshDays:       (int) (\auth_config('session.refresh_days') ?? 7),
                    fingerprintHeader: (string) (\auth_config('session.client_fingerprint_header') ?? 'X-Client-Fingerprint'),
                    transaction:       $c->make('auth.transaction'),
                )
        );

        // RBAC bridge — resolves a user's roles/permissions from the
        // Authorization plugin's policy store when it is loaded for the request;
        // degrades to empty lists otherwise (optional dependency).
        $container->bindInternal(\Plugins\Auth\Application\Auth\RoleResolver::class, static fn(ModuleContainer $c) =>
            new \Plugins\Auth\Application\Auth\RoleResolver(
                $c->has(\Plugins\Authorization\API\Contracts\AuthorizationServiceContract::class)
                    ? $c->make(\Plugins\Authorization\API\Contracts\AuthorizationServiceContract::class)
                    : null,
            )
        );

        $container->bind(AuthServiceContract::class, static fn(ModuleContainer $c) =>
            new AuthService(
                tokens:    $c->make(PersonalAccessTokenRepository::class),
                hasher:    $c->make(HashingPort::class),
                jwtSecret:   env('JWT_SECRET') ?: '',
                jwtAlgo:     env('JWT_ALGO') ?: 'HS256',
                jwtIssuer:     env('JWT_ISSUER') ?: null,
                jwtAudience:   env('JWT_AUDIENCE') ?: null,
                cache:         $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
                jwtPrivateKey: self::readKey(env('JWT_PRIVATE_KEY'), env('JWT_PRIVATE_KEY_FILE')),
                jwtKid:        env('JWT_KID') ?: null,
                roles:         $c->make(\Plugins\Auth\Application\Auth\RoleResolver::class),
                transaction:   $c->make('auth.transaction'),
                // Fills the display-identity claims (preferred_username/email)
                // on issued credentials when the caller doesn't supply them.
                // LAZY closure — an eager make() recurses: AuthService →
                // UserService → MembershipService → AuthService (bind() has no
                // cycle guard, so it loops until max_execution_time).
                users:         $c->has(\Plugins\User\API\Contracts\UserServiceContract::class)
                    ? static fn() => $c->make(\Plugins\User\API\Contracts\UserServiceContract::class)
                    : null,
            )
        );

        // Session login/logout controller for web + AJAX. Credentials verified by
        // the User module; SessionPort (essential) carries the stateful session.
        // Session login/logout drives the AuthManager 'web' guard; the
        // controller only needs the device registry for the list/revoke-by-id
        // endpoints (the login flow itself goes through the guard).
        $container->bindInternal(SessionAuthController::class, static fn(ModuleContainer $c) =>
            new SessionAuthController(
                $c->make(\Plugins\Auth\Application\Services\DeviceSessionService::class),
            )
        );

        // Self-service PAT management controller (GET/POST/DELETE /auth/tokens).
        $container->bindInternal(\Plugins\Auth\Infrastructure\Http\Controllers\PersonalAccessTokenController::class,
            static fn(ModuleContainer $c) =>
                new \Plugins\Auth\Infrastructure\Http\Controllers\PersonalAccessTokenController(
                    $c->make(AuthServiceContract::class),
                )
        );

        // Refresh-token session store (central connection — control-plane data).
        $container->bindInternal(\Plugins\Auth\Application\Ports\RefreshTokenStore::class, static fn(ModuleContainer $c) =>
            new \Plugins\Auth\Infrastructure\Persistence\RefreshTokenRepository(
                $c->make(DatabaseConnectionManagerContract::class)->default(),
            )
        );

        // Refresh-token service (revocable long-lived first-party sessions).
        $container->bind(\Plugins\Auth\API\Contracts\RefreshTokenServiceContract::class, static fn(ModuleContainer $c) =>
            new \Plugins\Auth\Application\Services\RefreshTokenService(
                tokens:      $c->make(\Plugins\Auth\Application\Ports\RefreshTokenStore::class),
                auth:        $c->make(AuthServiceContract::class),
                refreshTtl:  (int) (env('AUTH_REFRESH_TTL') ?: 2592000),
                accessTtl:   (int) (env('AUTH_REFRESH_ACCESS_TTL') ?: 900),
                transaction: $c->make('auth.transaction'),
            )
        );

        $container->bindInternal(\Plugins\Auth\Infrastructure\Http\Controllers\AuthTokenController::class,
            static fn(ModuleContainer $c) =>
                new \Plugins\Auth\Infrastructure\Http\Controllers\AuthTokenController(
                    $c->make(\Plugins\Auth\API\Contracts\RefreshTokenServiceContract::class),
                )
        );

        // Transient-token controller (POST /auth/token/refresh) for first-party SPAs.
        $container->bindInternal(\Plugins\Auth\Infrastructure\Http\Controllers\TransientTokenController::class,
            static fn(ModuleContainer $c) =>
                new \Plugins\Auth\Infrastructure\Http\Controllers\TransientTokenController(
                    $c->make(AuthServiceContract::class),
                )
        );

        // Mobile auth flow (old __DEV__ /v1/auth/*). PUBLIC binds so a project
        // route override (adding "requires": ["auth.identity","oauth.server"])
        // can resolve them — that override is how PKCE mode is enabled; without
        // it the OAuth2 module isn't in the graph and PKCE returns a clear 4xx.
        $container->bind(\Plugins\Auth\Application\Services\MobileAuthService::class, static fn(ModuleContainer $c) =>
            new \Plugins\Auth\Application\Services\MobileAuthService(
                users:         $c->make(UserServiceContract::class),
                auth:          $c->make(AuthServiceContract::class),
                oauthFlow:     $c->has(\Plugins\OAuth2\Application\Ports\AuthorizationFlow::class)
                    ? $c->make(\Plugins\OAuth2\Application\Ports\AuthorizationFlow::class)
                    : null,
                autoVerify:    !\in_array(strtolower((string) (env('AUTH_MOBILE_AUTOVERIFY') ?? '1')), ['0', 'false', 'off', 'no'], true),
            )
        );

        $container->bind(\Plugins\Auth\Infrastructure\Http\Controllers\MobileAuthController::class,
            static fn(ModuleContainer $c) =>
                new \Plugins\Auth\Infrastructure\Http\Controllers\MobileAuthController(
                    $c->make(UserServiceContract::class),
                    $c->make(\Plugins\Auth\Application\Services\MobileAuthService::class),
                )
        );

        // Default user provider (ModelUserProvider over the central identity store).
        // Passing AuthServiceContract lights up the HasApiTokens surface on proxies.
        // The tenant gate makes membership part of the fetch: on a tenant-scoped
        // request a user with no active seat in that tenant simply does not exist.
        $container->bind(\Plugins\Auth\Application\Ports\UserProvider::class, static fn(ModuleContainer $c) =>
            new \Plugins\Auth\Application\Auth\ModelUserProvider(
                $c->make(UserServiceContract::class),
                'users',
                ['identifier', 'email', 'username'],
                $c->make(AuthServiceContract::class)
            )
        );

        // Password reset broker (CachePort-backed). Bound only when a cache is
        // available — the reset flow needs a token store.
        if ($container->has(CachePort::class)) {
            $container->bind(\Plugins\Auth\Application\Ports\PasswordBroker::class, static fn(ModuleContainer $c) =>
                new \Plugins\Auth\Application\Auth\PasswordResetBroker(
                    $c->make(UserServiceContract::class),
                    $c->make(CachePort::class),
                    otpTtlSeconds: (int) (env('AUTH_OTP_TTL') ?: 600),
                )
            );

            // OTP forgot-password endpoints (old __DEV__ mobile flow). MailPort
            // is OPTIONAL — without a mailer the OTP is only visible in dev
            // transports; the flow itself keeps working.
            $container->bindInternal(\Plugins\Auth\Infrastructure\Http\Controllers\PasswordResetController::class,
                static fn(ModuleContainer $c) =>
                    new \Plugins\Auth\Infrastructure\Http\Controllers\PasswordResetController(
                        $c->make(\Plugins\Auth\Application\Ports\PasswordBroker::class),
                        $c->has(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort::class)
                            ? $c->make(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\MailPort::class)
                            : null,
                    )
            );
        }

        // AuthManager — manages named guards + providers. Request is injected per
        // use via the InteractsWithAuthManager controller concern (setRequest).
        $container->bind(\Plugins\Auth\Application\Auth\AuthManager::class, static fn(ModuleContainer $c) =>
            new \Plugins\Auth\Application\Auth\AuthManager(
                config: \auth_config(),
                providerFactory: static function (string $name) use ($c): ?\Plugins\Auth\Application\Ports\UserProvider {
                    // Resolve the named provider from config; only 'model' is
                    // built-in — extend this switch to back other stores.
                    $driver = \auth_config('providers.' . $name . '.driver');
                    return $driver === 'model'
                        ? new \Plugins\Auth\Application\Auth\ModelUserProvider(
                            $c->make(UserServiceContract::class),
                            $name,
                            ['identifier', 'email', 'username'],
                            $c->make(AuthServiceContract::class)
                        )
                        : null;
                },
                session: $c->has(SessionPort::class) ? $c->make(SessionPort::class) : null,
                auth:    $c->make(AuthServiceContract::class),
                statefulFactory: static function (string $name, \Plugins\Auth\Application\Ports\UserProvider $provider, \AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request $request) use ($c): ?\Plugins\Auth\Application\Ports\StatefulGuard {
                    if (!$c->has(SessionPort::class)) {
                        return null;
                    }

                    // WRITE-side guard for the old flow:
                    //   auth()->guard('web')->attempt($credentials, remember: true)
                    $guard = new \Plugins\Auth\Application\Auth\StatefulSessionGuard(
                        name:     $name,
                        provider: $provider,
                        session:  $c->make(SessionPort::class),
                        users:    $c->make(UserServiceContract::class),
                        cookies:  $c->has(\Plugins\Cookie\Infrastructure\CookieJar::class)
                            ? $c->make(\Plugins\Cookie\Infrastructure\CookieJar::class)
                            : null,
                        devices:  $c->make(\Plugins\Auth\Application\Services\DeviceSessionService::class),
                    );

                    return $guard->setRequest($request);
                },
                refreshTokens: $c->make(\Plugins\Auth\API\Contracts\RefreshTokenServiceContract::class),
                accessTtl:     (int) (env('AUTH_MOBILE_ACCESS_TTL') ?: 3600),
            )
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Token verification (JWT / PAT) runs in the SecurityGateway via project
        // ->withSecurity([...]). SESSION auth (web + AJAX) cannot run there — the
        // session is only opened at after.load — so attach a session Identity just
        // after StartSessionStage and before the route `auth` filter.
        $http->hook('after.load', \Plugins\Auth\Infrastructure\Http\Stages\SessionAuthStage::class, priority: \Plugins\Auth\Infrastructure\Http\Stages\SessionAuthStage::PRIORITY);

        // Control-plane maintenance command (auth:tokens:prune). Deferred so only
        // CLI processes pay for it; the repository pins to the central connection
        // (tokens are control-plane data, never tenant-scoped).
        $cli->defer(static function (CliPipeline $cli): void {
            $c = new ModuleContainer($cli->container());
            $c->setScope('database.management');
            (new \Plugins\Database\Provider())->register($c);

            $repository = new PersonalAccessTokenRepository(
                $c->make(DatabaseConnectionManagerContract::class)->default(),
                env('AUTH_PAT_TABLE') ?: 'personal_access_tokens',
            );

            $cli->command(new \Plugins\Auth\Infrastructure\Cli\PruneAccessTokensCommand($repository));
        });
    }


    /**
     * Resolve a PEM signing key from either an inline env value or a file path
     * (the file form is preferred in production — keys stay off the process
     * environment). Returns null when neither is configured (symmetric mode).
     */
    private static function readKey(mixed $inline, mixed $file): ?string
    {
        $file = is_string($file) ? trim($file) : '';
        if ($file !== '' && is_readable($file)) {
            $contents = file_get_contents($file);
            if ($contents !== false && trim($contents) !== '') {
                return $contents;
            }
        }

        $inline = is_string($inline) ? trim($inline) : '';

        // Allow literal "\n" escapes from single-line .env values.
        return $inline !== '' ? str_replace('\n', "\n", $inline) : null;
    }
}
