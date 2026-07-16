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
    'projects_registry' => base_path('projects/projects.json'),
    'platform_registry' => base_path('projects/platform.json'),
    'extra_domains'   => array_values(array_filter(array_map('trim', explode(',', (string) env('EDGE_EXTRA_DOMAINS', ''))))),
    'exclude_domains' => array_values(array_filter(array_map('trim', explode(',', (string) env('EDGE_EXCLUDE_DOMAINS', ''))))),
];
