<?php

declare(strict_types=1);

/**
 * Edge routing configuration (config/edge.php).
 *
 * A project copy at projects/<name>/config/edge.php overrides this default.
 * Everything is env-driven; the defaults are safe for local development (the
 * generated files land under var/edge/ so no root is needed to write them —
 * point EDGE_*_PATH at /etc/nginx or /etc/apache2 in production).
 */
$__edgeProjectsDir = (static function (): string {
    // Edge is a HOST/control-plane tool: it must read the GLOBAL project registry
    // (every project + its domains), which lives in the kernel home — NOT the
    // per-project base_path. Resolution order: explicit override → PSP_PROJECTS_DIR
    // → HKM_KERNEL_HOME/projects → base_path('projects').
    $explicit = (string) env('EDGE_PROJECTS_DIR', '');
    if ($explicit !== '') {
        return rtrim($explicit, '/');
    }
    $psp = (string) env('PSP_PROJECTS_DIR', '');
    if ($psp !== '') {
        return rtrim($psp, '/');
    }
    $home = (string) env('HKM_KERNEL_HOME', '');
    if ($home !== '') {
        return rtrim($home, '/') . '/projects';
    }
    return base_path('projects');
})();

return [
    // The public TLS port the edge listens on.
    'listen' => (int) (env('EDGE_LISTEN_PORT') ?: 443),

    // Backends the traffic is routed to.
    'upstreams' => [
        // Where nginx terminates TLS for the platform's own domains.
        'nginx'  => (string) (env('EDGE_NGINX_BACKEND') ?: '127.0.0.1:444'),
        // Fallback web server (Apache) for everything not owned by the platform.
        'apache' => (string) (env('EDGE_APACHE_BACKEND') ?: '127.0.0.1:8443'),
        // The application backend nginx/Apache reverse-proxy to (Swoole http or
        // a plain listener). For PHP-FPM use fastcgi in your own vhost instead.
        'app'    => (string) (env('EDGE_APP_BACKEND') ?: '127.0.0.1:8080'),
    ],

    // TLS material used by the nginx-only and Apache-only templates.
    'ssl' => [
        'cert' => (string) (env('EDGE_SSL_CERT') ?: '/etc/ssl/certs/hkm-edge.pem'),
        'key'  => (string) (env('EDGE_SSL_KEY')  ?: '/etc/ssl/private/hkm-edge.key'),
    ],

    // Where each rendered config is written. Override to /etc/nginx/... in prod.
    'paths' => [
        'stream' => (string) (env('EDGE_STREAM_PATH') ?: base_path('var/edge/hkm-edge-stream.conf')),
        'nginx'  => (string) (env('EDGE_NGINX_PATH')  ?: base_path('var/edge/hkm-edge-nginx.conf')),
        'apache' => (string) (env('EDGE_APACHE_PATH') ?: base_path('var/edge/hkm-edge-apache.conf')),
    ],

    // Validation + reload commands (configurable per distro / init system).
    'commands' => [
        'nginx_test'    => (string) (env('EDGE_NGINX_TEST_CMD')    ?: 'nginx -t'),
        'nginx_reload'  => (string) (env('EDGE_NGINX_RELOAD_CMD')  ?: 'nginx -s reload'),
        'apache_test'   => (string) (env('EDGE_APACHE_TEST_CMD')   ?: 'apachectl configtest'),
        'apache_reload' => (string) (env('EDGE_APACHE_RELOAD_CMD') ?: 'apachectl graceful'),
    ],

    // Reload the web server after writing (edge:apply). Can also be forced/ skipped
    // with CLI flags. Off by default so a bare `edge:apply` never touches a live
    // server unless you opt in.
    'reload' => filter_var(env('EDGE_RELOAD', 'false'), FILTER_VALIDATE_BOOL),

    // Domain sources. The registries are read automatically; extra/exclude let
    // you add or drop hostnames without editing the registry.
    'projects_registry' => $__edgeProjectsDir . '/projects.json',
    'platform_registry' => $__edgeProjectsDir . '/platform.json',
    'extra_domains'   => array_values(array_filter(array_map('trim', explode(',', (string) env('EDGE_EXTRA_DOMAINS', ''))))),
    'exclude_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('EDGE_EXCLUDE_DOMAINS', ''))))),

    // Local (dev-only) domains. A domain whose TLD is in this list — or that has
    // no dot at all — is treated as LOCAL: it is kept OUT of the public server
    // config and written to /etc/hosts instead (pointing at the loopback).
    'local_tlds' => array_values(array_filter(array_map('trim', explode(',', (string) env('EDGE_LOCAL_TLDS', 'local,test,localhost,example,invalid'))))),

    // Write local domains into /etc/hosts on apply (needs privileges to edit it).
    'manage_hosts' => filter_var(env('EDGE_MANAGE_HOSTS', 'true'), FILTER_VALIDATE_BOOL),
    'hosts' => [
        'path' => (string) (env('EDGE_HOSTS_PATH') ?: '/etc/hosts'),
        'ip'   => (string) (env('EDGE_HOSTS_IP') ?: '127.0.0.1'),
    ],

    // Set true to ALSO include local domains in the generated server config
    // (e.g. when nginx serves your .local sites in local development).
    'include_local_in_server' => filter_var(env('EDGE_LOCAL_IN_SERVER', 'false'), FILTER_VALIDATE_BOOL),

    // How each project is served (per-project override via proj.json "edge").
    'serve' => [
        'model'            => (string) (env('EDGE_SERVE_MODEL') ?: 'fpm'),   // fpm | swoole
        // Empty = auto-resolve the FPM socket matching the CLI PHP version
        // (multi-PHP hosts). Set explicitly to pin a socket/addr.
        'fpm_socket'       => (string) env('EDGE_FPM_SOCKET', ''),
        'swoole_host'      => (string) (env('EDGE_SWOOLE_HOST') ?: '127.0.0.1'),
        'swoole_base_port' => (int) (env('EDGE_SWOOLE_BASE_PORT') ?: 9500),
    ],

    // Inject the kernel-resolution env into each vhost so FPM workers (which do
    // NOT inherit your shell/hkm environment) boot against the correct kernel.
    'inject_kernel_env' => filter_var(env('EDGE_INJECT_KERNEL_ENV', 'true'), FILTER_VALIDATE_BOOL),

    // APP_ENV written into each vhost.
    'app_env' => (string) (env('EDGE_APP_ENV') ?: env('APP_ENV') ?: 'production'),

    // The launcher (`hkm run` / `hkm cli`) ALREADY exports the kernel-resolution
    // env for the active context — dev (HKM_DEV_HOME + the checkout) vs live (the
    // installed kernel). Edge simply PASSES THROUGH whichever of these are present
    // in the environment; it does not derive, default, or configure them. So a
    // dev run naturally carries HKM_DEV_HOME, a live run carries the installed
    // paths, and PSP_PROJECTS_DIR only appears if you actually set it.
    'kernel_env_keys' => [
        'HKM_KERNEL_HOME',
        'HKM_DEV_HOME',
        'HKM_USERDATA_DIR',
        'PSP_GLOBAL_AUTOLOAD',
        'PSP_PROJECTS_DIR',
    ],
];
