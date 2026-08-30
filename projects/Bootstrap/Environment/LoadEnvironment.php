<?php

declare(strict_types=1);

namespace Project\Bootstrap\Environment;

use Project\Bootstrap\Domain\DomainContext;

/**
 * LoadEnvironment — pre-bootstrap .env loader (Project layer).
 *
 * Runs in the entry points BEFORE the kernel builder ({@see app/bootstrap/base.php})
 * reads any configuration, populating $_ENV / $_SERVER / putenv() so that the
 * builder's plain getenv()/$_ENV lookups resolve. It lives entirely under
 * Project\Bootstrap\ so the kernel stays environment-agnostic — exactly like
 * {@see \Project\Bootstrap\Domain\DomainResolver}, this is wiring, not business logic.
 *
 * It deliberately ships its own tiny parser instead of pulling in vlucas/phpdotenv:
 * the framework has no Composer dependency on a dotenv package and end users may
 * run from a native distribution with no vendor/ at all.
 *
 * ── THREE-TIER CASCADE ──────────────────────────────────────────────────────
 *   TIER 1  base       {root}/.env, then {root}/.env.{APP_ENV|--env}
 *   TIER 2  domain     {root}/.env.{sld}, .env.{sub}, .env.{sub}.{sld}
 *   TIER 3  project    {projectPath}/.env (+ the same domain cascade)
 *
 * Each file is optional; a later file overrides keys set by an earlier one
 * (mutable cascade). Values already present in the *real* process environment
 * (set before this loader runs) are never clobbered — true OS/server config wins.
 *
 * ── COMPILED CACHE (FPM / php -S fast path) ─────────────────────────────────
 * Re-parsing the cascade on every short-lived process (FPM, built-in server)
 * is wasted work. After the first full load the resolved values are written to
 * a compiled PHP file under {projectPath|root}/var/cache/env.<scope>.php that
 * opcache keeps in memory. Subsequent processes `include` it (one stat + an
 * opcode-cached array) instead of stat+parsing 9 files — ~190µs → ~5µs.
 *
 * Invalidation is automatic: the cache records the mtime+size of every file it
 * examined (existing AND absent). Before trusting the cache, each of those paths
 * is re-stat'd; any change — an edited .env, a newly created .env.local, a
 * deleted override — busts it and forces a fresh load + rewrite. No manual
 * `cache:clear` needed in development.
 *
 * Under OpenSwoole the per-worker load-once guard already makes this moot
 * (env loads once per worker); the cache exclusively benefits per-request SAPIs.
 *
 * @see \Project\Bootstrap\Domain\DomainContext for projectPath / host resolution.
 */
final class LoadEnvironment
{
    /** Guards against double-loading within a single process (per root path). */
    private static array $loaded = [];

    /**
     * Compiled-cache opt-in. OFF by default: the measured win is small (~30µs/req,
     * value injection dominates, not parsing) and stat-invalidation has 1-second
     * mtime granularity — unsafe for the rapidly-edited dev .env. Enable it only in
     * production, where .env is immutable between deploys (a deploy spawns fresh
     * processes / clears opcache anyway), via useCache(true) or ENV_CACHE=1.
     */
    private static ?bool $useCache = null;

    /** When true, also mirror values into the real process env via putenv(). */
    private static bool $processEnv = false;

    /** Keys this loader set from files this run (name => value), for cache write. */
    private static array $collected = [];

    /** Every path examined this run (path => [mtime,size] | false), for invalidation. */
    private static array $examined = [];

    /**
     * Load the .env cascade for the given root + optional resolved domain.
     *
     * @param string             $rootPath Absolute repository root.
     * @param DomainContext|null  $domain  Resolved context (gives host + projectPath).
     * @param array|null         $argv    CLI argv, for the --env / --domain overrides.
     */
    public static function load(string $rootPath, ?DomainContext $domain = null, ?array $argv = null): void
    {
        $rootPath = rtrim($rootPath, '/\\');

        if (isset(self::$loaded[$rootPath])) {
            return;
        }
        self::$loaded[$rootPath] = true;

        $argv = $argv ?? ($_SERVER['argv'] ?? []);

        // Cache scope: stable, computed from values available BEFORE any file is
        // read (host + the --env/real-env hint), so the cache filename is identical
        // on the read and the write of the same process family.
        $host    = $domain?->host
            ?? ($_SERVER['HTTP_HOST'] ?? null)
            ?? self::cliOption($argv, '--domain')
            ?? self::current('APP_DOMAIN');
        $host    = is_string($host) ? $host : null;
        $envHint = self::cliOption($argv, '--env') ?? self::current('APP_ENV');

        $cacheFile = self::cacheEnabled()
            ? self::cachePath($rootPath, $domain, $host, $envHint)
            : null;

        // ── Fast path: a valid compiled cache short-circuits the cascade ─────
        if ($cacheFile !== null && self::applyFromCache($cacheFile)) {
            return;
        }

        // ── Slow path: full cascade, tracking collected keys + examined files ─
        self::$collected = [];
        self::$examined  = [];

        self::runCascade($rootPath, $domain, $host, $envHint);

        if ($cacheFile !== null) {
            self::writeCache($cacheFile);
        }
    }

