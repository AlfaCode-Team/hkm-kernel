<?php

declare(strict_types=1);

namespace Plugins\Auth\Application\Auth;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use Plugins\Auth\Application\Ports\Authenticatable;
use Plugins\Auth\Application\Ports\GuardContext;
use Plugins\Auth\Application\Ports\GuardDriver;
use Plugins\Auth\Application\Ports\StatefulGuard;
use Plugins\Auth\Application\Ports\UserProvider;

/**
 * AuthManager — request-scoped registry that manages named user PROVIDERS and
 * guard DRIVERS, the GDA-native rework of the old __DEV__ AuthManager.
 *
 * Differences from the Laravel-style original: no global `auth.` alias, no
 * `kernel()`/`config()` reach-ins (config + collaborators are injected), and the
 * kernel Identity stays the security principal (guards resolve an AuthUserProxy
 * that EMITS an Identity). Ergonomics are preserved:
 *
 *   $manager->guard();            // default guard
 *   $manager->guard('api')->user();
 *   $manager->user();             // default guard's user
 *   $manager->provider('users');  // a named UserProvider
 *
 * "Scan drivers": driver classes under Infrastructure/Auth/Drivers implementing
 * GuardDriver are filesystem-scanned once per process and keyed by driverName().
 * (This is a deliberate, documented exception to the GDA "nothing auto-discovered
 * at runtime" rule — the scan is boot-time and cached, never on the hot path.)
 */
final class AuthManager
{
    /** @var array<string, class-string<GuardDriver>>|null process-cached driver map */
    private static ?array $driverMap = null;

    /** @var array<string, GuardAccessor> request-scoped guard cache */
    private array $guards = [];

    /** @var array<string, UserProvider> request-scoped provider cache */
    private array $providers = [];

    /** Set by setRequest() before resolving guards (Request is not container-bound). */
    private ?Request $request = null;

    /** @var array<string, \Closure(?Request,string,array):GuardAccessor> custom guard creators */
    private array $customGuardCreators = [];

    /** @var array<string, \Closure(string):UserProvider> custom provider creators */
    private array $customProviderCreators = [];

    /** @var \Closure(?string):?Authenticatable|null shared user resolver override */
    private ?\Closure $userResolver = null;

    /**
     * @param array<string, mixed>            $config          auth_config()
     * @param \Closure(string): ?UserProvider $providerFactory builds a named provider
     * @param \Closure(string,UserProvider,Request): ?StatefulGuard $statefulFactory
     *        builds the WRITE-side guard (attempt/login/logout) for stateful
     *        drivers — wired by the Provider with the module's collaborators.
     */
    public function __construct(
        private readonly array $config,
        private readonly \Closure $providerFactory,
        private readonly ?SessionPort $session = null,
        private readonly ?\Plugins\Auth\API\Contracts\AuthServiceContract $auth = null,
        private readonly ?\Closure $statefulFactory = null,
        private readonly ?\Plugins\Auth\API\Contracts\RefreshTokenServiceContract $refreshTokens = null,
        private readonly int $accessTtl = 3600,
    ) {}

    /**
     * Mint a JWT access token for a user via AuthService. Port of the old
     * AuthManager::issueToken(). Requires the AuthServiceContract to be wired.
     *
     * @param array{roles?:list<string>,permissions?:list<string>,tnt?:string} $claims
     */
    public function issueToken(string $userId, array $claims = [], int $ttlSeconds = 3600): string
    {
        if ($this->auth === null) {
            throw new ServiceException('AuthManager has no AuthService — cannot issue tokens.', layer: 'service.auth');
        }

        return $this->auth->issueJwt($userId, $claims, $ttlSeconds);
    }

    /**
     * Mint the full mobile/API credential pair for an ALREADY-VERIFIED user:
     * a short-lived access JWT + a revocable refresh token. The single front-door
     * call for stateless issuance (mobile login/register), so callers never touch
     * AuthService / RefreshTokenService directly.
     *
     * @param array{roles?:list<string>,permissions?:list<string>,tnt?:string} $claims
     * @return array{accessToken:string,tokenType:string,expiresAt:int,refreshToken:string,refreshExpiresAt:string}
     */
    public function issueTokenPair(string $userId, array $claims = [], ?string $device = null, ?string $ip = null): array
    {
        if ($this->auth === null || $this->refreshTokens === null) {
            throw new ServiceException(
                'AuthManager cannot issue a token pair — AuthService/RefreshTokenService not wired.',
                layer: 'service.auth',
            );
        }

        $accessToken = $this->auth->issueJwt($userId, $claims, $this->accessTtl);
        $refresh     = $this->refreshTokens->issue($userId, device: $device, ip: $ip);

        return [
            'accessToken'      => $accessToken,
            'tokenType'        => 'Bearer',
            'expiresAt'        => time() + $this->accessTtl,
            'refreshToken'     => $refresh->token,
            'refreshExpiresAt' => $refresh->expiresAt,
        ];
    }

    /**
     * Bind the active request (the container-bearing one) and reset the guard
     * cache. Called once per request by the controller concern; resetting the
     * cache keeps a reused instance Swoole-safe.
     */
    public function setRequest(Request $request): self
    {
        $this->request = $request;
        $this->guards  = [];

        return $this;
    }

    // ── Guards ────────────────────────────────────────────────────────────────

