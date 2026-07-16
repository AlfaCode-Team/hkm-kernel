<?php

declare(strict_types=1);

namespace Project\Bootstrap;

use Project\Bootstrap\Domain\DomainContext;
use Project\Bootstrap\Domain\DomainResolver;

/**
 * EntryHelpers — pure helpers shared by every HTTP entry point.
 *
 * Picks the active project (from the resolved DomainContext, then HKM_PROJECT,
 * then 'admin') and resolves the bootstrap file path for it. Returns null
 * project context if no Host header is available (CLI), which lets the
 * caller decide its fallback policy.
 */
final class EntryHelpers
{
    /**
     * Resolve the project bootstrap file path for the given $project under $rootPath.
     * Falls back to the legacy bootstrap/app.php shim when the project bootstrap is missing.
     */
    public static function bootstrapPathFor(string $rootPath, string $project): string
    {
        $rootPath = rtrim($rootPath, '/');
        $project  = self::sanitiseProject($project);
        $candidate = $rootPath . '/projects/' . $project . '/bootstrap/app.php';

        return is_file($candidate) ? $candidate : $rootPath . '/bootstrap/app.php';
    }

    /**
     * Resolve the bootstrap path honouring a resolved DomainContext.
     *
     * When the host matched a project REGISTERED with an external absolute path
     * (a flat standalone project created with `psp new`), boot that project's
     * own bootstrap:
     *   - flat layout:   <projectPath>/app/bootstrap/app.php
     *   - nested layout: <projectPath>/bootstrap/app.php
     * Otherwise fall back to the in-repo lookup (bootstrapPathFor).
     */
    public static function bootstrapPathForContext(?DomainContext $ctx, string $rootPath, string $project): string
    {
        if ($ctx !== null && !$ctx->isPlatformOnly()) {
            $path = rtrim($ctx->projectPath, '/');
            // Only treat as external when it lives outside <root>/projects/.
            $inRepo = $path === rtrim($rootPath, '/') . '/projects/' . self::sanitiseProject($ctx->name);
            if (!$inRepo) {
                foreach (['/app/bootstrap/app.php', '/bootstrap/app.php'] as $rel) {
                    if (is_file($path . $rel)) {
                        return $path . $rel;
                    }
                }
            }
        }

        return self::bootstrapPathFor($rootPath, $project);
    }

    /**
     * Resolve the active project name in priority order:
     *   1. DomainContext->name (when a host matched a project in projects.json)
     *   2. HKM_PROJECT env var
     *   3. 'admin' (legacy default)
     */
    public static function projectFromContext(?DomainContext $ctx): string
    {
        if ($ctx !== null && !$ctx->isPlatformOnly()) {
            return self::sanitiseProject($ctx->name);
        }
        $env = (string) (getenv('HKM_PROJECT') ?: '');
        return self::sanitiseProject($env !== '' ? $env : 'admin');
    }

    /**
     * Resolve the DomainContext for an HTTP host, or null when no host is given
     * (CLI / worker contexts). Never throws — returns null on any registry error.
     */
    public static function resolveDomain(string $rootPath, ?string $host): ?DomainContext
    {
        if ($host === null || trim($host) === '') {
            return null;
        }
        return DomainResolver::resolve($rootPath, $host);
    }

    /**
     * Read project-layer routes declared in <projectPath>/proj.json under the
     * optional "routes" key. Each route keeps method/path/handler plus the
     * optional "filters" and "requires" arrays (passed through to the route-
     * manifest compiler); malformed entries are silently dropped so a typo in
     * proj.json never breaks the boot — invalid handlers and unknown require
     * domains are caught later by the route-manifest compiler with a descriptive
     * error.
     *
     * @return list<array{method: string, path: string, handler: string, filters?: mixed, requires?: mixed}>
     */
    public static function projectRoutes(string $projectPath): array
    {
        $file = rtrim($projectPath, '/') . '/proj.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['routes']) || !is_array($data['routes'])) {
            return [];
        }

        $routes = [];
        foreach ($data['routes'] as $route) {
            if (!is_array($route)
                || !isset($route['method'], $route['path'], $route['handler'])) {
                continue;
            }
            $entry = [
                'method'  => (string) $route['method'],
                'path'    => (string) $route['path'],
                'handler' => (string) $route['handler'],
            ];
            // Optional per-route declarations passed through to the route-manifest
            // compiler: filters[] (auth, throttle, …) and requires[] (plugin
            // domains to seed into this route's dependency graph).
            if (isset($route['filters'])) {
                $entry['filters'] = $route['filters'];
            }
            if (isset($route['requires'])) {
                $entry['requires'] = $route['requires'];
            }
            $routes[] = $entry;
        }

        return $routes;
    }

    /**
     * Read the project's GLOBAL (essential) modules from <projectPath>/proj.json
     * under "essentials": [ ... ]. Each entry is a module DOMAIN (a plugin's
     * solves value, e.g. "tenancy.routing") — the project's declaration of which
     * plugins must register on EVERY request. Passed to
     * Kernel::withEssentialModules(); the kernel resolves each domain to its
     * provider at build() and FAILS the boot on an unknown domain, so a typo or
     * a plugin missing from withModules() never becomes a silent no-op.
     * Non-string / malformed entries are dropped here.
     *
     * @return list<string>
     */
    public static function projectEssentials(string $projectPath): array
    {
        $file = rtrim($projectPath, '/') . '/proj.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['essentials'] ?? null)) {
            return [];
        }

        $domains = [];
        foreach ($data['essentials'] as $domain) {
            if (is_string($domain) && trim($domain) !== '') {
                $domains[] = trim($domain);
            }
        }

        return $domains;
    }

    /**
     * Read the project's route-disable policy from <projectPath>/proj.json under
     * "routePolicy": { "disable": [ ... ] } (a bare "disable": [...] top-level key
     * is also accepted). Each entry is a "METHOD /path" spec or a module domain,
     * passed straight to Kernel::withRoutePolicy(); the route-manifest compiler
     * validates them at boot (an unmatched spec fails with a descriptive error).
     * Non-string / malformed entries are dropped here.
     *
     * @return list<string>
     */
    public static function projectRoutePolicy(string $projectPath): array
    {
        $file = rtrim($projectPath, '/') . '/proj.json';
        if (!is_file($file)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            return [];
        }

        $disable = $data['routePolicy']['disable'] ?? $data['disable'] ?? null;
        if (!is_array($disable)) {
            return [];
        }

        $specs = [];
        foreach ($disable as $spec) {
            if (is_string($spec) && trim($spec) !== '') {
                $specs[] = trim($spec);
            }
        }

        return $specs;
    }

    private static function sanitiseProject(string $project): string
    {
        $project = trim($project);
        if ($project === '' || preg_match('/^[a-zA-Z0-9_\-]+$/', $project) !== 1) {
            return 'admin';
        }
        return $project;
    }
}