    /** Force the compiled cache on/off, overriding the ENV_CACHE auto-detection. */
    public static function useCache(bool $enabled): void
    {
        self::$useCache = $enabled;
    }

    /**
     * Mirror loaded values into the real process environment via putenv() too.
     * Off by default (putenv is the injection bottleneck and coroutine-unsafe).
     * Enable only when a third-party SDK reads the OS environment directly.
     */
    public static function useProcessEnv(bool $enabled): void
    {
        self::$processEnv = $enabled;
    }

    /** Resolve whether the cache is active: explicit override, else ENV_CACHE=1. */
    private static function cacheEnabled(): bool
    {
        if (self::$useCache !== null) {
            return self::$useCache;
        }
        return filter_var(getenv('ENV_CACHE') ?: '', FILTER_VALIDATE_BOOL);
    }

    /** Reset per-process state (tests only). */
    public static function reset(): void
    {
        self::$loaded    = [];
        self::$collected = [];
        self::$examined  = [];
        self::$useCache  = null;
        self::$processEnv = false;
    }

    /** Run the three-tier cascade (no caching concern here). */
    private static function runCascade(
        string $rootPath,
        ?DomainContext $domain,
        ?string $host,
        ?string $envHint,
    ): void {
        // ── Tier 1: base ────────────────────────────────────────────────────
        self::loadFile($rootPath . '/.env');

        // .env itself may define APP_ENV; re-read so .env.{APP_ENV} picks it up.
        $appEnv = $envHint ?? self::current('APP_ENV');
        if ($appEnv !== null && $appEnv !== '') {
            self::loadFile($rootPath . '/.env.' . $appEnv);
        }

        [$sld, $sub] = self::hostParts($host);

        // ── Tier 2: domain overrides (root) ─────────────────────────────────
        self::loadDomainTier($rootPath, $sld, $sub);

        // ── Tier 3: project overrides ───────────────────────────────────────
        //
        // CONFINED TO THE ROOT BEING SERVED — deliberately, and this is a
        // security boundary, not tidiness.
        //
        // $domain comes from DomainResolver, which matches the request's Host
        // header against the MACHINE-GLOBAL registry (HKM_USERDATA_DIR/
        // projects.json) listing every project on the host by ABSOLUTE path. A
        // Host owned by a DIFFERENT project therefore resolves to that
        // project's directory — and loading its .env here would splice a
        // foreign APP_KEY, DB_* and SESSION_* over ours, from a header the
        // caller controls. With ENV_CACHE on, the merged result (our secrets
        // included) is then written under that other project's var/cache.
        //
        // The test is containment rather than equality, because both layouts
        // are legitimate: flat, where projectPath IS the root; and nested,
        // where it is <root>/projects/<name>. Anything outside the root is a
        // different application and its environment is none of our business.
        if ($domain !== null && !$domain->isPlatformOnly()) {
            $projectPath = rtrim($domain->projectPath, '/\\');

            if (!self::isWithin($projectPath, $rootPath)) {
                error_log(sprintf(
                    "[hkm] Host '%s' is registered to project '%s' at %s, which is outside the "
                    . 'application root %s. Its .env was NOT loaded. '
                    . 'Check HKM_USERDATA_DIR/projects.json.',
                    (string) $host,
                    $domain->name,
                    $projectPath,
                    $rootPath,
                ));

                return;
            }

            self::loadFile($projectPath . '/.env');
            self::loadDomainTier($projectPath, $sld, $sub);
        }
    }

