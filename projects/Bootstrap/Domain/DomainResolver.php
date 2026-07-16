<?php

declare(strict_types=1);

namespace Project\Bootstrap\Domain;

/**
 * DomainResolver — maps an incoming Host header to a DomainContext.
 *
 * Resolution order:
 *   1. Load projects/platform.json to learn which subdomains are globally
 *      registered as 'admin' or 'api'. Defaults: admin=['app'], api=['api'].
 *   2. Load projects/projects.json and match the (lowercased, port-stripped,
 *      trailing-dot-stripped) host against each project's domains[] in two
 *      priority passes:
 *        Pass 1 — exact host match     ($host === domain)
 *        Pass 2 — subdomain-of match   ($host ends with '.' . $domain)
 *      Pass 1 wins so a project that registers 'app.example.com' directly
 *      beats one that registers 'example.com' when resolving 'app.example.com'.
 *   3. Determine the face from the subdomain via the platform.json registry.
 *   4. If no project matched but the subdomain is in the admin/api list,
 *      return a platform-only context (name === DomainContext::PLATFORM).
 *   5. If nothing matched, return null. Caller decides the fallback policy
 *      (env HKM_PROJECT in development, fail-closed in production).
 *
 * Security & Swoole-safety:
 *   - Project names from JSON are validated to block path traversal.
 *   - Static cache is keyed by basePath so multi-tenant test harnesses or
 *     side-by-side deployments cannot poison each other's registry.
 *   - No global state is mutated on the resolution path — the cache is
 *     populated once per worker per basePath and never invalidated at
 *     runtime. Deploy-time artifact assumption: redeploy = new worker.
 */
final class DomainResolver
{
    /**
     * Worker-level cache: basePath => parsed registries.
     *
     * @var array<string, array{adminSubs: list<string>, apiSubs: list<string>, projects: array<string, mixed>}>
     */
    private static array $cache = [];

    /**
     * Resolve $host against the projects registry rooted at $basePath.
     *
     * @param string $basePath Absolute path to the framework root.
     * @param string $host     Raw Host header (may include port, mixed case, trailing dot).
     */
    public static function resolve(string $basePath, string $host): ?DomainContext
    {
        $base      = rtrim($basePath, '/');
        $cleanHost = self::normaliseHost($host);
        if ($cleanHost === '') {
            return null;
        }

        $parts     = explode('.', $cleanHost);
        $subdomain = count($parts) > 2 ? $parts[0] : null;

        $reg       = self::loadRegistries($base);
        $adminSubs = $reg['adminSubs'];
        $apiSubs   = $reg['apiSubs'];

        $type = match (true) {
            $subdomain !== null && in_array($subdomain, $adminSubs, true) => DomainType::Admin,
            $subdomain !== null && in_array($subdomain, $apiSubs, true)   => DomainType::Api,
            default                                                       => DomainType::Project,
        };

        // Pass 1: exact host match.
        foreach ($reg['projects'] as $projectName => $config) {
            $ctx = self::matchProject($base, (string) $projectName, $config, $cleanHost, $type, exact: true);
            if ($ctx !== null) {
                return $ctx;
            }
        }

        // Pass 2: subdomain-of match.
        foreach ($reg['projects'] as $projectName => $config) {
            $ctx = self::matchProject($base, (string) $projectName, $config, $cleanHost, $type, exact: false);
            if ($ctx !== null) {
                return $ctx;
            }
        }

        // No project matched. Check for platform-only fallback via subdomain.
        if ($subdomain !== null && in_array($subdomain, $adminSubs, true)) {
            return new DomainContext(
                name:        DomainContext::PLATFORM,
                projectPath: $base,
                type:        DomainType::Admin,
                host:        $cleanHost,
            );
        }

        if ($subdomain !== null && in_array($subdomain, $apiSubs, true)) {
            return new DomainContext(
                name:        DomainContext::PLATFORM,
                projectPath: $base,
                type:        DomainType::Api,
                host:        $cleanHost,
            );
        }

        return null;
    }

    /**
     * Test/diagnostic hook — clear the worker-level cache.
     * Production code must never call this on the hot path.
     */
    public static function flushCache(): void
    {
        self::$cache = [];
    }

