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
use Plugins\Tenancy\Support\TenantsFile;

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
 * Scope: by default only the tenants THIS project provisioned (var/tenants.json)
 * are migrated, because several projects may share one central registry and a
 * sibling's tenant is encrypted with a different APP_KEY. Pass --all for the
 * whole fleet. A project with no var/tenants.json migrates every active tenant.
 *
 *   hkm tenants:migrate                  # this project's tenants
 *   hkm tenants:migrate --all            # every active tenant in the registry
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
        $this->addOption('all', 'a', 'Migrate EVERY active tenant in the registry, ignoring var/tenants.json');
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

    /**
     * Which tenants this run touches.
     *
     * Scope rules, in order:
     *   --tenant=<id>  exactly that tenant (when active).
     *   --all          every active tenant in the registry (fleet-wide).
     *   default        the tenants THIS project recorded in var/tenants.json,
     *                  intersected with the active registry rows.
     *
     * The default is project-scoped because several projects may share ONE
     * central registry. A sibling project's tenant is not ours to migrate: its
     * credentials are encrypted with that project's APP_KEY, so we could not
     * decrypt them anyway, and its schema is driven by its own tenant-template.
     * Without this filter such a tenant surfaces as a spurious decrypt failure
     * on every run.
     *
     * When var/tenants.json is absent or empty (it is disposable, and a
     * single-project deployment may never write one) we fall back to the whole
     * active fleet — the historical behaviour, so nothing regresses.
     *
     * @return list<Tenant>
     */
    private function targets(): array
    {
        $one = $this->option('tenant');
        if (\is_string($one) && $one !== '') {
            $tenant = $this->registry->find($one);

            return ($tenant !== null && $tenant->status === TenantStatus::Active) ? [$tenant] : [];
        }

        $active = $this->registry->listByStatus(TenantStatus::Active->value);

        if ($this->hasOption('all')) {
            return $active;
        }

        $owned = [];
        foreach (TenantsFile::all() as $entry) {
            $owned[$entry['tenant_id']] = true;
        }

        if ($owned === []) {
            return $active;
        }

        $scoped = array_values(array_filter(
            $active,
            static fn (Tenant $t): bool => isset($owned[$t->tenantId]),
        ));

        $skipped = \count($active) - \count($scoped);
        if ($skipped > 0) {
            $this->info(
                "· scoped to {$this->tenantsFileLabel()} — skipping {$skipped} tenant(s) "
                . 'owned by another project (use --all to include them).'
            );
        }

        return $scoped;
    }

    /** Short, readable path to var/tenants.json for the scope notice. */
    private function tenantsFileLabel(): string
    {
        $path = TenantsFile::path();
        $root = Paths::project();

        return str_starts_with($path, $root) ? ltrim(substr($path, \strlen($root)), '/\\') : $path;
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
