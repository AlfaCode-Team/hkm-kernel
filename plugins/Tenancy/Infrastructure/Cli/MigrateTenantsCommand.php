<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Cli;

use AlfaCode\LetMigrate\MigrationServiceFactory;
use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\EncryptionPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Tenancy\API\Contracts\TenantRegistryContract;
use Plugins\Tenancy\Domain\Entities\Tenant;
use Plugins\Tenancy\Domain\ValueObjects\TenantStatus;

/**
 * tenants:migrate — apply the tenant template migrations across the fleet.
 *
 * Iterates ACTIVE tenants from the registry and runs pending template
 * migrations against each tenant's own database. Each tenant database keeps its
 * own `let_migrations` tracking table, so a tenant mid-rollout is never
 * corrupted by a global counter. After each success the central
 * `tenants.schema_version` is stamped so the fleet's version drift is visible.
 *
 * Failure isolation: a failed tenant is logged and SKIPPED — the run continues
 * so one bad tenant DB never aborts the fleet. Re-running only touches tenants
 * not yet at the target version (resumable).
 *
 *   hkm tenants:migrate                  # all active tenants
 *   hkm tenants:migrate --tenant=<id>    # one tenant
 *   hkm tenants:migrate --pretend        # print SQL, change nothing
 */
final class MigrateTenantsCommand extends AbstractCommand
{
    public function __construct(
        private readonly TenantRegistryContract $registry,
        private readonly DatabaseConnectionManagerContract $connections,
        private readonly EncryptionPort $crypto,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'tenant:migrate';
        $this->description = 'Run tenant template migrations across all active tenant databases';

        $this->addOption('tenant', 't', 'Migrate only this tenant_id', acceptsValue: true);
        $this->addOption('template', '', 'Override template migrations path', acceptsValue: true);
        $this->addOption('pretend', 'p', 'Print SQL instead of executing');
    }

    protected function handle(): int
    {
        $template = (string) ($this->option('template') ?: $this->defaultTemplatePath());
        $pretend  = $this->hasOption('pretend');

        $tenants = $this->targets();
        if ($tenants === []) {
            $this->info('No active tenants to migrate.');
            return self::SUCCESS;
        }

        $ok = 0;
        $failed = 0;
        $central = $this->connections->default();

        foreach ($tenants as $tenant) {
            $label = "{$tenant->slug} ({$tenant->tenantId})";
            try {
                $service = MigrationServiceFactory::fromConfig([
                    'driver'   => $tenant->dbDriver,
                    'host'     => $tenant->dbHost,
                    'port'     => $tenant->dbPort,
                    'database' => $tenant->dbName,
                    'username' => $tenant->dbUsername,
                    'password' => $this->crypto->decryptString($tenant->dbPasswordEnc),
                    'paths'    => [$template],
                    'transactional' => true,
                    'pretend'  => $pretend,
                ]);

                if (!$service->isInstalled()) {
                    $service->install();
                }

                $pending = $service->pending();
                if ($pending === []) {
                    $this->info("· {$label}: up to date.");
                    $ok++;
                    continue;
                }

                if ($pretend) {
                    [$sql] = $service->captureSql(array_keys($pending));
                    $this->info("-- {$label}");
                    foreach ($sql as $stmt) {
                        $this->info($stmt . ';');
                    }
                    $ok++;
                    continue;
                }

                $result = $service->run();
                $version = $tenant->schemaVersion + 1;
                $central->execute(
                    'UPDATE tenants SET schema_version = :v WHERE tenant_id = :id',
                    ['v' => $version, 'id' => $tenant->tenantId],
                );
                $this->success("✓ {$label}: {$result->appliedCount()} applied (v{$version}).");
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error("✘ {$label}: {$e->getMessage()}");
                // Continue — never abort the fleet for one tenant.
            }
        }

        $this->info("Done. {$ok} succeeded, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return list<Tenant> */
    private function targets(): array
    {
        $one = $this->option('tenant');
        if (is_string($one) && $one !== '') {
            $tenant = $this->registry->find($one);

            return ($tenant !== null && $tenant->status === TenantStatus::Active) ? [$tenant] : [];
        }

        return $this->registry->listByStatus(TenantStatus::Active->value);
    }

    private function defaultTemplatePath(): string
    {
        // Env override: an absolute path is honoured as-is; a relative one is
        // resolved under the active project root.
        $custom = env('TENANCY_TEMPLATE_PATH');
        if (is_string($custom) && $custom !== '') {
            return $this->isAbsolutePath($custom) ? $custom : Paths::project($custom);
        }

        // Project-relative by default: projects/<name>/database/tenant-template.
        return Paths::project('database/tenant-template');
    }

    /** Unix (/…) or Windows (C:\… / \\…) absolute path. */
    private function isAbsolutePath(string $path): bool
    {
        return $path[0] === '/' || (bool) preg_match('#^[A-Za-z]:[\\\\/]|^\\\\\\\\#', $path);
    }
}
