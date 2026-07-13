<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use Plugins\Database\API\Contracts\DatabaseConfigurationContract;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Database\Infrastructure\Drivers\MySQLConfiguration;
use Plugins\Database\Infrastructure\Drivers\PostgreSQLConfiguration;
use Plugins\Database\Infrastructure\Drivers\SQLiteConfiguration;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\Domain\Entities\Tenant;
use Plugins\Tenancy\Domain\Exceptions\TenantUnavailableException;
use Plugins\Tenancy\Domain\Exceptions\UnknownTenantException;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * TenantConnectionResolver — maps tenant_id -> isolated DatabasePort.
 *
 * Sits on top of plugins/Database's ConnectionManager: it registers a named
 * connection per tenant ("tenant:<id>") whose config is derived from the
 * central registry row, then asks the manager to resolve it. The manager
 * caches the resolved adapter and builds it lazily, so reuse and lazy socket
 * opening come for free.
 *
 * Guarantees:
 *   - Fail closed. Unknown / suspended / deleted / unreachable -> throw. Never
 *     fall back to another tenant or the central DB.
 *   - Per-tenant circuit breaker. After N consecutive failures the breaker
 *     opens and the tenant fast-fails for a cooldown window, isolating one dead
 *     tenant DB from the rest of the fleet.
 *
 * Swoole safety: this resolver is app-lifetime, but the DatabasePort it returns
 * is bound into the per-request ModuleContainer by TenantContextStage and
 * discarded on reset(). Two coroutines serving different tenants get different
 * bindings. The resolver itself holds no per-request state.
 */
final class TenantConnectionResolver implements TenantConnectionResolverContract
{
    public function __construct(
        private readonly DatabaseConnectionManagerContract $connections,
        private readonly TenantRegistryContract $registry,
        private readonly EncryptionPort $crypto,
        private readonly CachePort $cache,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly int $breakerThreshold = 5,
        private readonly int $breakerCooldown = 30,
        /**
         * Sliding window (seconds) over which consecutive failures must occur to
         * trip the breaker. Without it the failure counter never expires, so rare
         * blips spread over hours/days would eventually open the breaker on a
         * perfectly healthy tenant.
         */
        private readonly int $breakerWindow = 60,
    ) {}

    public function for(string $tenantId): DatabasePort
    {
        $name = 'tenant:' . $tenantId;

        // ALWAYS re-validate status, even when the connection is already warm in
        // this worker. The registry is cache-backed (short TTL), so this is cheap,
        // and it is what makes a suspension/deletion take effect on a long-lived
        // Swoole worker instead of lingering until the process restarts. A stale
        // warm handle to a now-unavailable tenant is dropped so it cannot be served.
        $tenant = $this->registry->find($tenantId)
            ?? $this->reject($name, UnknownTenantException::for($tenantId));

           
        try {
            $this->guardStatus($tenant);
        } catch (TenantUnavailableException $e) {
            $this->reject($name, $e);
        }

        $this->guardBreaker($tenantId);

        // Reuse a healthy warm connection; otherwise build it lazily.
        if (!$this->connections->has($name)) {
            $this->connections->register($name, $this->configFor($tenant));
        }

        return $this->connections->connection($name);
    }

    /**
     * Explicitly drop any open tenant connection and forget its cached registry
     * row, so a control-plane action (suspend/delete/credential rotation) takes
     * effect immediately rather than waiting out the registry TTL.
     */
    public function invalidate(string $tenantId): void
    {
        $this->registry->forget($tenantId);
        $this->connections->close('tenant:' . $tenantId);
    }

    /** Close any stale warm handle, then throw the routing decision. */
    private function reject(string $connectionName, \Throwable $e): never
    {
        $this->connections->close($connectionName);
        throw $e;
    }

    /**
     * Record a tenant DB failure (called by TenantContextStage when a query
     * against the tenant connection throws a connectivity error). Trips the
     * breaker once the threshold is reached and drops the cached adapter so the
     * next attempt rebuilds it.
     */
    public function recordFailure(string $tenantId, \Throwable $e): void
    {
        $key   = $this->failKey($tenantId);
        $count = $this->cache->increment($key);
        $this->connections->close('tenant:' . $tenantId);

        // Establish the sliding window on the FIRST failure of a window. Redis
        // INCR preserves an existing TTL, so subsequent increments keep counting
        // within the same window; the counter then expires if failures stop.
        if ($count <= 1) {
            $this->cache->set($key, $count, $this->breakerWindow);
        }

        if ($count >= $this->breakerThreshold) {
            $this->cache->set($this->breakerKey($tenantId), 1, $this->breakerCooldown);
            $this->logger->error('Tenant DB circuit breaker opened', [
                'tenant_id' => $tenantId,
                'failures'  => $count,
                'cooldown'  => $this->breakerCooldown,
                'error'     => $e->getMessage(),
            ]);
        }
    }

    /** Clear the failure counter after a healthy request. */
    public function recordSuccess(string $tenantId): void
    {
        $this->cache->delete($this->failKey($tenantId));
    }

    private function guardStatus(Tenant $tenant): void
    {
        match ($tenant->status) {
            TenantStatus::Active       => null,
            TenantStatus::Suspended    => throw TenantUnavailableException::suspended($tenant->tenantId),
            TenantStatus::Deleted      => throw TenantUnavailableException::deleted($tenant->tenantId),
            TenantStatus::Provisioning => throw TenantUnavailableException::provisioning($tenant->tenantId),
        };
    }

    private function guardBreaker(string $tenantId): void
    {
        if ($this->cache->has($this->breakerKey($tenantId))) {
            throw TenantUnavailableException::breakerOpen($tenantId);
        }
    }

    private function configFor(Tenant $tenant): DatabaseConfigurationContract
    {
        // SQLite is file-based — no host/port/credentials to decrypt. The
        // registry stores the file path in db_name (storefront/domain mode).
        // Fail CLOSED when the file is absent: opening it would silently create
        // an empty database and serve empty data (e.g. a just-deleted tenant
        // whose row is still cached). A missing file means "not provisioned".
        if ($tenant->dbDriver === 'sqlite') {
            if ($tenant->dbName !== ':memory:' && !is_file($tenant->dbName)) {
                throw TenantUnavailableException::provisioning($tenant->tenantId);
            }

            return new SQLiteConfiguration($tenant->dbName);
        }

        $password = $this->crypto->decryptString($tenant->dbPasswordEnc);

        return match ($tenant->dbDriver) {
            'pgsql' => new PostgreSQLConfiguration(
                host: $tenant->dbHost,
                port: $tenant->dbPort,
                database: $tenant->dbName,
                username: $tenant->dbUsername,
                password: $password,
            ),
            default => new MySQLConfiguration(
                host: $tenant->dbHost,
                port: $tenant->dbPort,
                database: $tenant->dbName,
                username: $tenant->dbUsername,
                password: $password,
            ),
        };
    }

    private function failKey(string $tenantId): string
    {
        return 'tenancy:fail:' . $tenantId;
    }

    private function breakerKey(string $tenantId): string
    {
        return 'tenancy:breaker:' . $tenantId;
    }
}
