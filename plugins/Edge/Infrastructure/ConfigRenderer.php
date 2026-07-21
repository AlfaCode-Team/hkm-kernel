<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

use Plugins\Edge\Domain\CacheProfile;
use Plugins\Edge\Domain\ServeModel;
use Plugins\Edge\Domain\ServerStack;
use Plugins\Edge\Domain\Site;
use Plugins\Edge\Domain\Strategy;
use Plugins\Edge\Domain\TlsConfig;
use Plugins\Edge\Domain\TlsMode;

/**
 * Pure config renderer — no I/O. Turns a strategy + the list of Sites into the
 * text of the host's web-server config: a per-project vhost (docroot
 * <project>/app/public, FPM fastcgi or Swoole proxy, with the run-env injected)
 * modeled on templates/app/{nginx,apache}.conf.example, plus — for the stream
 * strategy — the nginx SNI splitter that routes SNI → nginx (:444) / Apache.
 */
final class ConfigRenderer
{
    /**
     * @param list<Site> $sites
     * @return array{0: string, 1: string} [targetPath, contents] ('' path for None)
     */
    public function render(Strategy $strategy, array $sites, TlsConfig $tls, ServerStack $stack, CacheProfile $profile, bool $reuseStream = false): array
    {
        $sslPort  = (int) edge_config('listen', 443);
        $httpPort = (int) edge_config('http', 80);

        return match ($strategy) {
            Strategy::NginxStream => [
                (string) edge_config('paths.stream'),
                // The stream splitter is a TLS SNI router by nature, so the
                // internal nginx vhosts behind it always terminate TLS on the
                // internal port — the chosen mode applies to the single-server
                // strategies (nginx-only / apache-only), not the L4 splitter.
                //
                // When an nginx stream splitter is ALREADY configured on the host,
                // we reuse it and emit ONLY the internal backend vhosts, so we
                // never write a second, conflicting `stream {}` block.
                ($reuseStream ? $this->reuseStreamBanner() : $this->stream($sites) . "\n")
                    . $this->nginxVhosts($sites, $tls->withMode(TlsMode::Ssl), $httpPort, $this->nginxInternalPort(), $stack, $profile),
            ],
            Strategy::NginxOnly => [
                (string) edge_config('paths.nginx'),
                // The vhost LISTENS on the configured TLS port — 443 standalone,
                // but 444 (or whatever EDGE_NGINX_SSL_PORT is) when this host also
                // runs an SNI `stream {}` router that already owns :443. Emitting
                // `listen 443 ssl` there would collide and stop nginx entirely. The
                // :80→HTTPS redirect still targets the PUBLIC port (below).
                $this->nginxVhosts($sites, $tls, $httpPort, $this->nginxSslListenPort($stack), $stack, $profile),
            ],
            Strategy::ApacheOnly => [
                (string) edge_config('paths.apache'),
                $this->apacheVhosts($sites, $tls, $httpPort, $sslPort, $stack),
            ],
            Strategy::None => ['', ''],
        };
    }

    /**
     * Header emitted instead of a fresh `stream {}` block when the host already
     * has an nginx SNI splitter — records WHY no stream block is present here.
     */
    private function reuseStreamBanner(): string
    {
        $port = $this->nginxInternalPort();

        return "# Managed by the HKM Edge plugin (`hkm edge:apply`). Do NOT edit by hand.\n"
            . "# An existing nginx `stream {}` SNI splitter was detected on this host, so\n"
            . "# Edge is REUSING it — no second stream block is written here. Only the\n"
            . "# internal backend vhosts (TLS-terminating on :{$port}) are emitted below.\n"
            . "# Point your existing splitter's nginx_backend upstream at 127.0.0.1:{$port}.\n\n";
    }

    // ── nginx SNI stream splitter (L4) ────────────────────────────────────────

    /** @param list<Site> $sites */
    private function stream(array $sites): string
    {
        $map = '';
        foreach ($this->publicDomains($sites) as $d) {
            $pad = str_repeat(' ', max(1, 42 - strlen($d)));
            $map .= "        {$d}{$pad}nginx_backend;\n";
        }

        $tpl = <<<'NGINX'
# Managed by the HKM Edge plugin (`hkm edge:apply`). Do NOT edit by hand.
# SNI TLS router: server name is read WITHOUT decrypting (ssl_preread), then the
# raw TLS stream is forwarded. Platform domains → nginx (%NGINX%); everything
# else → Apache (%APACHE%). This block lives at the nginx MAIN context.
stream {
    upstream nginx_backend { server %NGINX%; }
    upstream apache_ssl    { server %APACHE%; }

    map $ssl_preread_server_name $backend_name {
%MAP%        default apache_ssl;
    }

    server {
        listen %LISTEN%;
        proxy_pass $backend_name;
        ssl_preread on;
    }
}
NGINX;

        return $this->fill($tpl, [
            '%NGINX%'  => (string) edge_config('upstreams.nginx'),
            '%APACHE%' => (string) edge_config('upstreams.apache'),
            '%LISTEN%' => (string) (int) edge_config('listen', 443),
            '%MAP%'    => $map,
        ]);
    }

    // ── per-project nginx vhosts ──────────────────────────────────────────────

    /** @param list<Site> $sites */
    private function nginxVhosts(array $sites, TlsConfig $tls, int $httpPort, int $sslPort, ServerStack $stack, CacheProfile $profile): string
    {
        $out = "# Managed by the HKM Edge plugin (`hkm edge:apply`). Do NOT edit by hand.\n";

        // One map for the whole file — nginx rejects a duplicate map for the
        // same variable, so it cannot live in the per-site template.
        $hasSwoole = false;
        foreach ($sites as $site) {
            if ($site->servesPublic() && $site->model === ServeModel::Swoole) {
                $hasSwoole = true;
                break;
            }
        }
        $prelude = $this->nginxHttpPrelude();
        if ($prelude !== '') {
            $out .= "\n" . $prelude;
        }
        // File-level CORS allowlist map (once — nginx rejects a duplicate map for
        // the same variable), emitted only when allowlist CORS is configured.
        $cors = $this->corsConfig();
        if ($cors['mode'] === 'allowlist') {
            $out .= "\n" . $this->nginxCorsMap($cors);
        }
        if ($hasSwoole) {
            $out .= "\n" . $this->connectionUpgradeMap();
        }

        foreach ($sites as $site) {
            if (!$site->servesPublic()) {
                continue;
            }
            $out .= "\n" . ($site->model === ServeModel::Swoole
                ? $this->nginxSwoole($site, $tls, $httpPort, $sslPort, $stack, $profile)
                : $this->nginxFpm($site, $tls, $httpPort, $sslPort, $stack, $profile));
        }

        return rtrim($out, "\n") . "\n";
    }

