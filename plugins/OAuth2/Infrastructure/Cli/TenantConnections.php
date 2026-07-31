<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Tenancy\API\Contracts\TenantConnectionResolverContract;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;

/**
 * Resolves the DatabasePort an OAuth2 CLI command should operate on.
 *
 * OAuth2's tables (clients, auth codes, refresh tokens, scopes, device codes)
 * follow the request's DatabasePort — TENANT-scoped under Tenancy, central in a
 * single-DB deployment. A CLI has no Host header, so it cannot resolve a tenant
 * on its own: pass `--tenant=<id|slug|db>` to target one, or omit it to use the
 * central/default connection (the historical behaviour).
 *
 * Tenancy is OPTIONAL. When the plugin is absent, `registry`/`resolver` are null
 * and only the central connection is available — passing `--tenant` then fails
 * with a clear message instead of silently hitting the wrong database. The
 * Tenancy type references below are only reached when tenancy IS available, so
 * this class loads fine without the Tenancy plugin on disk.
 */
final class TenantConnections
{
    public function __construct(
        private readonly DatabasePort $central,
        private readonly ?TenantRegistryContract $registry = null,
        private readonly ?TenantConnectionResolverContract $resolver = null,
    ) {
    }

    public function tenancyAvailable(): bool
    {
        return $this->registry !== null && $this->resolver !== null;
    }

    /** The central / default connection (no tenant routing). */
    public function central(): DatabasePort
    {
        return $this->central;
    }

    /** Resolve the connection for a tenant identifier (tenant_id, slug, or db name). */
    public function for(string $identifier): DatabasePort
    {
        if (!$this->tenancyAvailable()) {
            throw new \RuntimeException(
                '--tenant needs the Tenancy plugin, which is not enabled for this project.',
            );
        }

        return $this->resolver->for($this->tenantId($identifier));
    }

    /**
     * Resolve the DatabasePort for a command run: the named tenant, or central
     * when no `--tenant` was given.
     */
    public function resolve(?string $identifier): DatabasePort
    {
        return $identifier === null ? $this->central : $this->for($identifier);
    }

    /**
     * Every active tenant's connection (for fleet-wide `--all` operations), each
     * labelled "slug (tenant_id)". Falls back to a single central entry when
     * Tenancy is not available. Unreachable tenants are skipped.
     *
     * @return list<array{0:string,1:DatabasePort}>
     */
    public function each(): array
    {
        if (!$this->tenancyAvailable()) {
            return [['central', $this->central]];
        }

        $out = [];
        foreach ($this->registry->listByStatus(TenantStatus::Active->value) as $tenant) {
            try {
                $out[] = ["{$tenant->slug} ({$tenant->tenantId})", $this->resolver->for($tenant->tenantId)];
            } catch (\Throwable) {
                // Skip a suspended / unreachable tenant — one bad DB never aborts the fleet.
            }
        }

        return $out;
    }

    /** Map a tenant_id | slug | db name to its tenant_id (or throw). */
    private function tenantId(string $identifier): string
    {
        foreach ($this->registry->listByStatus(TenantStatus::Active->value) as $tenant) {
            if ($tenant->tenantId === $identifier
                || $tenant->slug === $identifier
                || $tenant->dbName === $identifier) {
                return $tenant->tenantId;
            }
        }

        throw new \RuntimeException("Unknown or inactive tenant: {$identifier}");
    }
}
