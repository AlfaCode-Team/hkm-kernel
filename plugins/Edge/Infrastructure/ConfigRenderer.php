<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

use Plugins\Edge\Domain\ServeModel;
use Plugins\Edge\Domain\Site;
use Plugins\Edge\Domain\Strategy;

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
    public function render(Strategy $strategy, array $sites): array
    {
        return match ($strategy) {
            Strategy::NginxStream => [
                (string) edge_config('paths.stream'),
                $this->stream($sites) . "\n" . $this->nginxVhosts($sites, $this->nginxInternalPort()),
            ],
            Strategy::NginxOnly => [
                (string) edge_config('paths.nginx'),
                $this->nginxVhosts($sites, (int) edge_config('listen', 443)),
            ],
            Strategy::ApacheOnly => [
                (string) edge_config('paths.apache'),
                $this->apacheVhosts($sites, (int) edge_config('listen', 443)),
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
    private function nginxVhosts(array $sites, int $port): string
    {
        $out = "# Managed by the HKM Edge plugin (`hkm edge:apply`). Do NOT edit by hand.\n";
        foreach ($sites as $site) {
            if (!$site->servesPublic()) {
                continue;
            }
            $out .= "\n" . ($site->model === ServeModel::Swoole
                ? $this->nginxSwoole($site, $port)
                : $this->nginxFpm($site, $port));
        }

        return rtrim($out, "\n") . "\n";
    }

    private function nginxFpm(Site $site, int $port): string
    {
        $params = '';
        foreach ($site->env as $k => $v) {
            $params .= sprintf("            fastcgi_param %s \"%s\";\n", $k, $this->escapeNginx($v));
        }

        $tpl = <<<'NGINX'
# Project: %NAME% (PHP-FPM)
server {
    listen %PORT% ssl;
    http2 on;
    server_name %NAMES%;

    root %DOCROOT%;
    index index.php;

    ssl_certificate     %CERT%;
    ssl_certificate_key %KEY%;

    location ~ /\. { deny all; return 404; }

    location ~ \.php$ {
        location = /index.php {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root/index.php;
%PARAMS%            fastcgi_pass %UPSTREAM%;
        }
        return 404;
    }

    location / { try_files $uri /index.php$is_args$args; }

    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    server_tokens off;
    client_max_body_size 25m;
}
NGINX;

        return $this->fill($tpl, [
            '%NAME%'     => $site->name,
            '%PORT%'     => (string) $port,
            '%NAMES%'    => implode(' ', $site->publicDomains),
            '%DOCROOT%'  => $site->docroot,
            '%CERT%'     => (string) edge_config('ssl.cert'),
            '%KEY%'      => (string) edge_config('ssl.key'),
            '%PARAMS%'   => $params,
            '%UPSTREAM%' => $site->upstream,
        ]);
    }

    private function nginxSwoole(Site $site, int $port): string
    {
        $tpl = <<<'NGINX'
# Project: %NAME% (OpenSwoole) — env lives in the Swoole process:
#   hkm run %NAME% --swoole   (bind it to %UPSTREAM%)
server {
    listen %PORT% ssl;
    http2 on;
    server_name %NAMES%;

    ssl_certificate     %CERT%;
    ssl_certificate_key %KEY%;

    location / {
        proxy_pass http://%UPSTREAM%;
        proxy_http_version 1.1;
        proxy_set_header Host              $host;
        proxy_set_header X-Real-IP         $remote_addr;
        proxy_set_header X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_set_header Upgrade           $http_upgrade;
        proxy_set_header Connection        "upgrade";
    }
}
NGINX;

        return $this->fill($tpl, [
            '%NAME%'     => $site->name,
            '%PORT%'     => (string) $port,
            '%NAMES%'    => implode(' ', $site->publicDomains),
            '%CERT%'     => (string) edge_config('ssl.cert'),
            '%KEY%'      => (string) edge_config('ssl.key'),
            '%UPSTREAM%' => $site->upstream,
        ]);
    }

    // ── per-project Apache vhosts ─────────────────────────────────────────────

    /** @param list<Site> $sites */
    private function apacheVhosts(array $sites, int $port): string
    {
        $out = "# Managed by the HKM Edge plugin (`hkm edge:apply`). Do NOT edit by hand.\n";
        foreach ($sites as $site) {
            if (!$site->servesPublic()) {
                continue;
            }
            $out .= "\n" . $this->apacheSite($site, $port);
        }

        return rtrim($out, "\n") . "\n";
    }

    private function apacheSite(Site $site, int $port): string
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

        $tpl = <<<'APACHE'
# Project: %NAME%
<VirtualHost *:%PORT%>
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

    SSLEngine on
    SSLCertificateFile    %CERT%
    SSLCertificateKeyFile %KEY%

%SETENV%%HANDLER%

    ServerTokens Prod
    ServerSignature Off
    LimitRequestBody 26214400
</VirtualHost>
APACHE;

        return $this->fill($tpl, [
            '%NAME%'    => $site->name,
            '%PORT%'    => (string) $port,
            '%PRIMARY%' => $site->publicDomains[0] ?? '_',
            '%ALIASES%' => $aliases,
            '%DOCROOT%' => $site->docroot,
            '%CERT%'    => (string) edge_config('ssl.cert'),
            '%KEY%'     => (string) edge_config('ssl.key'),
            '%SETENV%'  => $setenv,
            '%HANDLER%' => $handler,
        ]);
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