    /**
     * Everything an nginx vhost shares regardless of runtime: dev extras,
     * compression, the FULL header set (CORS + security + HSTS), the cache-profile
     * banner, the static asset location, deny lists and the method guard. Kept in
     * ONE place so the PHP-FPM and OpenSwoole vhosts can never drift apart, and so
     * every location that emits an `add_header` emits the SAME complete set.
     *
     * @return array{dev: bool, logs: string, compression: string,
     *               serverHeaders: string, banner: string, static: string,
     *               devLoc: string, dynamicHeaders: string, proxyHeaders: string,
     *               rateLimit: string, deny: string, methodGuard: string,
     *               corsMap: string}
     */
    private function vhostCommon(Site $site, TlsConfig $tls, ServerStack $stack, CacheProfile $profile): array
    {
        // Dev-only extras (verbose logging, Disallow-all robots, stub_status).
        // These follow the APP_ENV-derived cache profile so NOTHING in the vhost
        // is inferred from the kernel mode (HKM_DEV); set EDGE_DEV_VHOST to force
        // them on/off independently.
        $devOverride = edge_config('dev_vhost', null);
        $dev = $devOverride === null ? $profile->isDevelopment() : (bool) $devOverride;

        // error_log debug is OPT-IN (EDGE_NGINX_DEBUG_LOG): it only works on an
        // nginx built --with-debug, is extremely verbose (can be gigabytes), and
        // may log request internals containing session ids / tokens. Default warn.
        $debugLog = (bool) edge_config('debug_log', false);
        $errLevel = $debugLog ? 'debug' : 'warn';

        // Per-site logs are emitted in BOTH profiles — they matter MORE in
        // production, where an incident is reconstructed across many domains
        // sharing one host; without them nginx falls back to the single global
        // log. When the prelude owns logging every vhost uses its buffered format;
        // otherwise a plain combined access log. Set EDGE_PER_SITE_LOGS=0 to fall
        // back to the global log.
        $logs = '';
        if ((bool) edge_config('per_site_logs', true)) {
            $preludeOn = (bool) edge_config('http_prelude.enabled', false);
            $format    = (string) edge_config('http_prelude.log_format', 'cf_realip');
            if ($preludeOn && $format !== '') {
                $buffer = (string) edge_config('http_prelude.log_buffer', '32k');
                $flush  = (string) edge_config('http_prelude.log_flush', '5s');
                $logs = "    access_log /var/log/nginx/{$site->name}.access.log {$format} buffer={$buffer} flush={$flush};\n"
                    . "    error_log  /var/log/nginx/{$site->name}.error.log {$errLevel};\n";
            } else {
                $logs = "    access_log /var/log/nginx/{$site->name}.access.log combined;\n"
                    . "    error_log  /var/log/nginx/{$site->name}.error.log {$errLevel};\n";
            }
        }

        $cors        = $this->corsConfig();
        $compression = $this->nginxCompression($stack);

        // THE single header set — CORS (if configured) + security + HSTS. Built at
        // two indents: 4 for server scope, 8 for inside a location. Any location
        // that declares an add_header of its own MUST re-emit this whole set,
        // because one add_header in a location drops ALL inherited ones.
        $serverHeaders = $this->headerSet(4, $tls, $profile, $cors);
        $locHeaders    = $this->headerSet(8, $tls, $profile, $cors);

        $cacheDev    = $profile->isDevelopment();
        $cacheHtml   = (bool) edge_config('cache.browser_html', false);
        $cacheAssets = (bool) edge_config('cache.browser_assets', true);
        $assetTtl    = (int) edge_config('cache.browser_assets_ttl', 31536000);
        $cloudflare  = (bool) edge_config('cache.cloudflare', true);

        // Dynamic responses (front controller / proxied app) are NEVER cached
        // unless HTML caching is explicitly opted into.
        if ($cacheHtml) {
            $cache = '';
        } elseif ($cacheDev) {
            $cache = "        add_header Cache-Control \"no-store, no-cache, must-revalidate\" always;\n"
                . "        add_header Pragma \"no-cache\" always;\n"
                . "        add_header Expires \"0\" always;\n";
        } else {
            $cache = "        add_header Cache-Control \"no-store, no-cache, must-revalidate\" always;\n";
        }
        // index.php ALWAYS emits the full header set (so CORS lands on real app /
        // API responses, which all route through the front controller), plus the
        // cache directives.
        $dynamicHeaders = "\n" . $locHeaders . $cache;

        // Proxied (OpenSwoole) responses: development forces no-store; PRODUCTION
        // lets the app own Cache-Control. The header set is still emitted so CORS
        // and security headers reach proxied responses too.
        $proxyCache   = (!$cacheHtml && $cacheDev) ? $cache : '';
        $proxyHeaders = "\n" . $locHeaders . $proxyCache;

        // Static assets. Disabled (no-store) in dev or when asset caching is off;
        // otherwise immutable, long-lived caching of FINGERPRINTED assets only.
        // PRODUCTION drops `map` (source maps expose original source) and `json`
        // (stray build/config) from the served set; DEVELOPMENT keeps them.
        if ($cacheDev || !$cacheAssets) {
            $assetExt   = 'css|js|map|json|png|jpg|jpeg|gif|svg|webp|ico|woff|woff2|ttf|otf|eot|pdf|txt|xml';
            $assetCache = "        expires off;\n"
                . "        add_header Cache-Control \"no-store, no-cache, must-revalidate\" always;\n"
                . "        add_header Pragma \"no-cache\" always;\n"
                . "        add_header Expires \"0\" always;\n"
                . $locHeaders
                . "        access_log off;\n";
        } else {
            $assetExt   = 'css|js|png|jpg|jpeg|gif|svg|webp|ico|woff|woff2|ttf|otf|eot';
            $control    = $cloudflare ? 'public, immutable' : 'public';
            $assetCache = "        expires " . $this->expiresValue($assetTtl) . ";\n"
                . "        add_header Cache-Control \"{$control}\" always;\n"
                . $locHeaders
                . "        access_log off;\n";
        }
        // Static assets resolve ONLY under the public root; a miss is a hard 404
        // and is never forwarded to the application (same rule for both runtimes).
        $static = "    location ~* \\.({$assetExt})\$ {\n"
            . $assetCache
            . "        try_files \$uri =404;\n    }";

        // robots.txt (dev only — Disallow all). /nginx-status is gated on the TRUE
        // development profile, never a mere EDGE_DEV_VHOST override: behind the
        // production SNI stream splitter every peer looks like 127.0.0.1, so an
        // `allow 127.0.0.1` there would expose stub_status to the whole internet.
        $devLoc = '';
        if ($dev) {
            $devLoc .= "\n    location = /robots.txt {\n        access_log off;\n"
                . "        return 200 \"User-agent: *\\nDisallow: /\\n\";\n    }\n";
        }
        if ($profile->isDevelopment() && (bool) edge_config('nginx_status', true)) {
            $devLoc .= "\n    location = /nginx-status {\n        stub_status on;\n"
                . "        allow 127.0.0.1;\n        allow ::1;\n        deny all;\n    }\n";
        }

        return [
            'dev'            => $dev,
            'logs'           => $logs,
            'rateLimit'      => $this->nginxRateLimit($profile),
            'compression'    => $compression,
            'serverHeaders'  => $serverHeaders,
            'banner'         => $profile->banner(),
            'static'         => $static,
            'devLoc'         => $devLoc,
            'dynamicHeaders' => $dynamicHeaders,
            'proxyHeaders'   => $proxyHeaders,
            'deny'           => $this->nginxDenyLocations($profile),
            'methodGuard'    => $this->nginxMethodGuard(),
            'corsMap'        => $cors['mode'] === 'allowlist' ? $this->nginxCorsMap($cors) : '',
        ];
    }

