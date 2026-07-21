<?php

declare(strict_types=1);

namespace Tests\Unit\Plugins\Edge;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Edge\Domain\CacheProfile;
use Plugins\Edge\Domain\ServeModel;
use Plugins\Edge\Domain\ServerStack;
use Plugins\Edge\Domain\Site;
use Plugins\Edge\Domain\Strategy;
use Plugins\Edge\Domain\TlsConfig;
use Plugins\Edge\Domain\TlsMode;
use Plugins\Edge\Infrastructure\ConfigRenderer;

/**
 * Feeds every generated DEVELOPMENT/PRODUCTION × TLS-mode vhost to a real
 * `nginx -t`. Skips cleanly when nginx is not installed so the suite still runs
 * everywhere, but proves the output is syntactically valid where it can.
 */
final class NginxSyntaxTest extends TestCase
{
    private ?string $dir = null;

    protected function setUp(): void
    {
        if ($this->nginxBinary() === null) {
            self::markTestSkipped('nginx not installed — skipping syntax validation.');
        }
        $this->dir = sys_get_temp_dir() . '/edge-nginx-' . bin2hex(random_bytes(4));
        @mkdir($this->dir . '/logs', 0755, true);
        // Self-signed cert so ssl_certificate directives resolve.
        $cert = $this->dir . '/cert.pem';
        $key  = $this->dir . '/key.pem';
        exec(sprintf(
            'openssl req -x509 -newkey rsa:2048 -keyout %s -out %s -days 1 -nodes -subj /CN=test 2>/dev/null',
            escapeshellarg($key),
            escapeshellarg($cert),
        ));
        file_put_contents($this->dir . '/fastcgi_params', "fastcgi_param QUERY_STRING \$query_string;\n");
    }

    protected function tearDown(): void
    {
        if ($this->dir !== null && is_dir($this->dir)) {
            exec('rm -rf ' . escapeshellarg($this->dir));
        }
    }

    /** @return array<string, array{CacheProfile, TlsMode}> */
    public static function matrix(): array
    {
        return NginxConfigRendererTest::matrix();
    }

    #[DataProvider('matrix')]
    public function test_generated_vhost_passes_nginx_t(CacheProfile $profile, TlsMode $mode): void
    {
        $site = new Site(
            name: 'hkmstd',
            docroot: '/srv/hkmstd/app/public',
            publicDomains: ['hkmstd.com', 'www.hkmstd.com'],
            localDomains: [],
            model: ServeModel::Fpm,
            upstream: 'unix:/run/php/php8.4-fpm.sock',
            env: ['APP_ENV' => 'production'],
            root: '/srv/hkmstd',
        );
        // Brotli off → gzip-only output, portable to nginx builds without ngx_brotli.
        $stack = new ServerStack(true, true, false, false, false, false, [], false);
        $tls   = new TlsConfig($mode, $this->dir . '/cert.pem', $this->dir . '/key.pem');

        [, $body] = (new ConfigRenderer())->render(Strategy::NginxOnly, [$site], $tls, $stack, $profile);

        // Point log/fastcgi/cert paths at the sandbox; declare the rate-limit zones.
        $body = str_replace(
            ['/var/log/nginx/', 'include fastcgi_params;'],
            [$this->dir . '/logs/', 'include ' . $this->dir . '/fastcgi_params;'],
            $body,
        );
        // `http2 on;` is valid only on nginx >= 1.25.1; strip it so the syntax
        // check tolerates the older nginx that CI/distros ship (its presence is
        // asserted separately by the behavioral test).
        $body = (string) preg_replace('/^\s*http2 on;\R/m', '', $body);
        // pid + error_log are supplied via -g (universally supported) rather than
        // written into the config body or the newer -e flag (older nginx rejects
        // -e), and kept OUT of the body so there is no duplicate directive.
        $conf = $this->dir . '/site.conf';
        file_put_contents($conf, sprintf(
            "events {}\nhttp {\n"
            . "  access_log %1\$s/logs/access.log;\n  client_body_temp_path %1\$s/logs/body;\n"
            . "  proxy_temp_path %1\$s/logs/proxy;\n  fastcgi_temp_path %1\$s/logs/fcgi;\n"
            . "  limit_req_zone \$binary_remote_addr zone=general:10m rate=10r/s;\n"
            . "  limit_conn_zone \$binary_remote_addr zone=perip:10m;\n%2\$s\n}\n",
            $this->dir,
            $body,
        ));

        exec(sprintf(
            '%s -t -c %s -p %s -g %s 2>&1',
            $this->nginxBinary(),
            escapeshellarg($conf),
            escapeshellarg($this->dir),
            escapeshellarg("pid {$this->dir}/logs/nginx.pid; error_log {$this->dir}/logs/main.log;"),
        ), $out, $code);
        $output = implode("\n", $out);

        self::assertStringContainsString('syntax is ok', $output, $output);
        self::assertSame(0, $code, $output);
    }

    private function nginxBinary(): ?string
    {
        $path = trim((string) shell_exec('command -v nginx 2>/dev/null'));

        return $path !== '' ? $path : null;
    }
}
