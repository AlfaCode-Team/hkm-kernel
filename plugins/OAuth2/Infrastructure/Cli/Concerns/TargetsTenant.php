<?php

declare(strict_types=1);

namespace Plugins\OAuth2\Infrastructure\Cli\Concerns;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\OAuth2\Infrastructure\Cli\TenantConnections;

/**
 * Adds the shared `--tenant` / `--all` options to an OAuth2 CLI command and
 * resolves which database connection(s) the run targets. OAuth2 data is
 * tenant-scoped under Tenancy; a CLI has no Host, so the tenant must be named
 * explicitly (`--tenant`), fanned out across the fleet (`--all`), or the command
 * falls back to the central/default connection.
 */
trait TargetsTenant
{
    /** Register `--tenant` and `--all` on the command. */
    protected function addTenantOptions(): void
    {
        $this->addOption(
            'tenant',
            't',
            'Target tenant (tenant_id, slug, or db name). Omit for the central/default connection.',
            acceptsValue: true,
        );
        $this->addOption('all', 'a', 'Apply across every active tenant database.');
    }

    /** The `--tenant` value, or null when none was given. */
    protected function tenantArg(): ?string
    {
        $value = trim((string) $this->option('tenant'));

        return $value === '' ? null : $value;
    }

    /**
     * The connection(s) this run targets: every active tenant with `--all`, else
     * the named tenant (or central). Each entry is [label, DatabasePort].
     *
     * @return list<array{0:string,1:DatabasePort}>
     */
    protected function tenantTargets(TenantConnections $connections): array
    {
        if ($this->hasOption('all')) {
            return $connections->each();
        }

        return [[$this->tenantArg() ?? 'central', $connections->resolve($this->tenantArg())]];
    }

    /** Whether to print a per-target label (fleet runs, or more than one target). */
    protected function tenantLabelled(array $targets): bool
    {
        return count($targets) > 1 || $this->hasOption('all');
    }
}