    /**
     * @param array<string, mixed>|mixed $config
     */
    private static function matchProject(
        string $base,
        string $projectName,
        mixed $config,
        string $cleanHost,
        DomainType $type,
        bool $exact,
    ): ?DomainContext {
        if (!self::isValidProjectName($projectName) || !is_array($config)) {
            return null;
        }

        $domains = $config['domains'] ?? [];
        if (!is_array($domains)) {
            return null;
        }

        foreach ($domains as $domain) {
            if (!is_string($domain) || $domain === '') {
                continue;
            }
            $domain = strtolower(rtrim($domain, '.'));

            $hit = $exact
                ? ($cleanHost === $domain)
                : str_ends_with($cleanHost, '.' . $domain);

            if ($hit) {
                // External/flat projects register an absolute "path" in the
                // registry; legacy in-repo projects fall back to <base>/projects/<name>.
                $registered = $config['path'] ?? null;
                $projectPath = (is_string($registered) && $registered !== '')
                    ? rtrim($registered, '/')
                    : $base . '/projects/' . $projectName;

                return new DomainContext(
                    name:        $projectName,
                    projectPath: $projectPath,
                    type:        $type,
                    host:        $cleanHost,
                    features:    self::loadProjFeatures($projectPath),
                );
            }
        }

        return null;
    }

    /**
     * @return array{adminSubs: list<string>, apiSubs: list<string>, projects: array<string, mixed>}
     */
    /**
     * Directory holding the persistent registry files (projects.json,
     * platform.json). HKM_USERDATA_DIR relocates them outside the kernel so an
     * update never clobbers them; falls back to <base>/projects for a checkout.
     */
    private static function userdataDir(string $base): string
    {
        $dir = $_SERVER['HKM_USERDATA_DIR']
            ?? $_ENV['HKM_USERDATA_DIR']
            ?? getenv('HKM_USERDATA_DIR')
            ?: null;

        return is_string($dir) && $dir !== '' ? rtrim($dir, '/') : $base . '/projects';
    }

    private static function loadRegistries(string $base): array
    {
        if (isset(self::$cache[$base])) {
            return self::$cache[$base];
        }

        // The registry files are USER DATA: they live in HKM_USERDATA_DIR when
        // set (persistent, outside the kernel install so an update cannot
        // overwrite them), else under <base>/projects for a dev checkout.
        $userdata  = self::userdataDir($base);
        $platform  = self::readJson($userdata . '/platform.json');
        $adminSubs = self::stringList($platform['subdomains']['admin'] ?? null) ?: ['app'];
        $apiSubs   = self::stringList($platform['subdomains']['api'] ?? null) ?: ['api'];

        $projects = self::readJson($userdata . '/projects.json');

        return self::$cache[$base] = [
            'adminSubs' => $adminSubs,
            'apiSubs'   => $apiSubs,
            'projects'  => $projects,
        ];
    }

    /**
     * @return list<string|array<string, mixed>>
     */
    private static function loadProjFeatures(string $projectPath): array
    {
        $data = self::readJson($projectPath . '/proj.json');
        $features = $data['features'] ?? [];

        return is_array($features) ? array_values($features) : [];
    }

    /**
     * Safe JSON read — returns [] on missing file, unreadable, or malformed
     * payload. Errors are written to error_log so production won't blow up
     * on a deploy with a typo in one of the registry files.
     *
     * @return array<string, mixed>
     */
    private static function readJson(string $file): array
    {
        if (!is_file($file)) {
            return [];
        }
        $raw = @file_get_contents($file);
        if ($raw === false || $raw === '') {
            return [];
        }
        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            return is_array($data) ? $data : [];
        } catch (\JsonException $e) {
            error_log("[DomainResolver] Malformed JSON in {$file}: {$e->getMessage()}");
            return [];
        }
    }

    /**
     * @return list<string>|null
     */
    private static function stringList(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $out = [];
        foreach ($value as $v) {
            if (is_string($v) && $v !== '') {
                $out[] = strtolower($v);
            }
        }
        return $out;
    }

    private static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return '';
        }
        // Strip IPv6 brackets if present (e.g. "[::1]:8080" -> "::1").
        if ($host[0] === '[') {
            $end = strpos($host, ']');
            if ($end !== false) {
                return substr($host, 1, $end - 1);
            }
        }
        $host = explode(':', $host)[0];
        return rtrim($host, '.');
    }

    private static function isValidProjectName(string $name): bool
    {
        return $name !== '' && preg_match('/^[a-zA-Z0-9_\-]+$/', $name) === 1;
    }
}