    public function guard(?string $name = null): GuardAccessor
    {
        $name ??= $this->defaultGuard();

        return $this->guards[$name] ??= $this->buildGuard($name);
    }

    /** The default guard's current user. */
    public function user(?string $name = null): ?Authenticatable
    {
        return $this->guard($name)->user();
    }

    public function check(?string $name = null): bool
    {
        return $this->guard($name)->check();
    }

    public function id(?string $name = null): string
    {
        return $this->guard($name)->id();
    }

    // ── Extension (custom guards / providers) ────────────────────────────────────

    /**
     * Register a custom guard creator. Takes precedence over config/scanned
     * drivers for that guard name. Port of the old AuthManager::extend().
     *
     * @param \Closure(?Request,string,array):GuardAccessor $creator
     */
    public function extend(string $name, \Closure $creator): self
    {
        $this->customGuardCreators[$name] = $creator;
        unset($this->guards[$name]);

        return $this;
    }

    /**
     * Register a custom user-provider creator. Port of the old
     * AuthManager::provider(name, callback).
     *
     * @param \Closure(string):UserProvider $creator
     */
    public function extendProvider(string $name, \Closure $creator): self
    {
        $this->customProviderCreators[$name] = $creator;
        unset($this->providers[$name]);

        return $this;
    }

    /** Shared resolver returning the current user for a guard (Gate/Request use it). */
    public function userResolver(): \Closure
    {
        return $this->userResolver ??= fn(?string $guard = null): ?Authenticatable => $this->guard($guard)->user();
    }

    /** Override the shared user resolver. Port of resolveUsersUsing(). */
    public function resolveUsersUsing(\Closure $resolver): self
    {
        $this->userResolver = $resolver;

        return $this;
    }

    /** Drop cached guard instances — call at the start of a Swoole request cycle. */
    public function forgetGuards(): self
    {
        $this->guards = [];

        return $this; 
    }

    /** Forward unknown calls (check/user/id/identity/...) to the default guard. */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->guard()->{$method}(...$parameters);
    }

    // ── Providers ───────────────────────────────────────────────────────────────

    /** Resolve a named UserProvider (default provider when null). */
    public function provider(?string $name = null): UserProvider
    {
        $name ??= $this->defaultProvider();

        $provider = $this->providers[$name] ??= isset($this->customProviderCreators[$name])
            ? ($this->customProviderCreators[$name])($name)
            : ($this->providerFactory)($name);

        if ($provider === null) {
            throw new ServiceException("Auth provider [{$name}] is not configured.", layer: 'service.auth');
        }

        return $provider;
    }

    // ── Internals ──────────────────────────────────────────────────────────────

    private function buildGuard(string $name): GuardAccessor
    {
        if ($this->request === null) {
            throw new ServiceException('AuthManager has no request — call setRequest() first.', layer: 'service.auth');
        }

        // Custom creators registered via extend() take precedence.
        if (isset($this->customGuardCreators[$name])) {
            return ($this->customGuardCreators[$name])($this->request, $name, $this->config['guards'][$name] ?? []);
        }

        $guardConfig = $this->config['guards'][$name] ?? null;
        if (!\is_array($guardConfig)) {
            throw new ServiceException("Auth guard [{$name}] is not defined in config/auth.php.", layer: 'service.auth');
        }

        $driverName = (string) ($guardConfig['driver'] ?? '');
        $driverClass = self::drivers()[$driverName] ?? null;
        if ($driverClass === null) {
            throw new ServiceException("Auth driver [{$driverName}] for guard [{$name}] was not found.", layer: 'service.auth');
        }

        $provider = $this->provider($guardConfig['provider'] ?? null);
        $context  = new GuardContext($provider, $this->session);

        /** @var GuardDriver $driver */
        $driver = new $driverClass();

        // Stateful drivers also get the WRITE-side guard so the old ergonomics
        // hold: $manager->guard('web')->attempt($credentials, $remember).
        $stateful = $driverName === 'session' && $this->statefulFactory !== null
            ? ($this->statefulFactory)($name, $provider, $this->request)
            : null;

        return new GuardAccessor($name, $driver, $context, $this->request, $stateful);
    }
 
    private function defaultGuard(): string
    {
        return (string) ($this->config['defaults']['guard'] ?? 'web');
    }

    private function defaultProvider(): string
    {
        return (string) ($this->config['defaults']['provider'] ?? 'users');
    }

    /**
     * Filesystem-scan the Drivers directory once per process, mapping each
     * GuardDriver implementation to its driverName(). Boot-time + cached.
     *
     * @return array<string, class-string<GuardDriver>>
     */
    public static function drivers(): array
    {
        if (self::$driverMap !== null) {
            return self::$driverMap;
        }

        $map = [];
        $dir = \dirname(__DIR__, 2) . '/Infrastructure/Auth/Drivers';

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $class = 'Plugins\\Auth\\Infrastructure\\Auth\\Drivers\\' . basename($file, '.php');

            if (!class_exists($class)) {
                continue;
            }
            if (!is_subclass_of($class, GuardDriver::class)) {
                continue; // traits/abstracts (e.g. ResolvesFromVerdict) are skipped
            }

            /** @var class-string<GuardDriver> $class */
            $map[$class::driverName()] = $class;
        }

        return self::$driverMap = $map;
    }

    /** Test seam — forget the scanned driver map (never call on the hot path). */
    public static function flushDriverCache(): void
    {
        self::$driverMap = null;
    }
}
