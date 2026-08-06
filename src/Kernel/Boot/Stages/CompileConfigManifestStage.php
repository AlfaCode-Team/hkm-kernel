<?php declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Boot\{BootException, ManifestReader, ManifestWriter};
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * Compiles every `config/*.php` file into one config-manifest.php.
 *
 * WHY THIS EXISTS
 * ---------------
 * Before this stage there was no configuration reader at all, so each plugin
 * hand-rolled the same lookup:
 *
 *     $path = function_exists('config_path') && is_file(config_path('mail.php'))
 *           ? config_path('mail.php') : __DIR__ . '/config/mail.php';
 *
 * — repeated across Mail, Validation, Storage, Edge and Authorization, with no
 * caching, no dotted access, and an all-or-nothing override (a project wanting
 * to change ONE key had to copy the plugin's whole file and then silently drift
 * from it on every upgrade).
 *
 * PRECEDENCE — project over plugin, the same rule as routes and views
 * -------------------------------------------------------------------
 *   1. plugin  {plugin}/config/{group}.php   compiled first
 *   2. project {project}/config/{group}.php  DEEP-MERGED over the plugin's
 *
 * The merge is recursive for associative arrays and replace-wholesale for lists.
 * That distinction matters: a project overriding `mail.from.address` keeps the
 * plugin's other `mail.*` defaults, but a project declaring `mail.transports`
 * as a list REPLACES the list rather than appending to it — appending would make
 * it impossible to REMOVE a default entry.
 *
 * Output (config-manifest.php):
 *   [ 'mail' => [...], 'validation' => [...], 'storage' => [...] ]
 *
 * Group name = the file's basename. `config/mail.php` is read as `mail.*`.
 */
final class CompileConfigManifestStage implements BootStageContract
{
    /** @param list<class-string> $moduleClasses */
    public function __construct(
        private readonly array $moduleClasses,
        private readonly ManifestReader $reader = new ManifestReader(),
    ) {}

    public function run(): void
    {
        $config = [];

        // ── PLUGIN config (defaults) ─────────────────────────────────────────
        foreach ($this->moduleClasses as $moduleClass) {
            foreach ($this->filesIn($this->moduleDir($moduleClass) . '/config') as $group => $file) {
                $loaded = $this->load($file);
                $config[$group] = isset($config[$group]) && is_array($config[$group])
                    ? $this->merge($config[$group], $loaded)
                    : $loaded;
            }
        }

        // ── PROJECT config (overrides) ───────────────────────────────────────
        // Deep-merged LAST so a project always wins, and only for the keys it
        // actually names.
        foreach ($this->filesIn(Paths::config()) as $group => $file) {
            $loaded = $this->load($file);
            $config[$group] = isset($config[$group]) && is_array($config[$group])
                ? $this->merge($config[$group], $loaded)
                : $loaded;
        }

        ManifestWriter::write('config-manifest.php', $config);
    }

    /**
     * Every `*.php` in a config directory, keyed by group name.
     *
     * @return array<string, string> group => absolute file path
     */
    private function filesIn(string $dir): array
    {
        if (!is_dir($dir)) {
            return [];
        }

        $found = glob(rtrim($dir, '/') . '/*.php');
        if ($found === false) {
            return [];
        }

        sort($found); // deterministic order regardless of filesystem listing

        $files = [];
        foreach ($found as $file) {
            $files[basename($file, '.php')] = $file;
        }

        return $files;
    }

    /** @return array<string, mixed> */
    private function load(string $file): array
    {
        $value = require $file;

        if (!is_array($value)) {
            throw new BootException(
                "Config file [{$file}] must return an array, got " . get_debug_type($value) . '.'
            );
        }

        return $value;
    }

    /**
     * Recursive merge with LIST-REPLACE semantics.
     *
     * Associative arrays merge key by key (so an override names only what it
     * changes). Lists are replaced wholesale — appending would leave no way to
     * remove a default entry, which is usually the point of overriding a list.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (
                is_array($value)
                && isset($base[$key])
                && is_array($base[$key])
                && !array_is_list($value)
            ) {
                $base[$key] = $this->merge($base[$key], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
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
