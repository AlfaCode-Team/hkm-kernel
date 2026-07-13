<?php

declare(strict_types=1);

namespace Plugins\Auth\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\SessionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Auth\API\Contracts\AuthServiceContract;
use Plugins\Auth\Application\Services\AuthService;
use Plugins\Auth\Application\Services\DeviceSessionService;
use Plugins\Auth\Domain\ValueObjects\Recaller;
use Plugins\Cookie\Infrastructure\CookieJar;
use Plugins\User\API\Contracts\UserServiceContract;

/**
 * SessionAuthStage — stateful authentication for browser (web + AJAX) requests.
 *
 * Why a stage and not a SecurityLayer: the session is only opened at
 * `after.load` (StartSessionStage, priority 20), which runs AFTER the
 * SecurityGateway. So token auth (JWT / PAT) attaches an Identity in the gateway,
 * and SESSION auth attaches one here — this stage is registered at `after.load`
 * with a priority just above StartSessionStage so the session is already loaded,
 * and before RouteFilterStage so the `auth` filter sees the resulting Identity.
 *
 * Precedence: a request that already carries a token-derived Identity (Bearer
 * JWT/PAT) is left untouched — the explicit credential wins. Anonymous requests
 * with a logged-in session get a `tokenType: 'session'` Identity. Everything else
 * stays guest so public routes keep working.
 */
final class SessionAuthStage implements HttpStageContract
{
    /**
     * Register right after StartSessionStage (20) so the session is loaded, and
     * before TenantContextStage (23) so a session-scoped tenant can route the DB.
     */
    public const PRIORITY = 22;

    /** Encrypted "remember me" cookie name and its lifetime (30 days). */
    public const RECALLER_COOKIE = 'remember_web';
    public const RECALLER_TTL    = 60 * 60 * 24 * 30;

    public function handle(Request $request, callable $next): Response
    {
        // A token already authenticated this request — do not override it.
        $existing = $request->identity();
        if ($existing !== null && !$existing->isGuest()) {
            return $next($request);
        }

        $container = $request->container();
        if ($container === null || !$container->has(SessionPort::class)) {
            return $next($request);
        }

        $session = $container->make(SessionPort::class);
        if (!$session instanceof SessionPort) {
            return $next($request);
        }

        $userId = (string) $session->get(AuthService::SESSION_USER, '');
        if ($userId === '') {
            // No live session — try to resurrect one from a "remember me" cookie.
            $resurrected = $this->fromRecaller($request, $container, $session);
            if ($resurrected !== null) {
                $request = $resurrected;
            }

            return $next($request);
        }

        // Fingerprint + device-session validation (old __DEV__ semantics): a
        // request that can't reproduce the login fingerprint, or whose server-
        // side session row was revoked/expired, loses the session outright.
        if ($container->has(DeviceSessionService::class)) {
            $devices = $container->make(DeviceSessionService::class);
            if ($devices instanceof DeviceSessionService && !$devices->verify($session, $request)) {
                $devices->teardown($session);
                $session->invalidate();

                return $next($request); // continue as guest
            }
        }

        $identity = new Identity(
            userId:      $userId,
            tenantId:    (string) $session->get(AuthService::SESSION_TENANT, ''),
            roles:       $this->stringList($session->get(AuthService::SESSION_ROLES, [])),
            permissions: $this->stringList($session->get(AuthService::SESSION_PERMISSIONS, [])),
            tokenType:   'session',
        );

        return $next($this->attach($request, $container, $identity));
    }

    /**
     * Attach a session Identity to BOTH the request and the request-scoped
     * container.
     *
     * Why the container too: services inject Identity from the ModuleContainer,
     * which OnDemandLoader binds at LoadStage from the PRE-auth (guest) request —
     * that runs before this after.load stage. Setting it only on the request
     * would authenticate route filters (the `auth` alias) but leave the service
     * layer seeing a guest, so service-level permission checks would wrongly
     * fail. Rebinding here is safe: no service resolves Identity until
     * ExecuteStage, which runs after this stage. Token auth is unaffected — it
     * attaches its Identity in the SecurityGateway, before LoadStage, so the
     * container already holds the correct one.
     */
    private function attach(Request $request, $container, Identity $identity): Request
    {
        $container->instance(Identity::class, $identity);

        return $request->withIdentity($identity);
    }

    /**
     * Validate a "remember me" recaller cookie and, on success, re-open a full
     * session login. Returns the request carrying the rebuilt Identity, or null
     * when there is no valid recaller (request left untouched, stays guest).
     *
     * Security: the token is matched by its stored hash, and on EVERY successful
     * use it is rotated (a fresh token + cookie) so a stolen cookie is a
     * single-use window — the old __DEV__ remember-me guarantee, GDA-native.
     */
    private function fromRecaller(Request $request, $container, SessionPort $session): ?Request
    {
        if (!$container->has(CookieJar::class)
            || !$container->has(UserServiceContract::class)
            || !$container->has(AuthServiceContract::class)) {
            return null;
        }

        $cookies = $container->make(CookieJar::class);
        $raw     = $cookies instanceof CookieJar ? $cookies->read($request, self::RECALLER_COOKIE) : null;
        if ($raw === null || $raw === '') {
            return null;
        }

        $recaller = new Recaller($raw);
        if (!$recaller->valid()) {
            return null;
        }

        $users = $container->make(UserServiceContract::class);
        $user  = $users instanceof UserServiceContract ? $users->findByRememberToken($recaller->token()) : null;

        // The cookie's id must match the token owner — defence against a
        // mismatched/forged pairing.
        if ($user === null || $user->id !== $recaller->id()) {
            return null;
        }

        // Re-establish the stateful session (rotates the session id) and rotate
        // the remember token + cookie so this recaller can't be replayed.
        $auth = $container->make(AuthServiceContract::class);
        if ($auth instanceof AuthServiceContract) {
            $auth->startSession($session, $user->id);
        } else {
            $session->put(AuthService::SESSION_USER, $user->id);
        }

        $fresh = $users->cycleRememberToken($user->id);
        $cookies->queue(
            self::RECALLER_COOKIE,
            Recaller::make($user->id, $fresh)->value(),
            maxAge: self::RECALLER_TTL,
        );

        return $this->attach($request, $container, new Identity(
            userId:      $user->id,
            tenantId:    '',
            roles:       [],
            permissions: [],
            tokenType:   'session',
        ));
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }
}
