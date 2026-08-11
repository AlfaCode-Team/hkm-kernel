<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Boot;

/**
 * ManifestReader — loads and caches a module's module.json.
 *
 * Reading from module.json (the single source of truth) instead of
 * instantiating the Provider keeps boot side-effect free and means Providers
 * are never required to have a parameterless constructor.
 */
final class ManifestReader
{
    /** @var array<class-string, array<string, mixed>> */
    private array $cache = [];

    /**
     * Absolute module.json paths this reader has read, in first-seen order.
     *
     * BootStamp records them (with mtime+size) so a later build can tell whether
     * anything the compile depended on has actually changed.
     *
     * @var array<string, true>
     */
    private array $files = [];

    /**
     * @param class-string $moduleClass
     * @return array<string, mixed>
     * @throws BootException when the file is missing or invalid
     */
    public function read(string $moduleClass): array
    {
        if (isset($this->cache[$moduleClass])) {
            return $this->cache[$moduleClass];
        }

        $ref  = new \ReflectionClass($moduleClass);
        $file = $ref->getFileName();
        if ($file === false) {
            throw new BootException("Cannot locate source file for [{$moduleClass}].");
        }

        $path = dirname($file) . '/module.json';
        if (!is_file($path)) {
            throw new BootException("module.json not found for [{$moduleClass}] — expected at {$path}");
        }

        $this->files[$path] = true;

        $raw     = file_get_contents($path);
        $decoded = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new BootException("module.json is invalid JSON for [{$moduleClass}] at {$path}");
        }

        return $this->cache[$moduleClass] = $decoded;
    }

    /**
     * Every module.json this reader has read, absolute paths.
     *
     * @return list<string>
     */
    public function files(): array
    {
        return array_keys($this->files);
    }

    /**
     * Read a COMPILED manifest written by {@see ManifestWriter} (route-manifest.php,
     * config-manifest.php, …). The counterpart to ManifestWriter::write().
     *
     * A missing manifest returns $default rather than throwing: a surface that
     * never compiled its manifest (an HTTP-only process has no job manifest) must
     * not fail, and boot compiles every manifest before anything reads one.
     *
     * @param  array<string, mixed> $default
     * @return array<string, mixed>
     */
    public static function readCompiled(string $file, array $default = []): array
    {
        $path = \AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths::cache('manifests/' . ltrim($file, '/'));
        if (!is_file($path)) {
            return $default;
        }

        $data = require $path;

        return is_array($data) ? $data : $default;
    }
}
