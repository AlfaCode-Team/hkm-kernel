<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot;

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * BootStamp — lets `Kernel::build()` skip recompiling manifests that are already
 * current.
 *
 * WHY
 * ---
 * build() is compile-only, but it has no idempotence: under PHP-FPM every
 * request re-executes bootstrap/app.php, so the whole BootPipeline runs again —
 * reading every module.json, globbing every config directory, and rewriting the
 * manifests. On a ~130-route application that is ~2 ms and ~150 KB of file
 * writes PER REQUEST, for output that is byte-identical to the last one.
 *
 * Under OpenSwoole this never mattered (build runs once per worker), which is
 * exactly why it went unnoticed.
 *
 * HOW
 * ---
 * After a real compile, the stamp records:
 *   - a hash of the BUILDER inputs (module list, project routes/groups/domains,
 *     disable policy, paths) — so changing bootstrap/app.php or proj.json
 *     invalidates it without stat'ing anything;
 *   - every source FILE the compile read, by mtime+size — module.json files and
 *     config/*.php;
 *   - the *.php COUNT of every config directory scanned, so ADDING or REMOVING
 *     a config file invalidates too (a new file appears in no existing entry);
 *   - the resolved essential-module classes, which are otherwise recomputed by
 *     re-reading every module.json a second time.
 *
 * OPT-IN, LIKE ENV_CACHE
 * ----------------------
 * Off unless `BOOT_CACHE` is truthy, for the same reason the .env cache is off
 * by default: mtime has one-second granularity, which is not safe against a file
 * being edited twice within the same second — normal while developing, vanishing
 * risk on a deployed release. Enable it in production; clear
 * `var/cache/manifests/` on deploy.
 *
 * WHAT A CACHED BOOT DOES NOT RE-CHECK
 * ------------------------------------
 * The validation stages that touch no disk (ports, security layers) still run on
 * every build. The stages that read module.json do not — so an env var that a
 * module declares in `config[]` and that disappears from the environment is not
 * re-detected until the cache is cleared. That is the trade the flag buys.
 */
final class BootStamp
{
    private const FILE = 'boot-stamp.php';

    /**
     * Manifests that must ALL exist for a cached boot to be usable at all.
     *
     * More than one because the list also guards KERNEL UPGRADES. A cache
     * written by an older kernel carries a stamp whose hash still matches (the
     * builder inputs did not change), so it would be accepted — while a
     * manifest that version never compiled is simply absent, and the stage
     * reading it would quietly do nothing. Naming each manifest here makes an
     * older cache fail the check and recompile, instead of the new feature
     * being silently inert until someone clears var/cache by hand.
     */
    private const SENTINELS = [
        'manifests/route-manifest.php',
        'manifests/files-manifest.php',
    ];

    /** Whether the compile may be skipped — `BOOT_CACHE` truthy. */
    public static function enabled(): bool
    {
        $value = \function_exists('env') ? env('BOOT_CACHE') : null;

        if ($value === null || $value === '') {
            return false;
        }

        return (bool) filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * Hash of everything the builder itself contributes. Anything here changing
     * invalidates the cache without a single stat() call.
     *
     * @param array<string, mixed> $inputs
     */
    public static function hash(array $inputs): string
    {
        return hash('sha256', serialize($inputs));
    }

    /**
     * The compiled manifests are current for these inputs.
     *
     * @return array{essentials: list<string>}|null the cached derivations, or
     *         null when a real compile is required
     */
    public static function read(string $configHash): ?array
    {
        foreach (self::SENTINELS as $sentinel) {
            if (!is_file(Paths::cache($sentinel))) {
                return null;
            }
        }

        $stamp = ManifestReader::readCompiled(self::FILE);

        if (($stamp['config'] ?? null) !== $configHash) {
            return null;
        }

        foreach ($stamp['files'] ?? [] as $path => $signature) {
            if (self::signature((string) $path) !== $signature) {
                return null;
            }
        }

        // A config file ADDED since the last compile appears in no entry above,
        // so compare how many each watched directory holds.
        foreach ($stamp['dirs'] ?? [] as $dir => $count) {
            if (self::countPhp((string) $dir) !== $count) {
                return null;
            }
        }

        return ['essentials' => $stamp['essentials'] ?? []];
    }

    /**
     * Record a successful compile.
     *
     * @param list<string> $sourceFiles module.json paths the compile read
     * @param list<string> $essentials  resolved essential-module classes
     */
    public static function write(string $configHash, array $sourceFiles, array $essentials): void
    {
        $files = [];
        $dirs  = [];

        foreach ($sourceFiles as $file) {
            $files[$file] = self::signature($file);

            // Each module.json sits beside the module's own config/ directory,
            // which CompileConfigManifestStage globs.
            self::watchConfigDir(dirname($file) . '/config', $files, $dirs);
        }

        self::watchConfigDir(Paths::config(), $files, $dirs);

        ManifestWriter::write(self::FILE, [
            'config'     => $configHash,
            'files'      => $files,
            'dirs'       => $dirs,
            'essentials' => array_values($essentials),
        ]);
    }

    /**
     * @param array<string, string> $files
     * @param array<string, int>    $dirs
     */
    private static function watchConfigDir(string $dir, array &$files, array &$dirs): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $found = glob(rtrim($dir, '/') . '/*.php') ?: [];

        $dirs[$dir] = count($found);

        foreach ($found as $file) {
            $files[$file] = self::signature($file);
        }
    }

    /** mtime:size, or '' when the file is gone — which invalidates. */
    private static function signature(string $path): string
    {
        $stat = @stat($path);

        return $stat === false ? '' : $stat['mtime'] . ':' . $stat['size'];
    }

    private static function countPhp(string $dir): int
    {
        return is_dir($dir) ? count(glob(rtrim($dir, '/') . '/*.php') ?: []) : -1;
    }
}
