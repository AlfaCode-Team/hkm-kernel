<?php

declare(strict_types=1);

if (!function_exists('edge_config')) {
    /**
     * Read the edge configuration (config/edge.php).
     *
     *   edge_config();                    // full array
     *   edge_config('listen');            // 443
     *   edge_config('upstreams.nginx');   // dotted access
     *   edge_config('paths.stream', '…'); // value, or fallback if absent
     *
     * Backed by the compiled config manifest, so a project's
     * projects/<name>/config/edge.php is DEEP-MERGED over the plugin default —
     * overriding one upstream keeps the rest of the shipped config, which the
     * previous project-file-replaces-plugin-file lookup silently discarded.
     *
     * @return mixed the whole config array, or a single (dotted) key's value
     */
    function edge_config(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            $all = function_exists('config') ? config('edge') : null;

            return is_array($all) && $all !== [] ? $all : edge_config_fallback();
        }

        if (function_exists('config')) {
            $value = config('edge.' . $key, $sentinel = new stdClass());
            if ($value !== $sentinel) {
                return $value;
            }
        }

        // No manifest compiled (e.g. a unit test that never ran the BootPipeline).
        $config = edge_config_fallback();
        foreach (explode('.', $key) as $segment) {
            if (!is_array($config) || !array_key_exists($segment, $config)) {
                return $default;
            }
            $config = $config[$segment];
        }

        return $config;
    }
}

if (!function_exists('edge_config_fallback')) {
    /**
     * The plugin's shipped defaults, used only when no config manifest exists.
     *
     * @return array<string, mixed>
     */
    function edge_config_fallback(): array
    {
        static $config = null;

        if ($config === null) {
            $loaded = require __DIR__ . '/../config/edge.php';
            $config = is_array($loaded) ? $loaded : [];
        }

        return $config;
    }
}
