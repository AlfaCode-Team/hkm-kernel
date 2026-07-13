<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Tenancy\Support\TenantsFile;

/**
 * tenant:remember — record an EXISTING registry tenant in var/tenants.json.
 *
 * tenant:create records new tenants automatically; this backfills tenants that
 * were provisioned before that existed (or after var/ was wiped — it is
 * disposable), so tenant:delete / tenant:host:add work without --tenant/--slug.
 * The remembered tenant becomes the default (last remembered wins).
 *
 *   hkm tenant:remember --slug=acme      # one tenant, by slug
 *   hkm tenant:remember --tenant=<id>    # one tenant, by id
 *   hkm tenant:remember --all            # every registered tenant
 *   hkm tenant:remember                  # interactive pick (or auto when only one)
 */
final class RememberTenantCommand extends AbstractCommand
{
    public function __construct(
        private readonly DatabaseConnectionManagerContract $connections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'tenant:remember';
        $this->description = 'Record an existing tenant in var/tenants.json so tenant commands default to it';

        $this->addOption('tenant', 't', 'Tenant id to remember', acceptsValue: true);
        $this->addOption('slug', '', 'Tenant slug to remember', acceptsValue: true);
        $this->addOption('all', 'a', 'Remember every registered tenant (last one becomes the default)');
    }

    protected function handle(): int
    {
        $central = $this->connections->default();

        if ($this->hasOption('all')) {
            $rows = $central->query('SELECT tenant_id, slug, name FROM tenants WHERE deleted_at IS NULL ORDER BY created_at');
            if ($rows === []) {
                $this->error('No tenants in the registry — create one with tenant:create first.');
                return self::FAILURE;
            }
            foreach ($rows as $r) {
                TenantsFile::remember((string) $r['tenant_id'], (string) $r['slug'], (string) $r['name']);
                $this->info("· remembered [{$r['slug']}] ({$r['tenant_id']}).");
            }
            $last = end($rows);
            $this->success(\count($rows) . ' tenant(s) recorded in ' . TenantsFile::path() . " — default is [{$last['slug']}].");
            return self::SUCCESS;
        }

        $id   = (string) $this->option('tenant');
        $slug = (string) $this->option('slug');

        $row = null;
        if ($id !== '' || $slug !== '') {
            $row = $id !== ''
                ? $central->queryOne('SELECT tenant_id, slug, name FROM tenants WHERE tenant_id = :id', ['id' => $id])
                : $central->queryOne('SELECT tenant_id, slug, name FROM tenants WHERE slug = :slug', ['slug' => $slug]);
            if ($row === null) {
                $this->error('Tenant not found in the registry.');
                return self::FAILURE;
            }
        } else {
            $rows = $central->query('SELECT tenant_id, slug, name FROM tenants WHERE deleted_at IS NULL ORDER BY slug');
            if ($rows === []) {
                $this->error('No tenants in the registry — create one with tenant:create first.');
                return self::FAILURE;
            }
            if (\count($rows) === 1) {
                $row = $rows[0];
            } elseif (\function_exists('stream_isatty') && @stream_isatty(\STDIN)) {
                $choices = [];
                foreach ($rows as $r) {
                    $choices["{$r['slug']} — {$r['name']} ({$r['tenant_id']})"] = $r;
                }
                $row = $choices[$this->select('Select the tenant to remember', array_keys($choices))] ?? null;
                if ($row === null) {
                    return self::FAILURE;
                }
            } else {
                $this->error('Several tenants registered — provide --tenant <id>, --slug <slug>, or --all.');
                return self::FAILURE;
            }
        }

        TenantsFile::remember((string) $row['tenant_id'], (string) $row['slug'], (string) $row['name']);
        $this->success("Tenant [{$row['slug']}] ({$row['tenant_id']}) recorded as default in " . TenantsFile::path() . '.');

        return self::SUCCESS;
    }
}
