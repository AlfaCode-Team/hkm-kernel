<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use Plugins\Cookie\Infrastructure\CookieJar;
use Plugins\Database\Exceptions\ConnectionException;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\Infrastructure\Http\Identification\TenantIdentifier;
use Plugins\Tenancy\Domain\Exceptions\TenantUnavailableException;
use Plugins\Tenancy\Domain\Exceptions\UnknownTenantException;
use Plugins\Tenancy\Infrastructure\TenantConnectionResolver;

/**
 * TenantContextStage — binds the per-request tenant database.
 *
 * Registered at `after.load`, so the request's ModuleContainer already exists.
 * It asks the configured {@see TenantIdentifier} which tenant this request
 * belongs to — either the authenticated Identity (`Identity.tenantId`, the SaaS
 * model) or the Host sub-domain (the storefront/domain model), per TENANCY_MODE
 * — resolves the isolated DatabasePort, and REBINDS DatabasePort in the request
 * container so every repository resolved downstream (ExecuteStage) transparently
 * talks to the tenant database.
 *
 * Routing decision — STRICT, no unscoped passthrough:
 *   - No tenant (cookie empty AND identifier returns '' or throws) -> 404.
 *     Every host must be assigned to a tenant; a request that cannot be scoped
 *     is never served the central DatabasePort. Control-plane code that needs
 *     central pins it explicitly (ConnectionManager default), not via this stage.
 *   - Tenant present -> rebind, or fail closed with a clean status. There is no
 *     silent fallback to another tenant or to central.
 *
 * This stage is purely connection routing; tenant IDENTIFICATION is the
 * identifier's job and tenant existence is the registry's.
 */
final class TenantContextStage implements HttpStageContract
{
    /** Encrypted, user-bound cookie remembering the active tenant (a HINT, never authority). */
    private const COOKIE = 'hkm_tnat_v01';

    /**
     * The collaborators are request-scoped (bound in the module's ModuleContainer
     * by Provider::register), but this stage is an app-lifetime after.load hook
     * resolved once from the CoreContainer. So they are OPTIONAL here and lazily
     * resolved from the per-request container when not explicitly injected
     * (explicit injection is used by tests / a CoreContainer binding).
     */
    public function __construct(
        private readonly ?TenantConnectionResolverContract $resolver = null,
        private readonly ?TenantIdentifier $identifier = null,
    ) {
    }

    public function handle(Request $request, callable $next): Response
    {
        $container = $request->container();

        // This stage is an always-on after.load hook, but its collaborators are
        // bound only when Tenancy is in the request's dependency graph. When the
        // route never pulled Tenancy in, TenantIdentifier is unbound — make()
        // would THROW (EntryNotFoundException), not return null. Probe with has()
        // so a non-tenant request cleanly no-ops instead of erroring.
        $identifier = $this->identifier
            ?? ($container?->has(TenantIdentifier::class) ? $container->make(TenantIdentifier::class) : null);

        // No identifier bound means Tenancy is not in this request's dependency
        // graph — fail loudly instead of silently skipping tenant resolution.
        // (Register Tenancy as an essential module so it binds on every request.)
        if ($identifier === null) {
            throw new \RuntimeException(
                'TenantContextStage: no TenantIdentifier is bound for this request. '
                . 'Ensure the Tenancy module is loaded — register it as an essential module.'
            );
        }

        $jar = $container->has(CookieJar::class) ? $container->make(CookieJar::class) : null;
        $userId = $request->identity()?->userId ?? '';



        $fromCookie = false;
        $tenantId = $this->rememberedTenant($jar, $request, $userId);
        $fromCookie = $tenantId !== '';

        // The remembered selection (encrypted, principal-bound cookie) is tried
        // first; only when there is no valid hint does the identifier run
        // (JWT `tnt` claim / Host, per TENANCY_MODE). An identifier may throw
        // UnknownTenantException to fail closed on a host it refuses to serve.
        try {
            if ($tenantId === '') {
                $tenantId = $identifier->identify($request);
            }
        } catch (UnknownTenantException) {
            return Response::notFound('Tenant not found.');
        }

        // EVERY request must resolve to a tenant — there is NO unscoped
        // passthrough to the central DatabasePort. A host that is not assigned
        // to a tenant (and a claim-mode request without a tenant claim/cookie)
        // fails closed with a 404 here, so an unknown website pointed at this
        // server is never served anything. Control-plane repositories that need
        // the central connection pin it explicitly via the ConnectionManager
        // default — they do not depend on this stage skipping the rebind.
        if ($tenantId === '') {
            return Response::notFound('Tenant not found.');
        }

        $resolver = $this->resolver ?? $container->make(TenantConnectionResolverContract::class);


        try {
            $db = $resolver->for($tenantId);
        } catch (UnknownTenantException) {
            // A stale hint can point at a deleted tenant — drop it, don't trap the user.
            if ($fromCookie && $jar !== null) {
                $jar->forget(self::COOKIE);
            }
            return Response::notFound('Tenant not found.');
        } catch (TenantUnavailableException $e) {
            return $this->unavailable($e);
        }

        // Override the Database plugin's binding for THIS request only.
        $container->instance(DatabasePort::class, $db);


        // Expose the resolved tenant to controllers without re-identifying it.
        $request = $request->withAttribute('tenant', $tenantId);

        // Expose the resolved tenant id (a scalar) into the container too. Use a
        // closure bind, not instance(): the kernel's ModuleContainer::instance()
        // requires an OBJECT, so binding a bare string there throws a TypeError.
        $container->bind('tenant.current', static fn(): string => $tenantId);

        // Remember the active tenant for next time — encrypted + bound to this
        // user so it cannot be replayed across users. Still a hint: every request
        // re-resolves through the registry/breaker above.
        if ($jar !== null) {
            $this->rememberTenant($jar, $tenantId, $userId);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            // Repositories translate \PDOException → Plugins\Database
            // ConnectionException (and may wrap it again), so the raw vendor
            // exception never reaches this stage. Walk the chain and only feed
            // the breaker on genuine CONNECTIVITY faults — a bad query or a
            // domain error must not trip a healthy tenant's breaker. Then
            // re-throw the original so the error pipeline handles it.
            if ($resolver instanceof TenantConnectionResolver && self::isConnectivityFault($e)) {
                $resolver->recordFailure($tenantId, $e);
            }
            throw $e;
        }

        if ($resolver instanceof TenantConnectionResolver) {
            $resolver->recordSuccess($tenantId);
        }

        return $response;
    }

