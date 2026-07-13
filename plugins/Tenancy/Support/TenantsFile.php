<?php

declare(strict_types=1);

namespace Plugins\Tenancy\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * var/tenants.json — per-project record of tenants provisioned from THIS
 * machine. tenant:create writes it so later tenant commands (tenant:delete,
 * tenant:host:add, …) can default to a tenant without --tenant/--slug.
 *
 * Convenience only: the central `tenants` table stays the single source of
 * truth. Entries here are HINTS — every command still validates the id against
 * the registry before acting, and a stale hint is dropped when detected.
 *
 * Shape:
 *   { "default": "<tenant_id>",
 *     "tenants": [ { "tenant_id": "…", "slug": "acme", "name": "Acme Inc" } ] }
 *
 * `default` is the most recently created tenant (last create wins).
 */
final class TenantsFile
{
    public static function path(): string
    {
        return Paths::var('tenants.json');
    }

    /** Upsert a tenant and make it the default (last created wins). */
    public static function remember(string $tenantId, string $slug, string $name): void
    {
        $data = self::read();
        $data['tenants'] = array_values(array_filter(
            $data['tenants'],
            static fn (array $t): bool => $t['tenant_id'] !== $tenantId,
        ));
        $data['tenants'][] = ['tenant_id' => $tenantId, 'slug' => $slug, 'name' => $name];
        $data['default']   = $tenantId;
        self::write($data);
    }

    /** Drop a tenant; the default falls back to the last remaining entry. */
    public static function forget(string $tenantId): void
    {
        $data = self::read();
        $data['tenants'] = array_values(array_filter(
            $data['tenants'],
            static fn (array $t): bool => $t['tenant_id'] !== $tenantId,
        ));
        if ($data['default'] === $tenantId) {
            $last = end($data['tenants']);
            $data['default'] = $last === false ? '' : $last['tenant_id'];
        }
        self::write($data);
    }

    /** @return list<array{tenant_id: string, slug: string, name: string}> */
    public static function all(): array
    {
        return self::read()['tenants'];
    }

    /**
     * The tenant other commands should act on when none is named: the recorded
     * default when still present, else the only entry, else null.
     *
     * @return array{tenant_id: string, slug: string, name: string}|null
     */
    public static function defaultTenant(): ?array
    {
        $data = self::read();
        foreach ($data['tenants'] as $t) {
            if ($t['tenant_id'] === $data['default']) {
                return $t;
            }
        }

        return \count($data['tenants']) === 1 ? $data['tenants'][0] : null;
    }

    /** @return array{tenants: list<array{tenant_id: string, slug: string, name: string}>, default: string} */
    private static function read(): array
    {
        $raw  = @file_get_contents(self::path());
        $data = \is_string($raw) ? json_decode($raw, true) : null;

        $tenants = [];
        foreach ((\is_array($data) ? ($data['tenants'] ?? []) : []) as $t) {
            if (\is_array($t) && \is_string($t['tenant_id'] ?? null) && $t['tenant_id'] !== '') {
                $tenants[] = [
                    'tenant_id' => $t['tenant_id'],
                    'slug'      => \is_string($t['slug'] ?? null) ? $t['slug'] : '',
                    'name'      => \is_string($t['name'] ?? null) ? $t['name'] : '',
                ];
            }
        }

        return [
            'tenants' => $tenants,
            'default' => \is_array($data) && \is_string($data['default'] ?? null) ? $data['default'] : '',
        ];
    }

    /** @param array{tenants: list<array{tenant_id: string, slug: string, name: string}>, default: string} $data */
    private static function write(array $data): void
    {
        $path = self::path();
        $dir  = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents(
            $path,
            json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . "\n",
            \LOCK_EX,
        );
    }
}
