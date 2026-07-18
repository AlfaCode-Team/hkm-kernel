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
    public function render(Strategy $strategy, array $sites, TlsConfig $tls, ServerStack $stack, CacheProfile $profile): array
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
                $this->stream($sites) . "\n"
                    . $this->nginxVhosts($sites, $tls->withMode(TlsMode::Ssl), $httpPort, $this->nginxInternalPort(), $stack, $profile),
            ],
            Strategy::NginxOnly => [
                (string) edge_config('paths.nginx'),
                $this->nginxVhosts($sites, $tls, $httpPort, $sslPort, $stack, $profile),
            ],
            Strategy::ApacheOnly => [
                (string) edge_config('paths.apache'),
                $this->apacheVhosts($sites, $tls, $httpPort, $sslPort, $stack),
            ],
            Strategy::None => ['', ''],
        };
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
     * compression, HSTS, security headers, the cache-profile banner, the static
     * asset location and the no-store headers for dynamic responses. Kept in ONE
     * place so the PHP-FPM and OpenSwoole vhosts can never drift apart.
     *
     * @return array{dev: bool, logs: string, cors: string, compression: string,
     *               hsts: string, secHeaders: string, banner: string,
     *               static: string, devLoc: string, dynamicHeaders: string,
     *               rateLimit: string, proxyHeaders: string}
     */
    private function vhostCommon(Site $site, TlsConfig $tls, ServerStack $stack, CacheProfile $profile): array
    {
        // Dev-only extras (verbose debug logging, permissive CORS, Disallow-all
        // robots, stub_status). These follow the APP_ENV-derived cache profile so
        // NOTHING in the vhost is inferred from the kernel mode (HKM_DEV); set
        // EDGE_DEV_VHOST to force them on/off independently.
        $devOverride = edge_config('dev_vhost', null);
        $dev = $devOverride === null ? $profile->isDevelopment() : (bool) $devOverride;

        // When the prelude owns logging, every vhost logs through its format
        // (with buffering); otherwise only dev gets a per-project log.
        $preludeOn = (bool) edge_config('http_prelude.enabled', false);
        $format    = (string) edge_config('http_prelude.log_format', 'cf_realip');
        if ($preludeOn && $format !== '') {
            $buffer = (string) edge_config('http_prelude.log_buffer', '32k');
            $flush  = (string) edge_config('http_prelude.log_flush', '5s');
            $logs = "    access_log /var/log/nginx/{$site->name}.access.log {$format} buffer={$buffer} flush={$flush};\n"
                . "    error_log  /var/log/nginx/{$site->name}.error.log " . ($dev ? 'debug' : 'warn') . ";\n";
        } else {
            $logs = $dev
                ? "    access_log /var/log/nginx/{$site->name}.access.log combined;\n"
                    . "    error_log  /var/log/nginx/{$site->name}.error.log debug;\n"
                : '';
        }

        $cors = $dev
            ? "    add_header Access-Control-Allow-Origin \"*\" always;\n"
                . "    add_header Access-Control-Allow-Methods \"GET, POST, PUT, DELETE, PATCH, OPTIONS\" always;\n"
                . "    add_header Access-Control-Allow-Headers \"Content-Type, Authorization, X-Requested-With\" always;\n"
            : '';

        $compression = $this->nginxCompression($stack);
        $usesTls     = $tls->mode->usesTls();
        $hsts        = $usesTls ? $this->nginxHsts(4) : '';

        // A location with its OWN add_header drops ALL inherited ones, so the
        // security headers (and HSTS on TLS) are repeated wherever we add others.
        $secHeaders = "        add_header X-Content-Type-Options \"nosniff\" always;\n"
            . "        add_header X-Frame-Options \"SAMEORIGIN\" always;\n"
            . "        add_header Referrer-Policy \"strict-origin-when-cross-origin\" always;\n"
            . ($usesTls ? $this->nginxHsts(8) : '');

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
        $dynamicHeaders = $cache === '' ? '' : "\n" . $secHeaders . $cache;

        // Proxied (OpenSwoole) responses: development forces no-store so assets and
        // HTML are never stale; PRODUCTION deliberately adds nothing so the
        // application keeps control of its own Cache-Control.
        $proxyCache   = (!$cacheHtml && $cacheDev) ? $cache : '';
        $proxyHeaders = $proxyCache === '' ? '' : "\n" . $secHeaders . $proxyCache;

        // Static assets. Disabled (no-store) in dev or when asset caching is off;
        // otherwise immutable, long-lived caching of FINGERPRINTED assets only.
        if ($cacheDev || !$cacheAssets) {
            $assetExt   = 'css|js|map|json|png|jpg|jpeg|gif|svg|webp|ico|woff|woff2|ttf|otf|eot|pdf|txt|xml';
            $assetCache = "        expires off;\n"
                . "        add_header Cache-Control \"no-store, no-cache, must-revalidate\" always;\n"
                . "        add_header Pragma \"no-cache\" always;\n"
                . "        add_header Expires \"0\" always;\n"
                . $secHeaders
                . "        access_log off;\n";
        } else {
            $assetExt   = 'css|js|map|png|jpg|jpeg|gif|svg|webp|ico|woff|woff2|ttf|otf|eot';
            $control    = $cloudflare ? 'public, immutable' : 'public';
            $assetCache = "        expires " . $this->expiresValue($assetTtl) . ";\n"
                . "        add_header Cache-Control \"{$control}\" always;\n"
                . $secHeaders
                . "        access_log off;\n";
        }
        // Static assets resolve ONLY under the public root; a miss is a hard 404
        // and is never forwarded to the application (same rule for both runtimes).
        $static = "    location ~* \\.({$assetExt})\$ {\n"
            . $assetCache
            . "        try_files \$uri =404;\n    }";

        $devLoc = $dev
            ? "\n    location = /robots.txt {\n        access_log off;\n"
                . "        return 200 \"User-agent: *\\nDisallow: /\\n\";\n    }\n\n"
                . "    location /nginx-status {\n        stub_status on;\n"
                . "        allow 127.0.0.1;\n        allow ::1;\n        deny all;\n    }\n"
            : '';

        return [
            'dev'            => $dev,
            'logs'           => $logs,
            'rateLimit'      => $this->nginxRateLimit(),
            'cors'           => $cors,
            'compression'    => $compression,
            'hsts'           => $hsts,
            'secHeaders'     => $secHeaders,
            'banner'         => $profile->banner(),
            'static'         => $static,
            'devLoc'         => $devLoc,
            'dynamicHeaders' => $dynamicHeaders,
            'proxyHeaders'   => $proxyHeaders,
        ];
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
            ? $this->nginxRedirect($site->publicDomains, $httpPort, $sslPort) . "\n\n"
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
%LOGS%%CORS%    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
%HSTS%    server_tokens off;
    client_max_body_size 25m;
%RATELIMIT%%COMPRESSION%
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

    # Static assets.
%STATIC%

    # Hidden files denied, but .well-known stays reachable (ACME/Let's Encrypt).
    location ~ /\.(?!well-known) { deny all; }

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
            '%CORS%'     => $c['cors'],
            '%HSTS%'     => $c['hsts'],
            '%COMPRESSION%' => $c['compression'],
            '%RATELIMIT%' => $c['rateLimit'],
            '%FCHEADERS%' => $c['dynamicHeaders'],
            '%STATIC%'   => $c['static'],
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
            foreach ($ranges as $range) {
                $out .= 'set_real_ip_from ' . trim((string) $range) . ";\n";
            }
            $out .= "real_ip_header {$header};\n"
                . "real_ip_recursive on;\n";
        }

        return rtrim($out, "\n") . "\n";
    }

    /** Per-vhost `limit_req` / `limit_conn`, matching the zones in the prelude. */
    private function nginxRateLimit(): string
    {
        if (!(bool) edge_config('http_prelude.enabled', false)
            || !(bool) edge_config('http_prelude.rate_limit.enabled', true)) {
            return '';
        }

        $reqZone  = (string) edge_config('http_prelude.rate_limit.req_zone', 'general');
        $burst    = (int) edge_config('http_prelude.rate_limit.req_burst', 50);
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
            ? $this->nginxRedirect($site->publicDomains, $httpPort, $sslPort) . "\n\n"
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
%LOGS%%CORS%    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
%HSTS%    server_tokens off;
    client_max_body_size 25m;
%RATELIMIT%%COMPRESSION%
    # Static assets are served straight off disk from the public root above.
    # A miss is a 404 — never forwarded to OpenSwoole.
%STATIC%

    # Hidden files denied, but .well-known stays reachable (ACME/Let's Encrypt).
    location ~ /\.(?!well-known) { deny all; }
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
            '%CORS%'         => $c['cors'],
            '%HSTS%'         => $c['hsts'],
            '%COMPRESSION%'  => $c['compression'],
            '%RATELIMIT%'    => $c['rateLimit'],
            '%STATIC%'       => $c['static'],
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

        $listen = "listen {$sslPort} ssl;\n    http2 on;";
        $ssl    = "\n    ssl_certificate     {$tls->cert};\n    ssl_certificate_key {$tls->key};\n";

        return [$listen, $ssl];
    }

    /**
     * A plain-HTTP server that 301-redirects to HTTPS (the `both` mode).
     *
     * @param list<string> $domains
     */
    private function nginxRedirect(array $domains, int $httpPort, int $sslPort): string
    {
        $names  = implode(' ', $domains);
        $target = $sslPort === 443
            ? 'https://$host$request_uri'
            : "https://\$host:{$sslPort}\$request_uri";

        return <<<NGINX
        server {
            listen {$httpPort};
            server_name {$names};
            return 301 {$target};
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

    /** The HSTS Strict-Transport-Security value from config, or '' when disabled. */
    private function hstsValue(): string
    {
        if (!(bool) edge_config('hsts.enabled', true)) {
            return '';
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
    private function nginxHsts(int $indent): string
    {
        $value = $this->hstsValue();

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
