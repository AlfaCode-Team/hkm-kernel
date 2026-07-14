<?php

declare(strict_types=1);

namespace Plugins\SocialAuth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\API\Contracts\RefreshTokenServiceContract;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\SocialAuth\API\Contracts\SocialAuthServiceContract;
use Plugins\SocialAuth\Application\Services\SocialAuthService;
use Plugins\SocialAuth\Application\Services\SocialLoginService;
use Plugins\SocialAuth\Infrastructure\Gateways\ProviderTokenGateway;
use Plugins\SocialAuth\Infrastructure\Http\Controllers\SocialAuthController;
use Plugins\SocialAuth\Infrastructure\Persistence\SocialIdentityRepository;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * SocialAuth plugin — OAuth1/OAuth2 social login (ported Socialite engine).
 *
 * The Socialite engine lives untouched under Socialite/; this Provider wires
 * it into GDA by reading provider credentials from env and exposing only
 * SocialAuthServiceContract.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'auth.social';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        // Mirrors module.json "requires": the login bridge maps provider
        // profiles onto central users and issues platform credentials via the
        // Auth plugin's published contracts; token sign-in verifies against the
        // provider over HttpClientPort.
        return [
            DatabaseConnectionManagerContract::class,
            UserServiceContract::class,
            AuthServiceContract::class,
            HttpClientPort::class,
        ];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [SocialAuthServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bind(SocialAuthServiceContract::class, static function () {
            return new SocialAuthService(
                config: self::buildConfig(),
                baseUrl: env('SOCIAL_AUTH_BASE_URL') ?: '',
            );
        });

        // Provider-account → user links (central — control-plane table).
        $container->bindInternal(SocialIdentityRepository::class, static fn(ModuleContainer $c) =>
            new SocialIdentityRepository(
                $c->make(DatabasePort::class),
            )
        );

        // Find-or-create bridge onto the central identity store.
        $container->bindInternal(SocialLoginService::class, static fn(ModuleContainer $c) =>
            new SocialLoginService(
                $c->make(SocialIdentityRepository::class),
                $c->make(UserServiceContract::class),
            )
        );

        // Native-SDK token verification (google access_token/id_token, apple
        // identity_token against Apple's JWKS).
        $container->bindInternal(ProviderTokenGateway::class, static fn(ModuleContainer $c) =>
            new ProviderTokenGateway(
                $c->make(HttpClientPort::class),
                googleClientId: env('GOOGLE_CLIENT_ID') ?: '',
                appleClientId:  env('APPLE_CLIENT_ID') ?: '',
            )
        );

        $container->bindInternal(SocialAuthController::class, static fn(ModuleContainer $c) =>
            new SocialAuthController(
                social:          $c->make(SocialAuthServiceContract::class),
                login:           $c->make(SocialLoginService::class),
                tokens:          $c->make(ProviderTokenGateway::class),
                auth:            $c->make(AuthServiceContract::class),
                refreshTokens:   $c->make(RefreshTokenServiceContract::class),
                session:         $c->make(SessionPort::class),
                accessTtl:       (int) (env('AUTH_MOBILE_ACCESS_TTL') ?: 3600),
                successRedirect: env('SOCIAL_AUTH_SUCCESS_REDIRECT') ?: '/',
            )
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // No hooks: drivers are invoked from a controller/service on demand.
    }

    /**
     * Assemble the Socialite-shaped config from env vars.
     *
     * @return array<string,mixed>
     */
    private static function buildConfig(): array
    {
        $services = [];
        foreach (['github', 'google', 'facebook', 'gitlab', 'bitbucket', 'linkedin', 'slack', 'x'] as $driver) {
            $prefix = strtoupper($driver);
            $id = env("{$prefix}_CLIENT_ID");
            if ($id === false || $id === '') {
                continue;
            }
            $services[$driver] = [
                'client_id'     => $id,
                'client_secret' => env("{$prefix}_CLIENT_SECRET") ?: '',
                'redirect'      => env("{$prefix}_REDIRECT_URI") ?: '',
            ];
        }

        return ['services' => $services];
    }
}
