<?php

declare(strict_types=1);

use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

if (!function_exists('edge_config')) {
    /**
     * Read the edge configuration (config/edge.php), cached per process. A
     * project copy at projects/<name>/config/edge.php wins over the plugin
     * default.
     *
     *   edge_config();                    // full array
     *   edge_config('listen');            // 443
     *   edge_config('upstreams.nginx');   // dotted access
     *   edge_config('paths.stream', '…'); // value, or fallback if absent
     *
     * @return mixed the whole config array, or a single (dotted) key's value
     */
    function edge_config(?string $key = null, mixed $default = null): mixed
    {
        /** @var array<string, mixed>|null $config */
        static $config = null;

        if ($config === null) {
            $projectFile = Paths::config('edge.php');
            $pluginFile  = __DIR__ . '/../config/edge.php';

            $file   = is_file($projectFile) ? $projectFile : $pluginFile;
            $loaded = require $file;
            $config = is_array($loaded) ? $loaded : [];
        }

        if ($key === null) {
            return $config;
        }

        $value = $config;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
