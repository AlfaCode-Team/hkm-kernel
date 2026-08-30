<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};

/**
 * Compiles the list of plain PHP FILES each module needs loaded — helper files
 * that define global functions, which no class autoloader can ever reach.
 *
 * WHY THIS EXISTS
 * ---------------
 * A plugin is loaded by the KERNEL, not by Composer: plugins are symlinked into
 * `plugins/` and reached through the project's PSR-4 `Plugins\` map, so no
 * plugin's own composer.json is ever read. That is fine for classes and fatal
 * for functions — a plugin declaring
 *
 *     "autoload": { "files": ["Engine/functions.php"] }
 *
 * has declared it in the one place nothing looks. The functions are simply never
 * defined, and PHP does not complain until something calls one, at which point
 * it falls back to the global namespace and dies with
 * "Call to undefined function ...". Projects have been hand-patching this with a
 * `require_once` in bootstrap/app.php next to a comment explaining why — which
 * works exactly once, in the one project that noticed.
 *
 * DECLARING FILES
 * ---------------
 * First-class, in module.json, alongside everything else the kernel reads:
 *
 *     { "files": ["Engine/functions.php"] }
 *
 * Paths are relative to the module directory (where Provider.php lives). A
 * declared file that does not exist FAILS THE BOOT — it is a kernel contract,
 * and the whole point is that a missing helper stops being a silent runtime
 * fatal.
 *
 * COMPOSER FALLBACK
 * -----------------
 * When a module.json declares no `files`, the module's own composer.json
 * `autoload.files` is used instead. Plugins already declare their helpers there
 * correctly; honouring it means they work with no plugin change at all. Because
 * composer.json is not the kernel's contract, a path listed there that does not
 * exist is SKIPPED rather than failing the boot — the kernel is being generous
 * with someone else's manifest, not enforcing it.
 *
 * Output (files-manifest.php): a flat, ordered list of absolute paths.
 * {@see LoadModuleFilesStage} is what actually requires them, on every build,
 * cached or not.
 */
final class CompileModuleFilesStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        $files = [];

        foreach ($this->moduleClasses as $moduleClass) {
            $manifest = $this->reader->read($moduleClass);
            $dir      = $this->moduleDir($moduleClass);

            $declared = $manifest['files'] ?? null;

            if (is_array($declared) && $declared !== []) {
                foreach ($declared as $relative) {
                    if (!is_string($relative) || trim($relative) === '') {
                        throw new BootException(
                            "module.json \"files\" for [{$moduleClass}] must contain non-empty strings, "
                            . 'got ' . get_debug_type($relative) . '.'
                        );
                    }

                    $path = $dir . '/' . ltrim($relative, '/');
                    if (!is_file($path)) {
                        throw new BootException(
                            "module.json \"files\" for [{$moduleClass}] declares [{$relative}], "
                            . "which does not exist at {$path}."
                        );
                    }

                    $files[$this->key($path)] = $path;
                }

                continue;
            }

            // No explicit declaration — honour the module's own composer.json.
            foreach ($this->composerFiles($dir) as $path) {
                $files[$this->key($path)] = $path;
            }
        }

        ManifestWriter::write('files-manifest.php', array_values($files));
    }

    /**
     * `autoload.files` from the module's composer.json, resolved to absolute
     * paths. Missing file, missing/invalid composer.json and a malformed
     * `autoload.files` all yield nothing rather than failing: this is a
     * best-effort read of a manifest the kernel does not own.
     *
     * @return list<string>
     */
    private function composerFiles(string $dir): array
    {
        $composer = $dir . '/composer.json';
        if (!is_file($composer)) {
            return [];
        }

        $raw     = file_get_contents($composer);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }

        $declared = $decoded['autoload']['files'] ?? null;
        if (!is_array($declared)) {
            return [];
        }

        $files = [];
        foreach ($declared as $relative) {
            if (!is_string($relative) || trim($relative) === '') {
                continue;
            }

            $path = $dir . '/' . ltrim($relative, '/');
            if (is_file($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    /**
     * De-duplication key. Two modules living in the same directory (or one
     * reachable by two paths) must not have their helpers required twice —
     * require_once already guards that, but keeping the manifest itself clean
     * means the list stays readable and its order stays meaningful.
     */
    private function key(string $path): string
    {
        return realpath($path) ?: $path;
    }

    private function moduleDir(string $moduleClass): string
    {
        $file = (new \ReflectionClass($moduleClass))->getFileName();
        if ($file === false) {
            throw new BootException("Cannot locate source file for [{$moduleClass}].");
        }

        return dirname($file);
    }
}
