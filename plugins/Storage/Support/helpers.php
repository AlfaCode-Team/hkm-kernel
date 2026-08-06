<?php

declare(strict_types=1);

if (!function_exists('storage_config')) {
    /**
     * Read the storage configuration (config/storage.php).
     *
     *   storage_config();              // full array
     *   storage_config('driver');      // 'local' | 's3'
     *   storage_config('s3.bucket');   // dotted access into a section
     *   storage_config('local.root', '/tmp')   // value, or fallback if absent
     *
     * Backed by the compiled config manifest, so a project's
     * projects/<name>/config/storage.php is DEEP-MERGED over the plugin default:
     * overriding `s3.bucket` no longer discards the rest of the shipped config,
     * which the previous project-file-replaces-plugin-file lookup did silently.
     *
     * @return mixed the whole config array, or a single (dotted) key's value
     */
    function storage_config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            $all = function_exists('config') ? config('storage') : null;

            return is_array($all) && $all !== [] ? $all : storage_config_fallback();
        }

        if (function_exists('config')) {
            $value = config('storage.' . $key, $sentinel = new stdClass());
            if ($value !== $sentinel) {
                return $value;
            }
        }

        // No manifest compiled (e.g. a unit test that never ran the BootPipeline).
        $config = storage_config_fallback();
        foreach (explode('.', $key) as $segment) {
            if (!is_array($config) || !array_key_exists($segment, $config)) {
                return $default;
            }
            $config = $config[$segment];
        }

        return $config;
    }
}

if (!function_exists('storage_config_fallback')) {
    /**
     * The plugin's shipped defaults, used only when no config manifest exists.
     *
     * @return array<string, mixed>
     */
    function storage_config_fallback(): array
    {
        static $config = null;

        if ($config === null) {
            $loaded = require __DIR__ . '/../config/storage.php';
            $config = is_array($loaded) ? $loaded : [];
        }

        return $config;
    }
}
