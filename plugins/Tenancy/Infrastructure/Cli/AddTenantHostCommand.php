<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use AlfacodeTeam\PhpIoCli\Components\TextInput;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use Plugins\Database\API\Contracts\DatabaseConnectionManagerContract;
use Plugins\Tenancy\API\Contracts\TenantHostServiceContract;
use Plugins\Tenancy\Support\TenantsFile;

/**
 * tenant:host:add — register a hostname for a tenant (seed-friendly).
 *
 * Goes through TenantHostService::add() so the hostname is validated, the
 * uniqueness/quota rules are enforced, a verification token is minted and an
 * audit row is written — exactly like the HTTP flow. For local seeding it can
 * skip the DNS challenge entirely:
 *
 *   hkm tenant:host:add --slug=acme --host=acme.example.com
 *   hkm tenant:host:add --slug=acme --host=acme.localhost --verified
 *   hkm tenant:host:add --slug=acme --host=acme.example.com --primary
 *
 * --verified force-marks the host VERIFIED without a DNS lookup (dev/seed only).
 * --primary makes it the canonical host (implies --verified).
 */
final class AddTenantHostCommand extends AbstractCommand
{
    /** tenant_hosts.status — mirrors the migration enum. */
    private const STATUS_VERIFIED = 1;

    public function __construct(
        private readonly TenantHostServiceContract $hosts,
        private readonly DatabaseConnectionManagerContract $connections,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'tenant:host:add';
        $this->description = 'Register a hostname for a tenant (optionally force-verified / primary)';

        $this->addOption('tenant', 't', 'Tenant id', acceptsValue: true);
        $this->addOption('slug', '', 'Tenant slug', acceptsValue: true);
        $this->addOption('host', 'H', 'Hostname (FQDN, lower-case, no port)', acceptsValue: true);
        $this->addOption('ip', '', 'Expected A/AAAA target for verification', acceptsValue: true);
        $this->addOption('verified', '', 'Force-mark VERIFIED without a DNS check (dev/seed only)');
        $this->addOption('primary', '', 'Make this the canonical host (implies --verified)');
    }

    protected function handle(): int
    {
        $id       = (string) $this->option('tenant');
        $slug     = (string) $this->option('slug');
        $hostname = strtolower(trim((string) $this->option('host')));
        $ip       = trim((string) $this->option('ip'));
        $primary  = $this->hasOption('primary');
        $verified = $primary || $this->hasOption('verified');

        $interactive = $this->isInteractive();
        $central     = $this->connections->default();

        // Tenant — by flag, else the default recorded by tenant:create in
        // var/tenants.json (validated against the registry; a stale hint is
        // dropped), else pick from a Select of registered tenants.
        if ($id !== '' || $slug !== '') {
            $tenantId = $this->resolveTenantId($central, $id, $slug);
            if ($tenantId === null) {
                $this->error('Tenant not found in the registry.');
                return self::FAILURE;
            }
        } else {
            $tenantId = null;
            $fallback = TenantsFile::defaultTenant();
            if ($fallback !== null) {
                $tenantId = $this->resolveTenantId($central, $fallback['tenant_id'], '');
                if ($tenantId !== null) {
                    $this->info("Using default tenant [{$fallback['slug']}] from " . TenantsFile::path() . '.');
                } else {
                    TenantsFile::forget($fallback['tenant_id']);
                    $this->warning('Recorded default tenant no longer exists in the registry — stale entry dropped from var/tenants.json.');
                }
            }
            if ($tenantId === null && $interactive) {
                $tenantId = $this->selectTenant($central);
            }
            if ($tenantId === null) {
                $this->error($interactive
                    ? 'No tenants in the registry — create one with tenant:create first.'
                    : 'Provide --tenant <id> or --slug <slug> (no default tenant recorded in var/tenants.json).');
                return self::FAILURE;
            }
        }

        // Hostname — by flag, else prompt with inline validation.
        if ($hostname === '' && $interactive) {
            $hostname = strtolower(trim((string) (new TextInput('Hostname (FQDN, no port)'))
                ->placeholder('app.example.com')
                ->validate(static fn (string $v): ?string => trim($v) === '' ? 'A hostname is required.' : null)
                ->run()));
        }
        if ($hostname === '') {
            $this->error('Provide --host <hostname>.');
            return self::FAILURE;
        }

        // Expected A/AAAA target. No --ip + a terminal → pick from a Select
        // (loopback shortcuts or a typed address); otherwise use the flag.
        if ($ip === '' && $interactive) {
            $ip = $this->promptIp();
        }
        if ($ip !== '' && filter_var($ip, \FILTER_VALIDATE_IP) === false) {
            $this->error("Invalid --ip [{$ip}] — must be a valid IPv4 or IPv6 address.");
            return self::FAILURE;
        }

        // Verified / primary — ask when not given on the command line.
        if (!$verified && $interactive) {
            $verified = $this->confirm('Mark this host VERIFIED now (skip DNS check)?', false);
        }
        if ($verified && !$primary && $interactive) {
            $primary = $this->confirm('Set as the PRIMARY (canonical) host?', false);
        }

        try {
            $instructions = $this->hosts->add($tenantId, $hostname, $ip !== '' ? $ip : null);
        } catch (\Throwable $e) {
            $this->error("Could not add host: {$e->getMessage()}");
            return self::FAILURE;
        }
        $this->info("Host [{$hostname}] registered for tenant [{$tenantId}] (status=pending).");

        if (!$verified) {
            $this->alertInfo('DNS verification required', [
                "TXT {$instructions->txtRecordName}",
                "    {$instructions->txtRecordValue}",
                'Then run: tenant:host:verify (HTTP) or add --verified to seed it directly.',
            ]);
            return self::SUCCESS;
        }

        // Seed shortcut — mark verified directly, bypassing the DNS challenge.
        $hostId = $this->hostId($central, $tenantId, $hostname);
        if ($hostId === null) {
            $this->error('Host row vanished after insert — aborting.');
            return self::FAILURE;
        }

        $central->execute(
            'UPDATE tenant_hosts SET status = :s, verified_at = :at WHERE host_id = :id',
            ['s' => self::STATUS_VERIFIED, 'at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'), 'id' => $hostId],
        );
        $this->success("Host [{$hostname}] marked VERIFIED.");

