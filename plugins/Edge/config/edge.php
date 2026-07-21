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

    // The public plain-HTTP port (used by tls=none, and the redirect vhost of
    // tls=both).
    'http' => (int) (env('EDGE_HTTP_PORT') ?: 80),

    // Default TLS mode for the rendered vhosts (overridable per `edge:apply` run):
    //   ssl  — HTTPS only (:443)                       [default]
    //   none — plain HTTP only (:80, no certificate)
    //   both — plain :80 that 301-redirects to HTTPS (:443)
    'tls' => [
        'mode' => (string) (env('EDGE_TLS_MODE') ?: 'ssl'),
    ],

    // Browser-cache strategy for the generated nginx vhost.
    //
    // The DEVELOPMENT vs PRODUCTION profile is derived from APP_ENV alone
    // (local/development → DEVELOPMENT, production → PRODUCTION, anything
    // unknown → DEVELOPMENT) — see Domain\CacheProfile. It is deliberately NOT
    // configurable here and never inferred from the kernel mode (HKM_DEV).
    //
    //   DEVELOPMENT → HTML, index.php AND every asset are no-store, so a refresh
    //                 always refetches the latest CSS/JS/JSON/images.
    //   PRODUCTION  → never cache HTML/index.php, but cache the fingerprinted
    //                 static assets long-term & immutable.
    //
    // These are independent knobs so the generator adapts without code changes:
    //   browser_assets      cache versioned static assets at all (prod)
    //   browser_assets_ttl  how long, in seconds (default 1 year)
    //   browser_html        cache HTML/dynamic responses (almost always false)
    //   cloudflare          add `immutable` to asset Cache-Control (CDN-friendly)
    'cache' => [
        'browser_assets'     => filter_var(env('EDGE_CACHE_ASSETS', 'true'), FILTER_VALIDATE_BOOL),
        'browser_assets_ttl' => (int) (env('EDGE_CACHE_ASSETS_TTL') ?: 31536000),
        'browser_html'       => filter_var(env('EDGE_CACHE_HTML', 'false'), FILTER_VALIDATE_BOOL),
        'cloudflare'         => filter_var(env('EDGE_CACHE_CLOUDFLARE', 'true'), FILTER_VALIDATE_BOOL),
    ],

    // HTTP-context prerequisites emitted ONCE at the top of the generated file:
    // the log_format, the rate-limit zones and the Cloudflare real-IP ranges that
    // the vhost directives below depend on.
    //
    // OPT-IN (default off): if your nginx.conf already declares `log_format
    // cf_realip` or `limit_req_zone … zone=general`, emitting them again is a
    // duplicate-definition error. Turn this on only when Edge owns those.
    'http_prelude' => [
        'enabled' => filter_var(env('EDGE_HTTP_PRELUDE', 'false'), FILTER_VALIDATE_BOOL),

        // log_format + the per-vhost access_log that uses it.
        'log_format'      => (string) (env('EDGE_LOG_FORMAT') ?: 'cf_realip'),
        'log_buffer'      => (string) (env('EDGE_LOG_BUFFER') ?: '32k'),
        'log_flush'       => (string) (env('EDGE_LOG_FLUSH') ?: '5s'),

        // limit_req_zone / limit_conn_zone + the vhost limit_req / limit_conn.
        'rate_limit' => [
            'enabled'    => filter_var(env('EDGE_RATE_LIMIT', 'true'), FILTER_VALIDATE_BOOL),
            'req_zone'   => (string) (env('EDGE_RATE_REQ_ZONE') ?: 'general'),
            'req_size'   => (string) (env('EDGE_RATE_REQ_SIZE') ?: '10m'),
            'req_rate'   => (string) (env('EDGE_RATE_REQ_RATE') ?: '10r/s'),
            'req_burst'  => (int) (env('EDGE_RATE_REQ_BURST') ?: 50),
            'req_nodelay' => filter_var(env('EDGE_RATE_REQ_NODELAY', 'true'), FILTER_VALIDATE_BOOL),
            'conn_zone'  => (string) (env('EDGE_RATE_CONN_ZONE') ?: 'perip'),
            'conn_size'  => (string) (env('EDGE_RATE_CONN_SIZE') ?: '10m'),
            'conn_limit' => (int) (env('EDGE_RATE_CONN_LIMIT') ?: 100),
        ],

        // Cloudflare: restore the visitor IP from CF-Connecting-IP so logs, rate
        // limits and deny rules see the real client instead of a CF edge node.
        // Ranges default to Cloudflare's published list (www.cloudflare.com/ips);
        // override with a comma-separated EDGE_CLOUDFLARE_RANGES.
        'cloudflare' => [
            'enabled' => filter_var(env('EDGE_CLOUDFLARE_REAL_IP', 'true'), FILTER_VALIDATE_BOOL),
            'header'  => (string) (env('EDGE_CLOUDFLARE_HEADER') ?: 'CF-Connecting-IP'),
            'ranges'  => array_values(array_filter(array_map('trim', explode(',', (string) env('EDGE_CLOUDFLARE_RANGES', ''))))),
        ],
    ],

    // Dev-only nginx vhost extras (verbose debug logging, permissive CORS,
    // Disallow-all robots.txt, /nginx-status). NULL (the default) means "follow
    // the APP_ENV cache profile" — set EDGE_DEV_VHOST=1/0 to force. Deliberately
    // NOT derived from HKM_DEV: kernel selection and app environment are
    // independent concerns.
    'dev_vhost' => ((string) env('EDGE_DEV_VHOST', '')) === ''
        ? null
        : filter_var(env('EDGE_DEV_VHOST'), FILTER_VALIDATE_BOOL),

    // Response compression preference. `auto` is resolved PER SERVER at render
    // time from that server's own capability — nginx from its ngx_brotli build,
    // Apache from its loaded mod_brotli — falling back to gzip; force with
    // EDGE_COMPRESSION = brotli | gzip | off. Brotli mode also emits a gzip block
    // as a fallback for clients without `br`. An explicit `brotli` still degrades
    // to gzip on a server that lacks the module, so a bad choice never breaks the
    // config test. An unrecognised value is treated as `auto`.
    'compression' => (static function (): string {
        $mode = strtolower(trim((string) env('EDGE_COMPRESSION', 'auto')));
        return in_array($mode, ['auto', 'brotli', 'gzip', 'off'], true) ? $mode : 'auto';
    })(),

    // HTTP Strict Transport Security. Emitted ONLY for TLS modes (ssl / both) —
    // never for plain HTTP. The DEVELOPMENT profile ALWAYS emits a short max-age
    // (dev_max_age, default 300s) with no includeSubDomains and no preload, so a
    // dev host is never pinned to HTTPS-for-a-year. PRODUCTION uses max_age (+ the
    // flags below). `preload` is a long-lived, hard-to-reverse commitment — it is
    // OPT-IN and never a silent default. max_age is in seconds (default 1 year).
    'hsts' => [
        'enabled'            => filter_var(env('EDGE_HSTS', 'true'), FILTER_VALIDATE_BOOL),
        'max_age'            => (int) (env('EDGE_HSTS_MAX_AGE') ?: 31536000),
        'dev_max_age'        => (int) (env('EDGE_HSTS_DEV_MAX_AGE') ?: 300),
        'include_subdomains' => filter_var(env('EDGE_HSTS_SUBDOMAINS', 'true'), FILTER_VALIDATE_BOOL),
        'preload'            => filter_var(env('EDGE_HSTS_PRELOAD', 'false'), FILTER_VALIDATE_BOOL),
    ],

    // Cross-Origin Resource Sharing for the generated vhosts. The wildcard is
    // OPT-IN: `*` combined with `Allow-Headers: Authorization` on a host reachable
    // beyond localhost lets any page a developer visits make authenticated
    // cross-origin reads. Prefer an explicit origin allowlist (echoed back via a
    // $http_origin map) over the wildcard.
    //   EDGE_CORS         = off (default) | allowlist | wildcard
    //   EDGE_CORS_ORIGINS = https://a.com,https://b.com   (allowlist mode)
    'cors' => [
        'mode'        => strtolower(trim((string) env('EDGE_CORS', 'off'))),
        'origins'     => array_values(array_filter(array_map('trim', explode(',', (string) env('EDGE_CORS_ORIGINS', ''))))),
        'methods'     => (string) (env('EDGE_CORS_METHODS') ?: 'GET, POST, PUT, DELETE, PATCH, OPTIONS'),
        'headers'     => (string) (env('EDGE_CORS_HEADERS') ?: 'Content-Type, Authorization, X-Requested-With'),
        'credentials' => filter_var(env('EDGE_CORS_CREDENTIALS', 'false'), FILTER_VALIDATE_BOOL),
    ],

    // Explicit TLS pinning for every generated TLS listener (both profiles).
    // Relying on the nginx build defaults has historically left TLS 1.0/1.1 on.
    // Keep stapling OFF for Cloudflare Origin CA certs (not publicly chained).
    'ssl_hardening' => [
        'protocols' => (string) (env('EDGE_SSL_PROTOCOLS') ?: 'TLSv1.2 TLSv1.3'),
        'ciphers'   => (string) (env('EDGE_SSL_CIPHERS') ?: ''), // empty = built-in modern default
        'stapling'  => filter_var(env('EDGE_SSL_STAPLING', 'false'), FILTER_VALIDATE_BOOL),
    ],

    // Directories denied (prefix-matched, before the static rule) so their files
    // can never be served even with a whitelisted extension. Per-project because
    // the right set is app-specific — `storage` is deliberately NOT in the default
    // (a Laravel-style public/storage symlink serves intended uploads); add it via
    // EDGE_DENY_DIRS where the app has no such symlink. Empty disables dir denies.
    'deny_dirs' => (static function (): array {
        $raw = env('EDGE_DENY_DIRS');
        if ($raw === null || $raw === '') {
            return ['vendor', 'node_modules', 'tests', '.git', '.github', 'bootstrap/cache'];
        }
        return array_values(array_filter(array_map('trim', explode(',', (string) $raw))));
    })(),

    // HTTP method allowlist emitted as a guard (`if ($request_method !~ …)`).
    // Defaults to the full REST set because these apps use PUT/PATCH/DELETE;
    // tighten to e.g. GET|HEAD|POST where an app only reads. Empty disables it.
    'allowed_methods' => (string) (env('EDGE_ALLOWED_METHODS') ?: 'GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS'),

    // error_log debug level is OPT-IN: it needs an nginx built --with-debug, is
    // hugely verbose, and can log session ids/tokens. Default level is warn.
    'debug_log' => filter_var(env('EDGE_NGINX_DEBUG_LOG', 'false'), FILTER_VALIDATE_BOOL),

    // Emit the /nginx-status stub_status location on DEVELOPMENT hosts. Never
    // emitted in production: behind the :443 SNI stream splitter every peer looks
    // like 127.0.0.1, so `allow 127.0.0.1` would expose it to the whole internet.
    'nginx_status' => filter_var(env('EDGE_NGINX_STATUS', 'true'), FILTER_VALIDATE_BOOL),

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

    // Force a single-server strategy, bypassing host auto-detection. Empty (the
    // default) = auto-detect. Set to `nginx-only` to serve everything through
    // nginx with NO Apache fallback, or `apache-only` for Apache with no fallback.
    // Overridable per run with `edge:apply --nginx-only` / `--apache-only`.
    'force_strategy' => (static function (): string {
        $v = strtolower(trim((string) env('EDGE_FORCE_STRATEGY', '')));
        return in_array($v, ['nginx-only', 'nginx', 'apache-only', 'apache'], true) ? $v : '';
    })(),

    // When both nginx and Apache are running and nginx ALREADY has an SNI stream
    // splitter configured (a `stream {}` block using ssl_preread), reuse it rather
    // than writing a second, conflicting splitter. Edge then emits only the
    // internal backend vhosts AND merges the platform's public domains INTO that
    // existing `map $ssl_preread_server_name` in place (host file untouched apart
    // from a marked, idempotent managed sub-block). Set false to always write
    // Edge's own stream block instead.
    'reuse_stream' => filter_var(env('EDGE_REUSE_STREAM', 'true'), FILTER_VALIDATE_BOOL),

    // The TLS port the nginx-only vhost LISTENS on. Empty/0 = auto: the public
    // `listen` port (443) standalone, but the internal backend port (from
    // upstreams.nginx, e.g. 444) when this host also runs an SNI `stream {}` router
    // that already binds :443 — otherwise nginx fails to start (Address already in
    // use). Auto-detected when an existing splitter is found; force with
    // EDGE_BEHIND_SNI_ROUTER=1, or pin the port with EDGE_NGINX_SSL_PORT.
    'nginx_ssl_port'    => (int) (env('EDGE_NGINX_SSL_PORT') ?: 0),
    'behind_sni_router' => filter_var(env('EDGE_BEHIND_SNI_ROUTER', 'false'), FILTER_VALIDATE_BOOL),

    // Emit per-site access_log / error_log in every vhost (both profiles). They
    // matter most in production, where incidents are reconstructed across many
    // domains on one host. Set false to fall back to nginx's single global log.
    'per_site_logs' => filter_var(env('EDGE_PER_SITE_LOGS', 'true'), FILTER_VALIDATE_BOOL),

    // The upstream NAME the merged domains map to inside the existing splitter —
    // must match the `upstream { … }` in your nginx.conf (default `nginx_backend`,
    // i.e. 127.0.0.1:444). Only used when reusing an existing stream splitter.
    'stream_backend' => (string) (env('EDGE_STREAM_BACKEND') ?: 'nginx_backend'),

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

    // Also include local (.local/.test) domains in the generated server config.
    // Dev mode (`hkm … --dev`, which exports HKM_DEV=1) turns this ON automatically
    // so nginx/Apache serves your .local sites locally; set this true to force it
    // outside dev too. A production (non --dev) run leaves it off — public domains
    // resolve through DNS. Local domains are written to /etc/hosts regardless.
    'include_local_in_server' => filter_var(env('EDGE_LOCAL_IN_SERVER', 'false'), FILTER_VALIDATE_BOOL),

    // How each project is served. Per-project override via proj.json:
    //   { "edge": { "runtime": "openswoole",
    //               "openswoole": { "host": "127.0.0.1", "port": 9501,
    //                               "websocket": "/ws", "health": "/health",
    //                               "php": "/usr/bin/php8.3",
    //                               "command": "bin/server.php",
    //                               "workers": "auto" } } }
    // `runtime` accepts php-fpm | openswoole (and the legacy fpm | swoole).
    // Projects with no runtime set stay on PHP-FPM — existing configs are unchanged.
    'serve' => [
        'model'            => (string) (env('EDGE_SERVE_MODEL') ?: 'php-fpm'), // php-fpm | openswoole
        // Empty = auto-resolve the FPM socket matching the CLI PHP version
        // (multi-PHP hosts). Set explicitly to pin a socket/addr.
        'fpm_socket'       => (string) env('EDGE_FPM_SOCKET', ''),

        // ── OpenSwoole ────────────────────────────────────────────────────────
        // Where the app's Swoole HTTP server listens — nginx reverse-proxies here.
        'swoole_host'      => (string) (env('EDGE_SWOOLE_HOST') ?: '127.0.0.1'),
        'swoole_port'      => (int) (env('EDGE_SWOOLE_PORT') ?: env('EDGE_SWOOLE_BASE_PORT') ?: 9501),
        // Dedicated WebSocket location. Empty disables the extra block (the `/`
        // proxy still carries the Upgrade headers).
        'websocket_path'   => (string) (env('EDGE_SWOOLE_WS_PATH') ?: '/ws'),
        // Values used by the generated systemd/supervisor unit (`edge:service`).
        'swoole_php'       => (string) (env('EDGE_SWOOLE_PHP') ?: PHP_BINARY ?: '/usr/bin/php'),
        // Entry script, relative to PROJECT_ROOT (absolute paths are used as-is).
        // Matches what `hkm run <project> --swoole` executes.
        'swoole_command'   => (string) (env('EDGE_SWOOLE_COMMAND') ?: 'app/swoole/index.php'),
        'swoole_workers'   => (string) (env('EDGE_SWOOLE_WORKERS') ?: 'auto'),
        // Upstream pool for the OpenSwoole backend(s). Extra backends are added
        // per project via proj.json: "openswoole": { "servers": ["127.0.0.1:9502"] }
        'swoole_balance'            => (string) (env('EDGE_SWOOLE_BALANCE') ?: 'least_conn'), // '' = round-robin
        'swoole_max_fails'          => (int) (env('EDGE_SWOOLE_MAX_FAILS') ?: 3),
        'swoole_fail_timeout'       => (string) (env('EDGE_SWOOLE_FAIL_TIMEOUT') ?: '10s'),
        'swoole_keepalive'          => (int) (env('EDGE_SWOOLE_KEEPALIVE') ?: 64),   // 0 disables the pool
        'swoole_keepalive_timeout'  => (string) env('EDGE_SWOOLE_KEEPALIVE_TIMEOUT', ''),   // '' = omit
        'swoole_keepalive_requests' => (int) env('EDGE_SWOOLE_KEEPALIVE_REQUESTS', 0),      // 0 = omit

        // Optional health endpoint proxied to the app (off by default).
        'health'           => [
            'enabled' => filter_var(env('EDGE_HEALTH_CHECK', 'false'), FILTER_VALIDATE_BOOL),
            'path'    => (string) (env('EDGE_HEALTH_PATH') ?: '/health'),
        ],
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