    /**
     * Is $path the same directory as $root, or somewhere beneath it?
     *
     * Compared after realpath() so symlinks and `..` cannot walk out, and with
     * an explicit separator on the prefix test so a sibling directory sharing a
     * name prefix (/srv/app-backup against /srv/app) is not treated as inside.
     * A path that does not exist falls back to its normalised form: it can
     * still be judged, and a non-existent directory has no .env to load anyway.
     */
    private static function isWithin(string $path, string $root): bool
    {
        $path = rtrim(realpath($path) ?: $path, '/\\');
        $root = rtrim(realpath($root) ?: $root, '/\\');

        return $path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR);
    }

    /** Load the .env.{sld}, .env.{sub}, .env.{sub}.{sld} files under $path. */
    private static function loadDomainTier(string $path, ?string $sld, ?string $sub): void
    {
        if ($sld === null) {
            return;
        }
        self::loadFile($path . '/.env.' . $sld);

        if ($sub !== null) {
            self::loadFile($path . '/.env.' . $sub);
            self::loadFile($path . '/.env.' . $sub . '.' . $sld);
        }
    }

    /**
     * Parse a single .env file and apply each key (mutable cascade, but real
     * pre-existing environment values are preserved). Missing files are skipped.
     */
    private static function loadFile(string $file): void
    {
        // Record the signature of EVERY candidate (present or absent) so the
        // cache can detect edits, additions and deletions on validation.
        self::$examined[$file] = is_file($file)
            ? [filemtime($file), filesize($file)]
            : false;

        if (!is_file($file) || !is_readable($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = ltrim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Optional "export " prefix.
            if (str_starts_with($line, 'export ')) {
                $line = ltrim(substr($line, 7));
            }

            $eq = strpos($line, '=');
            if ($eq === false) {
                continue;
            }

            $name = trim(substr($line, 0, $eq));
            if ($name === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_.]*$/', $name) !== 1) {
                continue;
            }

            $value = self::parseValue(substr($line, $eq + 1));
            self::setVar($name, $value);
        }
    }

    /** Unquote / strip inline comments / interpolate ${VAR} references. */
    private static function parseValue(string $raw): string
    {
        $raw = trim($raw);

        // Empty value, or a value that is ENTIRELY an inline comment
        // (e.g. `KEY=   # note`, which trims to `# note`) → empty string.
        if ($raw === '' || $raw[0] === '#') {
            return '';
        }

        $quote = $raw[0];
        if ($quote === '"' || $quote === '\'') {
            $end = strrpos($raw, $quote);
            $inner = $end > 0 ? substr($raw, 1, $end - 1) : substr($raw, 1);

            if ($quote === '"') {
                // Double quotes: process escapes and interpolate.
                $inner = strtr($inner, [
                    '\\n' => "\n", '\\r' => "\r", '\\t' => "\t",
                    '\\"' => '"', '\\\\' => '\\',
                ]);
                return self::interpolate($inner);
            }

            // Single quotes: literal.
            return $inner;
        }

        // Unquoted: strip a trailing inline comment ( value # comment ).
        $hash = strpos($raw, ' #');
        if ($hash !== false) {
            $raw = rtrim(substr($raw, 0, $hash));
        }

        return self::interpolate($raw);
    }

    /** Expand ${VAR} (and bare $VAR) using already-loaded values. */
    private static function interpolate(string $value): string
    {
        if (!str_contains($value, '$')) {
            return $value;
        }

        return (string) preg_replace_callback(
            '/\$\{([A-Za-z_][A-Za-z0-9_.]*)\}|\$([A-Za-z_][A-Za-z0-9_.]*)/',
            static fn (array $m): string => (string) (self::current($m[1] !== '' ? $m[1] : $m[2]) ?? ''),
            $value,
        );
    }

    /**
     * Set a variable into the in-memory surfaces, unless it was already provided
     * by the real environment before the loader started (server/OS config wins).
     *
     * putenv() is deliberately NOT called by default: it is ~98% of injection cost
     * (~1.7µs/var vs ~0.04µs for the array writes) and is coroutine-unsafe under
     * OpenSwoole. $_ENV is the source of truth; first-party code reads via env().
     * Enable putenv() only for third-party SDKs that read the real OS environment
     * directly (e.g. some AWS/Vault clients) via useProcessEnv(true).
     */
    private static function setVar(string $name, string $value): void
    {
        $realEnv = getenv($name);
        if ($realEnv !== false && !isset($_ENV[$name]) && !isset($_SERVER[$name])) {
            // Present in the real environment but not injected by us → keep it.
            return;
        }

        $_ENV[$name]      = $value;
        $_SERVER[$name]   = $value;
        self::$collected[$name] = $value;

        if (self::$processEnv) {
            putenv($name . '=' . $value);
        }
    }

    /** Current value from any access surface. */
    private static function current(string $name): ?string
    {
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return ($v === false || $v === null) ? null : (string) $v;
    }

    // ── Compiled cache ──────────────────────────────────────────────────────

    /**
     * Resolve the compiled cache file path. Scoped by host + APP_ENV hint so
     * different domains/environments never share a cache. Lives under the
     * project's var/cache when a project matched, else the repo root's.
     */
    private static function cachePath(
        string $rootPath,
        ?DomainContext $domain,
        ?string $host,
        ?string $envHint,
    ): string {
        $scope = ($host !== null ? str_replace([':', '.'], '_', strtolower($host)) : 'cli')
            . '.' . ($envHint !== null && $envHint !== '' ? preg_replace('/[^A-Za-z0-9_\-]/', '_', $envHint) : 'default');

        $dir = ($domain !== null && !$domain->isPlatformOnly())
            ? rtrim($domain->projectPath, '/\\') . '/var/cache'
            : $rootPath . '/var/cache';

        return $dir . '/env.' . $scope . '.php';
    }

    /**
     * Try the compiled cache. Returns true only when every examined path still
     * matches its recorded signature (mtime+size, or still-absent). On a hit,
     * the cached values are applied via setVar (so real-env-wins still holds).
     */
    private static function applyFromCache(string $cacheFile): bool
    {
        if (!is_file($cacheFile)) {
            return false;
        }

        $data = @include $cacheFile;
        if (!is_array($data) || !isset($data['__env'], $data['__sig']) || !is_array($data['__env']) || !is_array($data['__sig'])) {
            return false;
        }

        foreach ($data['__sig'] as $path => $sig) {
            $now = is_file($path) ? [filemtime($path), filesize($path)] : false;
            if ($now !== $sig) {
                return false; // edited / added / deleted → stale
            }
        }

        foreach ($data['__env'] as $name => $value) {
            if (is_string($name)) {
                self::setVar($name, (string) $value);
            }
        }

        return true;
    }

    /** Write the resolved values + examined-file signatures to the compiled cache. */
    private static function writeCache(string $cacheFile): void
    {
        $dir = dirname($cacheFile);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            return; // cache is best-effort — never fatal
        }

        $payload  = ['__env' => self::$collected, '__sig' => self::$examined];
        $contents = "<?php\n// Sentinel env cache — generated " . date('c')
            . "\n// DO NOT EDIT. Auto-invalidated when any source .env changes.\n"
            . 'return ' . var_export($payload, true) . ";\n";

        $tmp = $cacheFile . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $contents, LOCK_EX) === false) {
            return;
        }
        @chmod($tmp, 0640);

        if (!@rename($tmp, $cacheFile)) {
            @unlink($tmp);
            return;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($cacheFile, true);
        }
    }

    /**
     * Split a host into [sld, subdomain].
     *   api.hkmcod.com       → ['hkmcod', 'api']
     *   hkmcod.com           → ['hkmcod', null]
     *   admin.api.hkmcod.com → ['hkmcod', 'admin.api']
     *   localhost / null     → [null, null]
     *
     * @return array{0: ?string, 1: ?string}
     */
    private static function hostParts(?string $host): array
    {
        if ($host === null || $host === '') {
            return [null, null];
        }

        $host  = strtolower(explode(':', $host)[0]); // strip port
        $parts = explode('.', rtrim($host, '.'));

        if (count($parts) < 2) {
            return [null, null];
        }

        $sld = $parts[count($parts) - 2];
        $sub = count($parts) >= 3 ? implode('.', array_slice($parts, 0, -2)) : null;

        return [$sld, $sub];
    }

    /** Read a "--opt value" or "--opt=value" CLI option from $argv. */
    private static function cliOption(array $argv, string $opt): ?string
    {
        $prefix = $opt . '=';
        $n = count($argv);

        for ($i = 0; $i < $n; $i++) {
            $token = $argv[$i];
            if (!is_string($token)) {
                continue;
            }
            if ($token === $opt) {
                $next = $argv[$i + 1] ?? null;
                return is_string($next) ? $next : null;
            }
            if (str_starts_with($token, $prefix)) {
                return substr($token, strlen($prefix));
            }
        }

        return null;
    }
}