    /**
     * Resolve the CORS policy. The wildcard is OPT-IN — a dev host reachable
     * beyond localhost with `Allow-Origin: *` + `Allow-Headers: Authorization`
     * lets any page a developer visits make authenticated cross-origin reads.
     *
     *   EDGE_CORS = off (default) | allowlist | wildcard
     *   EDGE_CORS_ORIGINS = https://a.com,https://b.com   (allowlist mode)
     *
     * @return array{mode: string, origins: list<string>, methods: string, headers: string, credentials: bool}
     */
    private function corsConfig(): array
    {
        $mode = strtolower(trim((string) edge_config('cors.mode', 'off')));
        if (!in_array($mode, ['off', 'allowlist', 'wildcard'], true)) {
            $mode = 'off';
        }
        $origins = array_values(array_filter((array) edge_config('cors.origins', [])));
        if ($mode === 'allowlist' && $origins === []) {
            $mode = 'off'; // allowlist with nothing to allow = no CORS
        }

        return [
            'mode'        => $mode,
            'origins'     => $origins,
            'methods'     => (string) edge_config('cors.methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS'),
            'headers'     => (string) edge_config('cors.headers', 'Content-Type, Authorization, X-Requested-With'),
            'credentials' => (bool) edge_config('cors.credentials', false),
        ];
    }

    /**
     * The complete header set at the given indent: CORS (per policy) + the three
     * security headers + HSTS (TLS modes only, profile-aware). This is emitted at
     * server scope AND repeated in every location that declares any add_header.
     *
     * @param array{mode: string, origins: list<string>, methods: string, headers: string, credentials: bool} $cors
     */
    private function headerSet(int $indent, TlsConfig $tls, CacheProfile $profile, array $cors): string
    {
        $pad = str_repeat(' ', $indent);
        $out = '';

        // ── CORS ──────────────────────────────────────────────────────────────
        if ($cors['mode'] === 'wildcard') {
            $out .= "{$pad}add_header Access-Control-Allow-Origin \"*\" always;\n";
        } elseif ($cors['mode'] === 'allowlist') {
            // $cors_allow_origin is set by the file-level map — empty (and so the
            // header is omitted by nginx) for any origin not on the allowlist.
            $out .= "{$pad}add_header Access-Control-Allow-Origin \$cors_allow_origin always;\n"
                . "{$pad}add_header Vary Origin always;\n";
        }
        if ($cors['mode'] !== 'off') {
            $out .= "{$pad}add_header Access-Control-Allow-Methods \"{$cors['methods']}\" always;\n"
                . "{$pad}add_header Access-Control-Allow-Headers \"{$cors['headers']}\" always;\n";
            if ($cors['credentials']) {
                $out .= "{$pad}add_header Access-Control-Allow-Credentials \"true\" always;\n";
            }
        }

        // ── Security ──────────────────────────────────────────────────────────
        $out .= "{$pad}add_header X-Content-Type-Options \"nosniff\" always;\n"
            . "{$pad}add_header X-Frame-Options \"SAMEORIGIN\" always;\n"
            . "{$pad}add_header Referrer-Policy \"strict-origin-when-cross-origin\" always;\n";

        // ── HSTS (TLS only) ───────────────────────────────────────────────────
        if ($tls->mode->usesTls()) {
            $out .= $this->nginxHsts($indent, $profile);
        }

        return $out;
    }

    /**
     * File-level `map $http_origin $cors_allow_origin { … }` for allowlist CORS —
     * echoes the request Origin back only when it is on the list, else empty.
     * Emitted once per file (nginx rejects a duplicate map for the same variable).
     *
     * @param array{origins: list<string>} $cors
     */
    private function nginxCorsMap(array $cors): string
    {
        $body = "    default \"\";\n";
        foreach ($cors['origins'] as $origin) {
            $origin = trim((string) $origin);
            if ($origin === '') {
                continue;
            }
            $q = '"' . $this->escapeNginx($origin) . '"';
            $body .= "    {$q} {$q};\n";
        }

        return "# CORS allowlist — echo the Origin only when explicitly permitted.\n"
            . "map \$http_origin \$cors_allow_origin {\n{$body}}\n";
    }

    /**
     * Deny access to sensitive files and directories that would otherwise fall
     * through `location /` to `try_files $uri` and be served as static content.
     *
     * ORDER MATTERS: nginx evaluates regex locations in file order, first match
     * wins — so these MUST be emitted BEFORE the static-asset regex, or a denied
     * directory file with a whitelisted extension (e.g. `vendor/composer/
     * installed.json`) would be served by the static rule instead. Directories use
     * `^~` prefix locations, which beat regex matching regardless of position and
     * cannot be shadowed. Emitted in BOTH profiles (defense in depth).
     *
     * NOTE: dropping an extension from the static-asset location is NOT enough to
     * block it — `location /` still falls through to `try_files $uri`, serving any
     * file that exists on disk. Only an explicit deny blocks it. So in PRODUCTION
     * `.map` (original source maps) is DENIED here, not merely un-cached.
     */
    private function nginxDenyLocations(CacheProfile $profile): string
    {
        $out  = "\n";
        $dirs = $this->denyDirs();
        if ($dirs !== []) {
            $out .= "    # Sensitive directories — prefix-matched (^~) so they win over the\n"
                . "    # static-asset regex below regardless of file extension.\n";
            foreach ($dirs as $dir) {
                $path = '/' . trim($dir, '/') . '/';
                $out .= "    location ^~ {$path} { deny all; access_log off; log_not_found off; }\n";
            }
            $out .= "\n";
        }

        // Source maps expose original unminified source — DENIED in production
        // (kept in development for debugging). This must be a deny, not just
        // removal from the static rule, or `location /`/try_files serves it.
        $ext = 'env|log|sql|sqlite|bak|backup|swp|dist|sh|ini|conf|yml|yaml|lock';
        if (!$profile->isDevelopment()) {
            $ext .= '|map';
        }

        // Hidden files (dotfiles) — but .well-known stays reachable (ACME). This
        // regex sits before the static rule so a dotfile with a whitelisted
        // extension (e.g. /.vscode/settings.json) is denied, not served.
        $out .= "    # Hidden files denied, but .well-known stays reachable (ACME/Let's Encrypt).\n"
            . "    location ~ /\\.(?!well-known) { deny all; }\n\n"
            . "    # Sensitive file extensions — never served as static content.\n"
            . "    location ~* \\.({$ext})\$ {\n"
            . "        deny all; access_log off; log_not_found off;\n    }";

        return $out;
    }