        if ($primary) {
            try {
                $this->hosts->makePrimary($tenantId, $hostId);
                $this->success("Host [{$hostname}] set as PRIMARY.");
            } catch (\Throwable $e) {
                $this->error("Could not set primary: {$e->getMessage()}");
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }

    /**
     * Pick the expected verification IP from a Select: skip, a loopback
     * shortcut, or a typed (validated) address. Returns '' for "skip".
     */
    private function promptIp(): string
    {
        $none   = 'None — skip IP pinning';
        $v6     = '::1 (IPv6 loopback)';
        $v4     = '127.0.0.1 (IPv4 loopback)';
        $custom = 'Enter a specific IP…';

        $choice = $this->select('Expected IP for DNS A/AAAA verification?', [$none, $v6, $v4, $custom]);

        return match ($choice) {
            $v6     => '::1',
            $v4     => '127.0.0.1',
            $custom => (string) (new TextInput('IP address'))
                ->validate(static fn (string $v): ?string =>
                    filter_var(trim($v), \FILTER_VALIDATE_IP) !== false ? null : 'Enter a valid IPv4/IPv6 address.')
                ->run(),
            default => '',
        };
    }

    /** True only when STDIN is a real terminal — guards the Select prompt. */
    private function isInteractive(): bool
    {
        return \function_exists('stream_isatty') && @stream_isatty(\STDIN);
    }

    /** Present registered tenants as a Select; returns the chosen tenant_id (null if none). */
    private function selectTenant(DatabasePort $central): ?string
    {
        $rows = $central->query('SELECT tenant_id, slug, name FROM tenants WHERE deleted_at IS NULL ORDER BY slug');
        if ($rows === []) {
            return null;
        }

        $choices = [];
        foreach ($rows as $r) {
            $choices["{$r['slug']} — {$r['name']} ({$r['tenant_id']})"] = (string) $r['tenant_id'];
        }

        return $choices[$this->select('Select a tenant', array_keys($choices))] ?? null;
    }

    private function resolveTenantId(DatabasePort $central, string $id, string $slug): ?string
    {
        $row = $id !== ''
            ? $central->queryOne('SELECT tenant_id FROM tenants WHERE tenant_id = :id', ['id' => $id])
            : $central->queryOne('SELECT tenant_id FROM tenants WHERE slug = :slug', ['slug' => $slug]);

        return $row === null ? null : (string) $row['tenant_id'];
    }

    private function hostId(DatabasePort $central, string $tenantId, string $hostname): ?int
    {
        $row = $central->queryOne(
            'SELECT host_id FROM tenant_hosts WHERE tenant_id = :t AND hostname = :h',
            ['t' => $tenantId, 'h' => $hostname],
        );

        return $row === null ? null : (int) $row['host_id'];
    }
}
