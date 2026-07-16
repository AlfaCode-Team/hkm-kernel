<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

use Plugins\Edge\Domain\Strategy;

/**
 * Pure config renderer — no I/O, no globals beyond edge_config(). Turns a
 * strategy + domain list into the text of an nginx stream router, an nginx
 * reverse-proxy vhost, or an Apache SSL VirtualHost.
 */
final class ConfigRenderer
{
    /**
     * @param list<string> $domains
     * @return array{0: string, 1: string} [targetPath, contents] ('' path for None)
     */
    public function render(Strategy $strategy, array $domains): array
    {
        return match ($strategy) {
            Strategy::NginxStream => [(string) edge_config('paths.stream'), $this->stream($domains)],
            Strategy::NginxOnly   => [(string) edge_config('paths.nginx'),  $this->nginx($domains)],
            Strategy::ApacheOnly  => [(string) edge_config('paths.apache'), $this->apache($domains)],
            Strategy::None        => ['', ''],
        };
    }

    /** nginx SNI (L4) stream splitter: listed domains → nginx, default → Apache. */
    private function stream(array $domains): string
    {
        $nginx  = (string) edge_config('upstreams.nginx');
        $apache = (string) edge_config('upstreams.apache');
        $listen = (int) edge_config('listen', 443);

        $map = '';
        foreach ($domains as $d) {
            $pad = str_repeat(' ', max(1, 42 - strlen($d)));
            $map .= "        {$d}{$pad}nginx_backend;\n";
        }

        $tpl = <<<'NGINX'
# Managed by the HKM Edge plugin (`hkm edge:apply`). Do NOT edit by hand.
#
# SNI TLS router: the ClientHello's server name is read WITHOUT decrypting
# (ssl_preread), then the raw TLS stream is forwarded to the matching backend.
# Listed platform domains go to nginx (%NGINX%); everything else falls back to
# Apache (%APACHE%). TLS is terminated by the chosen backend, not here.
#
# This block MUST live at the nginx MAIN context (top level of nginx.conf),
# NOT inside http{}. Include it from nginx.conf:  include %SELF%;
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
            '%NGINX%'  => $nginx,
            '%APACHE%' => $apache,
            '%LISTEN%' => (string) $listen,
            '%MAP%'    => $map,
            '%SELF%'   => (string) edge_config('paths.stream'),
        ]);
    }

    /** Plain nginx reverse-proxy vhost (no Apache present, no stream layer). */
    private function nginx(array $domains): string
    {
        $app    = (string) edge_config('upstreams.app');
        $cert   = (string) edge_config('ssl.cert');
        $key    = (string) edge_config('ssl.key');
        $names  = $domains === [] ? '_' : implode(' ', $domains);

        $tpl = <<<'NGINX'
# Managed by the HKM Edge plugin (`hkm edge:apply`). Do NOT edit by hand.
# nginx-only: no Apache on this host. nginx terminates TLS and reverse-proxies
# every platform domain to the application backend (%APP%).
upstream hkm_app_backend { server %APP%; }

server {
    listen %LISTEN% ssl;
    http2 on;
    server_name %NAMES%;

    ssl_certificate     %CERT%;
    ssl_certificate_key %KEY%;

    location / {
        proxy_pass http://hkm_app_backend;
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
            '%APP%'    => $app,
            '%LISTEN%' => (string) (int) edge_config('listen', 443),
            '%NAMES%'  => $names,
            '%CERT%'   => $cert,
            '%KEY%'    => $key,
        ]);
    }

    /** Apache SSL VirtualHost (Apache is the active server). */
    private function apache(array $domains): string
    {
        $app  = (string) edge_config('upstreams.app');
        $cert = (string) edge_config('ssl.cert');
        $key  = (string) edge_config('ssl.key');

        $primary = $domains[0] ?? '_';
        $aliases = '';
        foreach (array_slice($domains, 1) as $d) {
            $aliases .= "    ServerAlias {$d}\n";
        }

        $tpl = <<<'APACHE'
# Managed by the HKM Edge plugin (`hkm edge:apply`). Do NOT edit by hand.
# Apache-only: Apache terminates TLS and reverse-proxies every platform domain
# to the application backend (%APP%).
<VirtualHost *:%LISTEN%>
    ServerName %PRIMARY%
%ALIASES%
    SSLEngine on
    SSLCertificateFile    %CERT%
    SSLCertificateKeyFile %KEY%

    ProxyPreserveHost On
    ProxyPass        / http://%APP%/
    ProxyPassReverse / http://%APP%/
    RequestHeader set X-Forwarded-Proto "https"
</VirtualHost>
APACHE;

        return $this->fill($tpl, [
            '%APP%'     => $app,
            '%LISTEN%'  => (string) (int) edge_config('listen', 443),
            '%PRIMARY%' => $primary,
            '%ALIASES%' => rtrim($aliases, "\n"),
            '%CERT%'    => $cert,
            '%KEY%'     => $key,
        ]);
    }

    /** @param array<string, string> $vars */
    private function fill(string $template, array $vars): string
    {
        return rtrim(strtr($template, $vars), "\n") . "\n";
    }
}