    /**
     * Directories to deny (prefix-matched). Configurable per project because the
     * right set is app-specific — notably `storage` is NOT denied by default: a
     * Laravel-style `public/storage` symlink serves intended user uploads, and a
     * blanket deny would break it. Add it via EDGE_DENY_DIRS where appropriate.
     * Entries are validated to a safe path charset so config can never inject a
     * directive.
     *
     * @return list<string>
     */
    private function denyDirs(): array
    {
        $list = edge_config('deny_dirs', null);
        if ($list === null) {
            $list = ['vendor', 'node_modules', 'tests', '.git', '.github', 'bootstrap/cache'];
        }

        $out = [];
        foreach ((array) $list as $dir) {
            $dir = trim((string) $dir, '/ ');
            if ($dir !== '' && preg_match('#^[A-Za-z0-9._/-]+$#', $dir)) {
                $out[] = $dir;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * HTTP method guard — reject verbs the app never uses. Configurable because
     * these apps legitimately use PUT/PATCH/DELETE (REST); the default therefore
     * allows the full REST set rather than the GET/HEAD/POST minimum, so the guard
     * blocks oddities (TRACE, CONNECT, …) without breaking the API. Set
     * EDGE_ALLOWED_METHODS to tighten. Empty disables the guard.
     */
    private function nginxMethodGuard(): string
    {
        $methods = strtoupper(trim((string) edge_config('allowed_methods', 'GET|HEAD|POST|PUT|PATCH|DELETE|OPTIONS')));
        $methods = str_replace([',', ' '], ['|', ''], $methods);
        if ($methods === '') {
            return '';
        }

        return "    # Reject any HTTP method the application does not use.\n"
            . "    if (\$request_method !~ ^({$methods})\$) { return 405; }\n";
    }

    private function nginxFpm(Site $site, TlsConfig $tls, int $httpPort, int $sslPort, ServerStack $stack, CacheProfile $profile): string
    {
        $paths = $this->pathBanner($site);
        $params = '';
        foreach ($site->env as $k => $v) {
            $params .= sprintf("        fastcgi_param %s \"%s\";\n", $k, $this->escapeNginx($v));
        }

        [$listen, $ssl] = $this->nginxTls($tls, $httpPort, $sslPort);
        $redirect = $tls->mode === TlsMode::Both
            ? $this->nginxRedirect($site->publicDomains, $site->publicRoot(), $httpPort, (int) edge_config('listen', 443)) . "\n\n"
            : '';

        $c = $this->vhostCommon($site, $tls, $stack, $profile);

        $tpl = <<<'NGINX'
%CACHEPROFILE%%RUNTIME%%PATHS%# Project: %NAME% (PHP-FPM)
%REDIRECT%server {
    %LISTEN%
    server_name %NAMES%;

    root %DOCROOT%;
    index index.php;
%SSL%
%LOGS%%HEADERS%    server_tokens off;
    client_max_body_size 25m;
%METHODS%%RATELIMIT%%COMPRESSION%
    # Front controller — only /index.php executes PHP.
    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/index.php;
%PARAMS%        fastcgi_pass %UPSTREAM%;

        fastcgi_buffers 16 16k;
        fastcgi_buffer_size 32k;
        fastcgi_read_timeout 300s;
        fastcgi_send_timeout 300s;
        fastcgi_connect_timeout 60s;
        # FastCGI microcache is OFF here; the session-keyed guards below mean it
        # stays safe (never caches an authenticated response) if a cache zone is
        # ever switched on upstream.
        fastcgi_cache off;
        fastcgi_no_cache $cookie_PHPSESSID;
        fastcgi_cache_bypass $cookie_PHPSESSID;
%FCHEADERS%    }

    # Any other .php file is not executed.
    location ~ \.php$ { return 404; }
%DENY%

    # Static assets (evaluated AFTER the deny rules above so nothing inside a
    # denied path can be served via a whitelisted extension).
%STATIC%

    location = /favicon.ico {
        access_log off;
        log_not_found off;
        try_files $uri =404;
    }
%DEVLOC%
    error_page 404 /404.html;
    error_page 500 502 503 504 /50x.html;
    location ~ ^/(404|50x)\.html$ {
        root %DOCROOT%;
        internal;
    }

    location / { try_files $uri /index.php$is_args$args; }
}
NGINX;

        return $this->fill($tpl, [
            '%NAME%'     => $site->name,
            '%CACHEPROFILE%' => $c['banner'],
            '%RUNTIME%'  => $site->model->banner(),
            '%PATHS%'    => $paths,
            '%REDIRECT%' => $redirect,
            '%LISTEN%'   => $listen,
            '%SSL%'      => $ssl,
            '%NAMES%'    => implode(' ', $site->publicDomains),
            '%DOCROOT%'  => $site->publicRoot(),
            '%PARAMS%'   => $params,
            '%LOGS%'     => $c['logs'],
            '%HEADERS%'  => $c['serverHeaders'],
            '%METHODS%'  => $c['methodGuard'],
            '%COMPRESSION%' => $c['compression'],
            '%RATELIMIT%' => $c['rateLimit'],
            '%FCHEADERS%' => $c['dynamicHeaders'],
            '%STATIC%'   => $c['static'],
            '%DENY%'     => $c['deny'],
            '%DEVLOC%'   => $c['devLoc'],
            '%UPSTREAM%' => $site->upstream,
        ]);
    }

    /** Cloudflare's published edge ranges (www.cloudflare.com/ips). */
    private const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * HTTP-context prerequisites the vhosts below depend on: the log_format, the
     * rate-limit zones and the Cloudflare real-IP ranges. Emitted ONCE per file
     * and only when explicitly enabled — re-declaring a zone or log_format that
     * already exists in nginx.conf is a duplicate-definition error.
     */
    private function nginxHttpPrelude(): string
    {
        if (!(bool) edge_config('http_prelude.enabled', false)) {
            return '';
        }

        $out = "# ── HKM Edge: http-context prerequisites ────────────────────────────────\n";

        $format = (string) edge_config('http_prelude.log_format', 'cf_realip');
        if ($format !== '') {
            $out .= "# Access log format: real visitor IP first, then the CF edge that relayed it.\n"
                . "log_format {$format} '\$remote_addr - \$remote_user [\$time_local] \"\$request\" '\n"
                . "                     '\$status \$body_bytes_sent \"\$http_referer\" '\n"
                . "                     '\"\$http_user_agent\" cf=\"\$http_cf_connecting_ip\" '\n"
                . "                     'rt=\$request_time urt=\"\$upstream_response_time\"';\n\n";
        }

        if ((bool) edge_config('http_prelude.rate_limit.enabled', true)) {
            $reqZone  = (string) edge_config('http_prelude.rate_limit.req_zone', 'general');
            $reqSize  = (string) edge_config('http_prelude.rate_limit.req_size', '10m');
            $reqRate  = (string) edge_config('http_prelude.rate_limit.req_rate', '10r/s');
            $connZone = (string) edge_config('http_prelude.rate_limit.conn_zone', 'perip');
            $connSize = (string) edge_config('http_prelude.rate_limit.conn_size', '10m');

            // Keyed on $binary_remote_addr, which is the REAL client once the
            // Cloudflare real-IP block below has rewritten it.
            $out .= "# Rate-limit zones (keyed on the real client IP).\n"
                . "limit_req_zone  \$binary_remote_addr zone={$reqZone}:{$reqSize} rate={$reqRate};\n"
                . "limit_conn_zone \$binary_remote_addr zone={$connZone}:{$connSize};\n\n";
        }

        if ((bool) edge_config('http_prelude.cloudflare.enabled', true)) {
            $ranges = (array) edge_config('http_prelude.cloudflare.ranges', []);
            $ranges = $ranges === [] ? self::CLOUDFLARE_RANGES : $ranges;
            $header = (string) edge_config('http_prelude.cloudflare.header', 'CF-Connecting-IP');

            $out .= "# Cloudflare: trust the edge and take the visitor IP from {$header},\n"
                . "# so logs, rate limits and deny rules see the real client.\n";
            // The HTTP server sits behind the :443 SNI stream splitter, so the TCP
            // peer at this layer is ALWAYS 127.0.0.1 — the real_ip module must trust
            // the loopback hop or it never fires and $remote_addr stays 127.0.0.1
            // (collapsing every client into ONE rate-limit bucket, and logging
            // 127.0.0.1 for everyone). SAFETY: only sound when :443 is firewalled to
            // Cloudflare — otherwise a direct-to-origin client can spoof {$header}.
            // For a stricter setup, enable PROXY protocol on the stream hop instead.
            if ((bool) edge_config('http_prelude.cloudflare.trust_loopback', true)) {
                $out .= "set_real_ip_from 127.0.0.1;\n"
                    . "set_real_ip_from ::1;\n";
            }
            foreach ($ranges as $range) {
                $out .= 'set_real_ip_from ' . trim((string) $range) . ";\n";
            }
            $out .= "real_ip_header {$header};\n"
                . "real_ip_recursive on;\n";
        }

        return rtrim($out, "\n") . "\n";
    }

    /**
     * Per-vhost `limit_req` / `limit_conn`, matching the zones in the prelude. The
     * DEVELOPMENT profile uses a looser burst so rapid page reloads don't trip 429s.
     */
    private function nginxRateLimit(CacheProfile $profile): string
    {
        if (!(bool) edge_config('http_prelude.enabled', false)
            || !(bool) edge_config('http_prelude.rate_limit.enabled', true)) {
            return '';
        }

        $reqZone  = (string) edge_config('http_prelude.rate_limit.req_zone', 'general');
        $default  = $profile->isDevelopment() ? 200 : 50;
        $burst    = (int) edge_config('http_prelude.rate_limit.req_burst', $default);
        $nodelay  = (bool) edge_config('http_prelude.rate_limit.req_nodelay', true) ? ' nodelay' : '';
        $connZone = (string) edge_config('http_prelude.rate_limit.conn_zone', 'perip');
        $connLim  = (int) edge_config('http_prelude.rate_limit.conn_limit', 100);

        return "    limit_req zone={$reqZone} burst={$burst}{$nodelay};\n"
            . "    limit_conn {$connZone} {$connLim};\n";
    }

    /**
     * The path provenance banner. Every generated path derives from PROJECT_ROOT,
     * so the same template works for any checkout location — this records which
     * roots produced the file.
     */
    private function pathBanner(Site $site): string
    {
        $root = $site->root !== '' ? $site->root : dirname($site->docroot, 2);
        $out  = "# HKM Edge project root: {$root}\n"
            . "# HKM Edge public root: {$site->publicRoot()}\n";
        if ($site->swoole !== null) {
            $out .= "# HKM Edge swoole root: {$site->swooleRoot()}\n";
        }

        return $out;
    }

    /** nginx upstream name for a project, e.g. `blog_backend`. */
    private function upstreamName(Site $site): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '_', $site->name));