    /**
     * True when the throwable (or anything in its `previous` chain) is a Database
     * ConnectionException whose operation indicates the connection itself failed
     * — connect, lost connection, or pool acquisition — as opposed to a query
     * that ran fine against a healthy connection.
     */
    private static function isConnectivityFault(\Throwable $e): bool
    {
        for ($cur = $e; $cur !== null; $cur = $cur->getPrevious()) {
            if (
                $cur instanceof ConnectionException
                && \in_array($cur->operation, ['connect', 'connection_lost', 'pool_acquire'], true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read the remembered tenant from the encrypted cookie. Returns '' unless the
     * cookie decrypts cleanly AND was minted for THIS principal — a cookie issued
     * for another account can never be replayed, while a guest-minted cookie
     * ('u' = '') still works for guests so public pages keep their selection.
     */
    private function rememberedTenant(?CookieJar $jar, Request $request, string $userId): string
    {
        if ($jar === null) {
            return '';
        }

        $raw = $jar->read($request, self::COOKIE); // decrypted; null if absent/tampered

        if ($raw === null) {
            return '';
        }

        $data = json_decode($raw, true);

        // Enforce the principal binding: the hint is only honoured by whoever it
        // was minted for. A guest-minted hint ('u' = '') keeps working for
        // guests — public pages don't require login — while a hint minted for
        // one user is never replayed onto another user (or onto a guest after
        // logout). Log-in flips the principal, so a fresh hint is re-minted.
        $mintedFor = is_string($data['u'] ?? null) ? $data['u'] : '';
        if (!is_string($data['t'] ?? null) || $mintedFor !== $userId) {
            return '';
        }

        return $data['t'];
    }

    /** Queue the encrypted, user-bound tenant hint (flushed by QueuedCookiesStage). */
    private function rememberTenant(CookieJar $jar, string $tenantId, string $userId): void
    {
        if ($jar->hasQueued(self::COOKIE)) {
            return; // already written this request
        }

        $jar->queue(
            self::COOKIE,
            json_encode(['t' => $tenantId, 'u' => $userId]),
            60 * 60 * 24 * 30, // 30 days
        );
    }

    private function unavailable(TenantUnavailableException $e): Response
    {
        return match ($e->statusCode) {
            403 => Response::forbidden($e->getMessage()),
            410 => Response::json(['error' => ['code' => $e->reason, 'message' => $e->getMessage()]], 410),
            default => Response::json(['error' => ['code' => $e->reason, 'message' => $e->getMessage()]], 503)
                ->withHeader('Retry-After', '30'),
        };
    }
}
