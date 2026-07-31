<?php

declare(strict_types=1);

namespace Plugins\OAuth2;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\View\API\Contracts\ViewRendererContract;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\OAuth2\Application\Ports\AuthCodeStore;
use Plugins\OAuth2\Application\Ports\ClientStore;
use Plugins\OAuth2\Application\Ports\DeviceCodeStore;
use Plugins\OAuth2\Application\Ports\RefreshTokenStore;
use Plugins\OAuth2\Application\Ports\ResourceOwnerVerifier;
use Plugins\OAuth2\Application\Ports\ScopeStore;
use Plugins\OAuth2\Application\Ports\UserInfoProvider;
use Plugins\OAuth2\Application\Services\AuthorizationService;
use Plugins\OAuth2\Application\Services\DeviceService;
use Plugins\OAuth2\Application\Services\IntrospectionService;
use Plugins\OAuth2\Application\Services\ScopeValidator;
use Plugins\OAuth2\Application\Services\TokenIssuer;
use Plugins\OAuth2\Application\Services\TokenService;
use Plugins\OAuth2\Infrastructure\Http\Controllers\AuthorizationController;
use Plugins\OAuth2\Infrastructure\Http\Controllers\DeviceController;
use Plugins\OAuth2\Infrastructure\Http\Controllers\DeviceVerificationController;
use Plugins\OAuth2\Infrastructure\Http\Controllers\DiscoveryController;
use Plugins\OAuth2\Infrastructure\Http\Controllers\IntrospectionController;
use Plugins\OAuth2\Infrastructure\Http\Controllers\JwksController;
use Plugins\OAuth2\Infrastructure\Http\Controllers\TokenController;
use Plugins\OAuth2\Infrastructure\Http\Controllers\UserInfoController;
use Plugins\OAuth2\Infrastructure\Identity\SubjectUserInfoProvider;
use Plugins\OAuth2\Infrastructure\Identity\UserResourceOwnerVerifier;
use Plugins\OAuth2\Infrastructure\Persistence\AuthCodeRepository;
use Plugins\OAuth2\Infrastructure\Persistence\ClientRepository;
use Plugins\OAuth2\Infrastructure\Persistence\DeviceCodeRepository;
use Plugins\OAuth2\Infrastructure\Persistence\RefreshTokenRepository;
use Plugins\OAuth2\Infrastructure\Persistence\ScopeRepository;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * OAuth2 plugin — a native, dependency-free OAuth 2.1 authorization server.
 *
 * Grants: authorization_code (+PKCE), client_credentials, refresh_token,
 * password. Access tokens are JWTs signed with the SAME keys the platform's
 * JwtAuthLayer verifies, so issued tokens authenticate against the existing
 * SecurityGateway with no extra wiring. Every repository binds the per-request
 * DatabasePort, so the server tables (clients, codes, refresh tokens, scopes,
 * device codes) follow the request connection — TENANT-scoped under Tenancy,
 * central in a single-DB deployment. The CLI has no request, so its commands
 * take a --tenant option (see the deferred registration in boot()).
 *
 * Endpoints: /oauth/authorize, /oauth/token, /oauth/introspect, /oauth/revoke,
 * /oauth/jwks, /.well-known/oauth-authorization-server.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'oauth.server';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return ['database.management', 'crypto.services', 'user.management', 'view.rendering'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [
            ClientStore::class,
            \Plugins\OAuth2\Application\Ports\AuthorizationFlow::class,
        ];
    }

    public function register(ModuleContainer $container): void
    {
        // ── persistence (central connection — control plane) ──────────────────
        $container->bind(ClientStore::class, static fn(ModuleContainer $c) =>
            new ClientRepository($c->make(DatabasePort::class)));
        $container->bindInternal(AuthCodeStore::class, static fn(ModuleContainer $c) =>
            new AuthCodeRepository($c->make(DatabasePort::class)));
        $container->bindInternal(RefreshTokenStore::class, static fn(ModuleContainer $c) =>
            new RefreshTokenRepository($c->make(DatabasePort::class)));
        $container->bindInternal(ScopeStore::class, static fn(ModuleContainer $c) =>
            new ScopeRepository($c->make(DatabasePort::class   )));
        $container->bindInternal(DeviceCodeStore::class, static fn(ModuleContainer $c) =>
            new DeviceCodeRepository($c->make(DatabasePort::class  )));

        $container->bindInternal(ScopeValidator::class, static fn(ModuleContainer $c) =>
            new ScopeValidator($c->make(ScopeStore::class)));

        // ── token signing (shares the platform JWT keys) ──────────────────────
        $container->bindInternal(TokenIssuer::class, static fn(ModuleContainer $c) =>
            new TokenIssuer(
                algo:       env('JWT_ALGO') ?: 'HS256',
                secret:     env('JWT_SECRET') ?: '',
                privateKey: self::readKey(env('JWT_PRIVATE_KEY'), env('JWT_PRIVATE_KEY_FILE')),
                issuer:     env('JWT_ISSUER') ?: null,
                keyId:      env('JWT_KID') ?: null,
                accessTtl:  (int) (env('OAUTH_ACCESS_TTL') ?: 3600),
                // Resource-server audience so access tokens pass JwtAuthLayer's
                // audience check; defaults to the platform JWT_AUDIENCE.
                audience:   env('OAUTH_TOKEN_AUDIENCE') ?: (env('JWT_AUDIENCE') ?: null),
            ));

        // ── resource-owner verifier (password grant) ──────────────────────────
        $container->bindInternal(ResourceOwnerVerifier::class, static function (ModuleContainer $c): ?ResourceOwnerVerifier {
            return $c->has(UserServiceContract::class)
                ? new UserResourceOwnerVerifier($c->make(UserServiceContract::class))
                : null;
        });

        // ── services ──────────────────────────────────────────────────────────
        $container->bindInternal(AuthorizationService::class, static fn(ModuleContainer $c) =>
            new AuthorizationService(
                $c->make(ClientStore::class),
                $c->make(AuthCodeStore::class),
                $c->make(ScopeValidator::class),
                (int) (env('OAUTH_CODE_TTL') ?: 60),
            ));

        // Published port: headless code issuance for first-party authenticated
        // flows (Auth's mobile login/register — old __DEV__ PKCE-without-browser).
        $container->bind(\Plugins\OAuth2\Application\Ports\AuthorizationFlow::class,
            static fn(ModuleContainer $c) => $c->make(AuthorizationService::class));

        $container->bindInternal(TokenService::class, static fn(ModuleContainer $c) =>
            new TokenService(
                $c->make(ClientStore::class),
                $c->make(AuthCodeStore::class),
                $c->make(RefreshTokenStore::class),
                $c->make(ScopeValidator::class),
                $c->make(TokenIssuer::class),
                $c->make(HashingPort::class),
                $c->make(ResourceOwnerVerifier::class),
                (int) (env('OAUTH_REFRESH_TTL') ?: 1209600),
                $c->make(DeviceCodeStore::class),
            ));

        $container->bindInternal(DeviceService::class, static fn(ModuleContainer $c) =>
            new DeviceService(
                $c->make(ClientStore::class),
                $c->make(DeviceCodeStore::class),
                $c->make(ScopeValidator::class),
                (int) (env('OAUTH_DEVICE_TTL') ?: 600),
                (int) (env('OAUTH_DEVICE_INTERVAL') ?: 5),
            ));

        // UserInfo (OIDC). Default returns `sub` only; a project may override the
        // UserInfoProvider binding with a richer, scope-aware implementation.
        $container->bindInternal(UserInfoProvider::class, static fn(ModuleContainer $c) =>
            new SubjectUserInfoProvider());

        $container->bindInternal(IntrospectionService::class, static fn(ModuleContainer $c) =>
            new IntrospectionService(
                $c->make(RefreshTokenStore::class),
                $c->make(TokenIssuer::class),
                self::verifyKey(),
                env('JWT_ALGO') ?: 'HS256',
                $c->has(CachePort::class) ? $c->make(CachePort::class) : null,
            ));

        // ── controllers ───────────────────────────────────────────────────────
        $container->bindInternal(AuthorizationController::class, static fn(ModuleContainer $c) =>
            new AuthorizationController(
                $c->make(ViewRendererContract::class),
                $c->make(AuthorizationService::class),
                $c->make(SessionPort::class),
                $c->make(\Plugins\Pageflow\Http\PageflowResponder::class),
            ));
        $container->bindInternal(TokenController::class, static fn(ModuleContainer $c) =>
            new TokenController($c->make(TokenService::class)));
        $container->bindInternal(IntrospectionController::class, static fn(ModuleContainer $c) =>
            new IntrospectionController(
                $c->make(IntrospectionService::class),
                $c->make(ClientStore::class),
                $c->make(HashingPort::class),
            ));
        $container->bindInternal(JwksController::class, static fn(ModuleContainer $c) =>
            new JwksController(
                env('JWT_ALGO') ?: 'HS256',
                self::readKey(env('JWT_PUBLIC_KEY'), env('JWT_PUBLIC_KEY_FILE')),
                env('JWT_KID') ?: null,
            ));
        $container->bindInternal(DiscoveryController::class, static fn(ModuleContainer $c) =>
            new DiscoveryController($c->make(ScopeStore::class)));
        $container->bindInternal(DeviceController::class, static fn(ModuleContainer $c) =>
            new DeviceController($c->make(DeviceService::class)));
        $container->bindInternal(DeviceVerificationController::class, static fn(ModuleContainer $c) =>
            new DeviceVerificationController(
                $c->make(ViewRendererContract::class),
                $c->make(DeviceCodeStore::class),
            ));
        $container->bindInternal(UserInfoController::class, static fn(ModuleContainer $c) =>
            new UserInfoController($c->make(UserInfoProvider::class)));

        // Scope catalogue (descriptions) + the /oauth/scopes endpoint.
        $container->bindInternal(\Plugins\OAuth2\Application\Services\ScopeRegistry::class, static fn(ModuleContainer $c) =>
            new \Plugins\OAuth2\Application\Services\ScopeRegistry($c->make(ScopeStore::class)));
        $container->bindInternal(\Plugins\OAuth2\Infrastructure\Http\Controllers\ScopeController::class, static fn(ModuleContainer $c) =>
            new \Plugins\OAuth2\Infrastructure\Http\Controllers\ScopeController(
                $c->make(\Plugins\OAuth2\Application\Services\ScopeRegistry::class)));

        // Self-service management API (clients + authorized tokens), owner-scoped.
        $container->bindInternal(\Plugins\OAuth2\Infrastructure\Http\Controllers\ClientController::class, static fn(ModuleContainer $c) =>
            new \Plugins\OAuth2\Infrastructure\Http\Controllers\ClientController(
                $c->make(ClientStore::class),
                $c->make(\AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HashingPort::class),
            ));
        $container->bindInternal(\Plugins\OAuth2\Infrastructure\Http\Controllers\AuthorizedTokenController::class, static fn(ModuleContainer $c) =>
            new \Plugins\OAuth2\Infrastructure\Http\Controllers\AuthorizedTokenController(
                $c->make(RefreshTokenStore::class)));

        // Tenant-wide ADMIN API (all clients / scopes / authorized grants).
        // Gated on OAUTH_ADMIN_ROLE / OAUTH_ADMIN_USERS inside the controller.
        $container->bindInternal(\Plugins\OAuth2\Infrastructure\Http\Controllers\AdminController::class, static fn(ModuleContainer $c) =>
            new \Plugins\OAuth2\Infrastructure\Http\Controllers\AdminController(
                $c->make(ClientStore::class),
                $c->make(ScopeStore::class),
                $c->make(RefreshTokenStore::class),
                $c->make(HashingPort::class),
                $c->make(UserServiceContract::class),
            ));

        // Admin dashboard (GET /oauth/admin) — rendered via Pageflow (component
        // "OAuth2/Admin"); its page fetches the JSON admin API same-origin.
        $container->bindInternal(\Plugins\OAuth2\Infrastructure\Http\Controllers\AdminUiController::class, static fn(ModuleContainer $c) =>
            new \Plugins\OAuth2\Infrastructure\Http\Controllers\AdminUiController(
                $c->make(\Plugins\Pageflow\Http\PageflowResponder::class)));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // OAuth2 data is tenant-scoped, but the CLI has no Host to route from —
        // so the commands take a --tenant option. Build a scoped container on the
        // CLI path (deferred so HTTP/worker builds never pay for it) and hand each
        // command a TenantConnections resolver (central by default; a named tenant
        // with --tenant, when the Tenancy plugin is present).
        $cli->defer(static function (CliPipeline $cli): void {
            $c = new ModuleContainer($cli->container());
            $c->setScope('database.management');
            (new \Plugins\Database\Provider())->register($c);
            $c->setScope((new \Plugins\Crypto\Provider())->solves());
            (new \Plugins\Crypto\Provider())->register($c);

            $connections = self::tenantConnections($c);
            $hasher      = $c->make(HashingPort::class);

            $cli->command(new \Plugins\OAuth2\Infrastructure\Cli\CreateClientCommand($connections, $hasher));
            $cli->command(new \Plugins\OAuth2\Infrastructure\Cli\ListClientsCommand($connections));
            $cli->command(new \Plugins\OAuth2\Infrastructure\Cli\RevokeClientCommand($connections));
            $cli->command(new \Plugins\OAuth2\Infrastructure\Cli\RotateClientSecretCommand($connections, $hasher));
            $cli->command(new \Plugins\OAuth2\Infrastructure\Cli\PruneCommand($connections));
        });
    }

    /**
     * Build the CLI connection resolver: the central/default connection, plus the
     * OPTIONAL Tenancy registry + resolver so `--tenant=<id|slug|db>` can target a
     * tenant database. Tenancy is registered into the scoped container only when
     * the plugin is present; otherwise the commands run central-only.
     */
    private static function tenantConnections(ModuleContainer $c): \Plugins\OAuth2\Infrastructure\Cli\TenantConnections
    {
        $central  = self::central($c);
        $registry = null;
        $resolver = null;

        if (class_exists(\Plugins\Tenancy\Provider::class)) {
            try {
                // Tenancy's services depend on Audit (audit.trail); register it first.
                $c->setScope((new \Plugins\Audit\Provider())->solves());
                (new \Plugins\Audit\Provider())->register($c);
                $c->setScope('tenancy.routing');
                (new \Plugins\Tenancy\Provider())->register($c);

                $registry = $c->make(\Plugins\Tenancy\API\Contracts\TenantRegistryContract::class);
                $resolver = $c->make(\Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract::class);
            } catch (\Throwable) {
                $registry = null;
                $resolver = null;
            }
        }

        return new \Plugins\OAuth2\Infrastructure\Cli\TenantConnections($central, $registry, $resolver);
    }

    private static function central(ModuleContainer $c): DatabasePort
    {
        return $c->make(DatabaseConnectionManagerContract::class)->default();
    }

    /** The key used to VERIFY access-token signatures (public key for RS/ES/PS, secret for HS). */
    private static function verifyKey(): string
    {
        $algo = env('JWT_ALGO') ?: 'HS256';
        $c    = $algo[0] ?? 'H';
        if ($c === 'R' || $c === 'E' || $c === 'P') {
            return (string) self::readKey(env('JWT_PUBLIC_KEY'), env('JWT_PUBLIC_KEY_FILE'));
        }

        return env('JWT_SECRET') ?: '';
    }

    /** Read a PEM key from an inline env value or a file path (file preferred). */
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

        return $inline !== '' ? str_replace('\n', "\n", $inline) : null;
    }
}