        return trim($slug, '_') . '_backend';
    }

    /**
     * The `upstream <name>_backend { … }` block for an OpenSwoole project: the
     * balancing method, every backend with its failure thresholds, and the
     * keepalive connection pool. Lives at http context, so it is emitted right
     * before the site's server block.
     */
    private function nginxUpstream(Site $site): string
    {
        $sw   = $site->swoole;
        $name = $this->upstreamName($site);

        $body = '';
        if ($sw->balance !== '') {
            $body .= "    # Load balancing method\n    {$sw->balance};\n\n";
        }
        $body .= "    # OpenSwoole worker(s)\n";
        foreach ($sw->servers() as $server) {
            $body .= "    server {$server} max_fails={$sw->maxFails} fail_timeout={$sw->failTimeout};\n";
        }
        if (count($sw->servers()) === 1) {
            $body .= "    # Add more workers via proj.json:\n"
                . "    #   \"edge\": { \"openswoole\": { \"ports\": [9501, 9502, 9503] } }\n";
        }
        if ($sw->keepalive > 0) {
            $body .= "\n    # Idle upstream connection pool\n    keepalive {$sw->keepalive};\n";
            if ($sw->keepaliveTimeout !== '') {
                $body .= "    keepalive_timeout {$sw->keepaliveTimeout};\n";
            }
            if ($sw->keepaliveRequests > 0) {
                $body .= "    keepalive_requests {$sw->keepaliveRequests};\n";
            }
        }

        return "# OpenSwoole upstream for {$site->name}\nupstream {$name} {\n{$body}}\n";
    }

    /**
     * The `$connection_upgrade` map, emitted ONCE per file when any OpenSwoole
     * site is present (nginx rejects a duplicate map for the same variable).
     *
     * Why a map: WebSocket requests need `Connection: upgrade`, but sending that
     * on EVERY request would defeat the upstream keepalive pool. The map sends
     * `upgrade` only when the client asked to upgrade, and an empty value
     * otherwise — which is exactly what keepalive to the backend requires.
     */
    private function connectionUpgradeMap(): string
    {
        return <<<'NGINX'
# WebSocket upgrade switch. Only the /ws location sets Connection from this —
# normal traffic leaves the header alone.
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}
NGINX . "\n";
    }

    private function nginxSwoole(Site $site, TlsConfig $tls, int $httpPort, int $sslPort, ServerStack $stack, CacheProfile $profile): string
    {
        [$listen, $ssl] = $this->nginxTls($tls, $httpPort, $sslPort);
        $redirect = $tls->mode === TlsMode::Both
            ? $this->nginxRedirect($site->publicDomains, $site->publicRoot(), $httpPort, (int) edge_config('listen', 443)) . "\n\n"
            : '';

        $c        = $this->vhostCommon($site, $tls, $stack, $profile);
        $sw       = $site->swoole;
        $upstream = $this->upstreamName($site);
        $paths    = $this->pathBanner($site);

        // Optional health endpoint — cheap, unlogged, never cached.
        $health = ($sw?->healthPath ?? null) === null ? '' : $this->fill(<<<'NGINX'

    # Health check.
    location %PATH% {
        proxy_pass http://%UPSTREAM%;
        proxy_set_header Host $host;
        access_log off;
    }
NGINX, ['%PATH%' => $sw->healthPath, '%UPSTREAM%' => $upstream]);

        // WebSocket — the ONLY place that sets Upgrade/Connection, with long
        // timeouts so idle sockets are not culled mid-conversation.
        $websocket = ($sw?->websocketPath ?? null) === null ? '' : $this->fill(<<<'NGINX'

    # WebSocket endpoint (OpenSwoole).
    location %PATH% {
        proxy_pass http://%UPSTREAM%;
        proxy_http_version 1.1;

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header CF-Connecting-IP  $http_cf_connecting_ip;

        proxy_set_header Upgrade           $http_upgrade;
        proxy_set_header Connection        $connection_upgrade;

        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
    }
NGINX, ['%PATH%' => $sw->websocketPath, '%UPSTREAM%' => $upstream]);

        $tpl = <<<'NGINX'
%CACHEPROFILE%%RUNTIME%%PATHS%# Project: %NAME% (OpenSwoole) — the app's env lives in the Swoole process:
#   hkm run %NAME% --swoole   (bind it to %BIND%)
%UPSTREAMBLOCK%
%REDIRECT%server {
    %LISTEN%
    server_name %NAMES%;

    root %DOCROOT%;
%SSL%
%LOGS%%HEADERS%    server_tokens off;
    client_max_body_size 25m;
%METHODS%%RATELIMIT%%COMPRESSION%%DENY%

    # Static assets are served straight off disk from the public root above (a
    # miss is a 404 — never forwarded to OpenSwoole). Evaluated AFTER the deny
    # rules above so nothing inside a denied path leaks via a whitelisted ext.
%STATIC%
%HEALTH%%WEBSOCKET%
    # Normal application traffic → OpenSwoole. No Upgrade/Connection here; the
    # WebSocket location above owns that.
    location / {
        proxy_pass http://%UPSTREAM%;
        proxy_http_version 1.1;

        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header X-Forwarded-Host  $host;
        proxy_set_header X-Forwarded-Port  $server_port;
        proxy_set_header CF-Connecting-IP  $http_cf_connecting_ip;

        proxy_connect_timeout 60s;
        proxy_send_timeout    60s;
        proxy_read_timeout    60s;

        proxy_buffering on;
        proxy_buffer_size 4k;
        proxy_buffers 8 4k;
        proxy_busy_buffers_size 8k;

        proxy_next_upstream error timeout invalid_header http_500 http_502 http_503;
        proxy_next_upstream_tries 2;
%PROXYHEADERS%    }
%DEVLOC%}
NGINX;

        return $this->fill($tpl, [
            '%NAME%'         => $site->name,
            '%CACHEPROFILE%' => $c['banner'],
            '%RUNTIME%'      => $site->model->banner(),
            '%PATHS%'        => $paths,
            '%REDIRECT%'     => $redirect,
            '%LISTEN%'       => $listen,
            '%SSL%'          => $ssl,
            '%NAMES%'        => implode(' ', $site->publicDomains),
            '%DOCROOT%'      => $site->publicRoot(),
            '%LOGS%'         => $c['logs'],
            '%HEADERS%'      => $c['serverHeaders'],
            '%METHODS%'      => $c['methodGuard'],
            '%COMPRESSION%'  => $c['compression'],
            '%RATELIMIT%'    => $c['rateLimit'],
            '%STATIC%'       => $c['static'],
            '%DENY%'         => $c['deny'],
            '%HEALTH%'       => $health,
            '%WEBSOCKET%'    => $websocket,
            '%PROXYHEADERS%' => $c['proxyHeaders'],
            '%DEVLOC%'       => $c['devLoc'],
            '%UPSTREAMBLOCK%' => $this->nginxUpstream($site),
            '%UPSTREAM%'     => $upstream,
            '%BIND%'         => $sw->upstream(),
        ]);
    }

    /**
     * The nginx listen directive(s) + ssl_certificate block for a mode.
     *
     * @return array{0: string, 1: string} [listen directives, ssl block ('' when plain)]
     */
    private function nginxTls(TlsConfig $tls, int $httpPort, int $sslPort): array
    {
        if ($tls->mode === TlsMode::None) {
            return ["listen {$httpPort};\n    listen [::]:{$httpPort};", ''];
        }

        // IPv4 + IPv6 listeners, mirroring the plain-HTTP redirect block so an
        // IPv6-only client that hits :80 has a :443 to be redirected to.
        $listen = "listen {$sslPort} ssl;\n    listen [::]:{$sslPort} ssl;\n    http2 on;";
        $ssl    = "\n    ssl_certificate     {$tls->cert};\n    ssl_certificate_key {$tls->key};\n"
            . $this->nginxTlsHardening();

        return [$listen, $ssl];
    }

    /**
     * Explicit TLS pinning + session settings. Emitted for every TLS listener in
     * BOTH profiles: relying on the build defaults has historically left TLS
     * 1.0/1.1 enabled on some distributions. OCSP stapling stays OFF by default —
     * Cloudflare Origin CA certs are not publicly chained, so stapling would fail;
     * enable EDGE_SSL_STAPLING only with a publicly-chained cert.
     */
    private function nginxTlsHardening(): string
    {
        $protocols = (string) (edge_config('ssl_hardening.protocols') ?: 'TLSv1.2 TLSv1.3');
        $ciphers   = (string) (edge_config('ssl_hardening.ciphers') ?:
            'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:'
            . 'ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:'
            . 'ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305');

        $out = "    ssl_protocols {$protocols};\n"
            . "    ssl_ciphers {$ciphers};\n"
            . "    ssl_prefer_server_ciphers off;\n"
            . "    ssl_session_cache shared:SSL:10m;\n"
            . "    ssl_session_timeout 1h;\n"
            . "    ssl_session_tickets off;\n";

        if ((bool) edge_config('ssl_hardening.stapling', false)) {
            $out .= "    ssl_stapling on;\n    ssl_stapling_verify on;\n";
        }

        return $out;
    }

    /**
     * A plain-HTTP server that 301-redirects to HTTPS (the `both` mode). ACME /
     * Let's Encrypt HTTP-01 validation is served BEFORE the redirect so a cert can
     * still be issued/renewed over plain :80.
     *
     * @param list<string> $domains
     */
    private function nginxRedirect(array $domains, string $docroot, int $httpPort, int $sslPort): string
    {
        $names  = implode(' ', $domains);
        $target = $sslPort === 443
            ? 'https://$host$request_uri'
            : "https://\$host:{$sslPort}\$request_uri";

        return <<<NGINX
        server {
            listen {$httpPort};
            listen [::]:{$httpPort};
            server_name {$names};

            # ACME/Let's Encrypt validation must succeed BEFORE the redirect fires.
            location ^~ /.well-known/acme-challenge/ {
                default_type "text/plain";
                root {$docroot};
            }

            location / {
                return 301 {$target};
            }
        }
        NGINX;
    }

    // ── per-project Apache vhosts ─────────────────────────────────────────────

    /** @param list<Site> $sites */
    private function apacheVhosts(array $sites, TlsConfig $tls, int $httpPort, int $sslPort, ServerStack $stack): string
    {
        $out = "# Managed by the HKM Edge plugin (`hkm edge:apply`). Do NOT edit by hand.\n";
        foreach ($sites as $site) {
            if (!$site->servesPublic()) {
                continue;
            }
            $out .= "\n" . $this->apacheSite($site, $tls, $httpPort, $sslPort, $stack);
        }

        return rtrim($out, "\n") . "\n";
    }

    private function apacheSite(Site $site, TlsConfig $tls, int $httpPort, int $sslPort, ServerStack $stack): string
    {
        $aliases = '';
        foreach (array_slice($site->publicDomains, 1) as $d) {
            $aliases .= "    ServerAlias {$d}\n";
        }
        $setenv = '';
        foreach ($site->env as $k => $v) {
            $setenv .= sprintf("    SetEnv %s \"%s\"\n", $k, $this->escapeApache($v));
        }

        // PHP handler: FPM via mod_proxy_fcgi, or reverse-proxy for Swoole.
        if ($site->model === ServeModel::Swoole) {
            $handler = "    ProxyPreserveHost On\n    ProxyPass        / http://{$site->upstream}/\n    ProxyPassReverse / http://{$site->upstream}/";
        } else {
            $fcgi = str_starts_with($site->upstream, 'unix:')
                ? 'proxy:' . $site->upstream . '|fcgi://localhost/'
                : 'proxy:fcgi://' . $site->upstream;
            $handler = "    <FilesMatch \"\\.php$\">\n        SetHandler \"{$fcgi}\"\n    </FilesMatch>";
        }

        // Plain mode serves on :80; ssl/both serve the app on the TLS port.
        $vhostPort = $tls->mode === TlsMode::None ? $httpPort : $sslPort;
        $ssl = $tls->mode === TlsMode::None
            ? ''
            : "\n    SSLEngine on\n    SSLCertificateFile    {$tls->cert}\n    SSLCertificateKeyFile {$tls->key}\n";
        $redirect = $tls->mode === TlsMode::Both
            ? $this->apacheRedirect($site, $aliases, $httpPort) . "\n\n"
            : '';

        // HSTS on TLS modes (needs mod_headers) + compression (mod_brotli/
        // mod_deflate via mod_filter). Both are gated on the module actually
        // being loaded, probed from `apachectl -M`.
        $hsts        = $tls->mode->usesTls() && $stack->apacheHasModule('headers') ? $this->apacheHsts() : '';
        $compression = $this->apacheCompression($stack);

        $tpl = <<<'APACHE'
# Project: %NAME%
%REDIRECT%<VirtualHost *:%PORT%>
    ServerName %PRIMARY%
%ALIASES%    DocumentRoot %DOCROOT%

    <Directory %DOCROOT%>
        AllowOverride All
        Require all granted
        Options -Indexes +FollowSymLinks
    </Directory>
    <DirectoryMatch "/\.">
        Require all denied
    </DirectoryMatch>
%SSL%
%HSTS%%COMPRESSION%%SETENV%%HANDLER%

    ServerSignature Off
    LimitRequestBody 26214400
</VirtualHost>
APACHE;

        return $this->fill($tpl, [
            '%NAME%'     => $site->name,
            '%REDIRECT%' => $redirect,
            '%PORT%'     => (string) $vhostPort,
            '%PRIMARY%'  => $site->publicDomains[0] ?? '_',
            '%ALIASES%'  => $aliases,
            '%DOCROOT%'  => $site->publicRoot(),
            '%SSL%'      => $ssl,
            '%HSTS%'     => $hsts,
            '%COMPRESSION%' => $compression,
            '%SETENV%'   => $setenv,
            '%HANDLER%'  => $handler,
        ]);
    }

    /**
     * A plain-HTTP VirtualHost that rewrites every request to HTTPS (`both`).
     * $aliases is the pre-rendered "    ServerAlias …\n" block (may be '').
     */
    private function apacheRedirect(Site $site, string $aliases, int $httpPort): string
    {
        $primary = $site->publicDomains[0] ?? '_';

        return rtrim(<<<APACHE
        <VirtualHost *:{$httpPort}>
            ServerName {$primary}
        {$aliases}    RewriteEngine On
            RewriteRule ^ https://%{HTTP_HOST}%{REQUEST_URI} [L,R=301]
        </VirtualHost>
        APACHE);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /** @param list<Site> $sites @return list<string> */
    private function publicDomains(array $sites): array
    {
        $domains = [];
        foreach ($sites as $site) {
            foreach ($site->publicDomains as $d) {
                $domains[] = $d;
            }
        }
        $domains = array_values(array_unique($domains));
        sort($domains);

        return $domains;
    }

    /** The internal port nginx vhosts listen on when behind the stream splitter. */
    private function nginxInternalPort(): int
    {
        $backend = (string) edge_config('upstreams.nginx', '127.0.0.1:444');
        $port    = (int) substr(strrchr($backend, ':') ?: ':444', 1);

        return $port > 0 ? $port : 444;
    }

    /**
     * The TLS port the nginx-only vhost LISTENS on.
     *
     * Standalone that's the public port (443). But when this host ALSO runs an SNI
     * `stream {}` router (which binds :443 itself), the vhost must listen on the
     * internal backend port (444) or nginx fails to start with "Address already in
     * use". Resolution: explicit EDGE_NGINX_SSL_PORT wins; else, if the host has an
     * existing stream splitter (detected on the host) is present, use the internal
     * backend port; else the public port.
     */
    private function nginxSslListenPort(ServerStack $stack): int
    {
        $override = (int) edge_config('nginx_ssl_port', 0);
        if ($override > 0) {
            return $override;
        }
        // Behind an SNI router — forced via config, or auto-detected because the
        // host already has a `stream {}` splitter that owns :443.
        if ((bool) edge_config('behind_sni_router', false) || $stack->nginxHasStreamConfig) {
            return $this->nginxInternalPort();
        }

        return (int) edge_config('listen', 443);
    }

    /** MIME types worth compressing (already-compressed images/video excluded). */
    private const COMPRESSIBLE = 'text/plain text/css application/json application/javascript '
        . 'text/xml application/xml application/xml+rss application/rss+xml '
        . 'application/atom+xml application/vnd.ms-fontobject font/ttf font/otf '
        . 'image/svg+xml text/javascript';

    /**
     * Resolve the configured compression preference for a given server, using
     * that server's own Brotli availability. `auto` → brotli when available else
     * gzip; an explicit `brotli` also degrades to gzip when the module is absent,
     * so the emitted config never fails the server's own config test.
     */
    private function compressionMode(bool $brotliAvailable): string
    {
        $mode = (string) edge_config('compression', 'auto');
        if ($mode === 'auto') {
            return $brotliAvailable ? 'brotli' : 'gzip';
        }
        if ($mode === 'brotli' && !$brotliAvailable) {
            return 'gzip';
        }

        return $mode; // brotli (available) | gzip | off
    }

    /** nginx gzip/brotli block (server context), gated on nginx's own ngx_brotli. */
    private function nginxCompression(ServerStack $stack): string
    {
        $types  = self::COMPRESSIBLE;
        $gzip   = "    gzip on;\n    gzip_vary on;\n    gzip_min_length 256;\n"
            . "    gzip_proxied any;\n    gzip_comp_level 6;\n    gzip_types {$types};\n";
        $brotli = "    brotli on;\n    brotli_comp_level 6;\n    brotli_min_length 256;\n    brotli_types {$types};\n";

        return match ($this->compressionMode($stack->nginxHasBrotli)) {
            'brotli' => $brotli . $gzip, // gzip fallback for clients without `br`
            'gzip'   => $gzip,
            default  => '',             // 'off' → nothing
        };
    }

    /**
     * Apache compression, gated on the modules actually loaded. `AddOutputFilterByType`
     * needs mod_filter; the filters need mod_brotli / mod_deflate. A requested
     * mode degrades to whatever the host can do (brotli → deflate → nothing)
     * rather than emitting a directive that would fail configtest.
     */
    private function apacheCompression(ServerStack $stack): string
    {
        $types = self::COMPRESSIBLE;
        $mode  = $this->compressionMode($stack->apacheHasModule('brotli'));

        if ($mode === 'off' || !$stack->apacheHasModule('filter')) {
            return '';
        }

        $out = '';
        if ($mode === 'brotli') { // compressionMode() already confirmed mod_brotli
            $out .= "    AddOutputFilterByType BROTLI_COMPRESS {$types}\n";
        }
        // gzip fallback (and the whole gzip mode) whenever mod_deflate is present.
        if ($stack->apacheHasModule('deflate')) {
            $out .= "    AddOutputFilterByType DEFLATE {$types}\n";
        }

        return $out;
    }

    /**
     * The HSTS Strict-Transport-Security value, or '' when disabled.
     *
     * The DEVELOPMENT profile emits a deliberately SHORT max-age with NO
     * includeSubDomains and NEVER preload: a dev host (often *.local with a
     * self-signed cert) must not pin a browser to HTTPS-for-a-year — that is
     * near-impossible to undo per-browser and would also suppress the very
     * plain-HTTP request the --tls=both redirect exists to catch. PRODUCTION keeps
     * the long max-age; `preload` stays opt-in (hsts.preload) because it is very
     * hard to reverse and must never be a silent default.
     *
     * A null profile (the Apache-only path) is treated as production.
     */
    private function hstsValue(?CacheProfile $profile = null): string
    {
        if (!(bool) edge_config('hsts.enabled', true)) {
            return '';
        }

        if ($profile?->isDevelopment() === true) {
            $devMaxAge = (int) edge_config('hsts.dev_max_age', 300);

            return "max-age={$devMaxAge}";
        }

        $value = 'max-age=' . (int) edge_config('hsts.max_age', 31536000);
        if ((bool) edge_config('hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }
        if ((bool) edge_config('hsts.preload', false)) {
            $value .= '; preload';
        }

        return $value;
    }

    /** nginx `add_header Strict-Transport-Security …` at the given indent ('' when off/plain). */
    private function nginxHsts(int $indent, ?CacheProfile $profile = null): string
    {
        $value = $this->hstsValue($profile);

        return $value === '' ? '' : str_repeat(' ', $indent) . "add_header Strict-Transport-Security \"{$value}\" always;\n";
    }

    /** Apache `Header always set Strict-Transport-Security …` ('' when off/plain). */
    private function apacheHsts(): string
    {
        $value = $this->hstsValue();

        return $value === '' ? '' : "    Header always set Strict-Transport-Security \"{$value}\"\n";
    }

    /** Render a TTL (seconds) as the cleanest nginx `expires` unit (1y / 30d / 3600s). */
    private function expiresValue(int $seconds): string
    {
        return match (true) {
            $seconds <= 0             => 'off',
            $seconds % 31536000 === 0 => intdiv($seconds, 31536000) . 'y',
            $seconds % 86400 === 0    => intdiv($seconds, 86400) . 'd',
            $seconds % 3600 === 0     => intdiv($seconds, 3600) . 'h',
            default                   => $seconds . 's',
        };
    }

    private function escapeNginx(string $v): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $v);
    }

    private function escapeApache(string $v): string
    {
        return str_replace('"', '\\"', $v);
    }

    /** @param array<string, string> $vars */
    private function fill(string $template, array $vars): string
    {
        return rtrim(strtr($template, $vars), "\n") . "\n";
    }
}
