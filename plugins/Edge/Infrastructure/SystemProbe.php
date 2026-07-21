<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

use Plugins\Edge\Domain\ServerStack;

/**
 * Talks to the operating system (the "vendor" here is the host itself): detects
 * which web servers are installed/active and whether nginx has the stream
 * module, and runs validate/reload commands. All shell access is funnelled
 * through run() so nothing else in the plugin shells out directly.
 */
final class SystemProbe
{
    /** Probe the host and build an immutable ServerStack snapshot. */
    public function detect(): ServerStack
    {
        $nginxInstalled  = $this->which('nginx');
        $apacheInstalled = $this->which('apache2') || $this->which('httpd') || $this->which('apachectl');

        return new ServerStack(
            nginxInstalled:  $nginxInstalled,
            nginxActive:     $this->active('nginx'),
            nginxHasStream:  $nginxInstalled && $this->nginxHasStream(),
            apacheInstalled: $apacheInstalled,
            apacheActive:    $this->active('apache2') || $this->active('httpd'),
            nginxHasBrotli:  $nginxInstalled && $this->nginxHasBrotli(),
            apacheModules:   $apacheInstalled ? $this->apacheModules() : [],
            nginxHasStreamConfig: $nginxInstalled && $this->nginxStreamConfigExists((string) edge_config('paths.stream', '')),
        );
    }

    /**
     * Does the RUNNING nginx already declare an SNI stream splitter (a `stream {}`
     * block using `ssl_preread`) in a config file OTHER than the one Edge manages?
     */
    private function nginxStreamConfigExists(string $ownPath): bool
    {
        return $this->nginxStreamConfigFile($ownPath) !== null;
    }

    /**
     * The ON-DISK path of the config file that holds the RUNNING nginx's SNI
     * stream splitter (the `map $ssl_preread_server_name … { … }`), or null when
     * none exists — so Edge can UPDATE that file's map in place instead of writing
     * a second, conflicting splitter.
     *
     * `nginx -T` dumps the full, resolved config, prefixing each file with a
     * `# configuration file <path>:` marker. We walk it file-by-file, skip Edge's
     * own managed file (so re-runs never match themselves), and return the first
     * OTHER file that declares the ssl_preread map. `ssl_preread` and that map only
     * ever appear inside a stream server, so their presence is a reliable signal.
     */
    public function nginxStreamConfigFile(string $ownPath): ?string
    {
        [$code, $dump] = $this->run('nginx -T');
        if ($code !== 0 || trim($dump) === '') {
            return null;
        }

        $own = ($ownPath !== '' ? (realpath($ownPath) ?: $ownPath) : '');

        $current = '';
        $byFile  = [];
        foreach (explode("\n", $dump) as $line) {
            if (preg_match('/^#\s*configuration file\s+(.+):\s*$/', $line, $m)) {
                $current = trim($m[1]);
                $byFile[$current] ??= '';
                continue;
            }
            if ($current !== '') {
                $byFile[$current] .= $line . "\n";
            }
        }

        foreach ($byFile as $path => $body) {
            if ($own !== '' && (realpath($path) ?: $path) === $own) {
                continue; // Edge's own managed file — not a pre-existing config
            }
            // The map is the splitter's routing table; ssl_preread confirms it's a
            // real SNI stream server and not an incidental mention.
            if (str_contains($body, 'ssl_preread') && preg_match('/map\s+\$ssl_preread_server_name\s+\$\w+\s*\{/', $body)) {
                // Only files that still exist on disk can be updated in place.
                if (is_file($path) && is_writable($path)) {
                    return $path;
                }
                if (is_file($path)) {
                    return $path; // exists but not writable — caller reports "need sudo"
                }
            }
        }

        return null;
    }

    /**
     * The Apache modules currently LOADED, as short names (no `_module` suffix),
     * parsed from `apachectl -M`. Empty list = couldn't probe (caller treats
     * that as "unknown", not "absent"). Tries the common front-ends in turn.
     */
    public function apacheModules(): array
    {
        foreach (['apache2ctl -M', 'apachectl -M', 'httpd -M'] as $cmd) {
            [$code, $out] = $this->run($cmd);
            if ($code !== 0 || trim($out) === '') {
                continue;
            }
            // Lines look like "  headers_module (shared)"; grab the module name.
            preg_match_all('/^\s*(\w+)_module\b/m', $out, $m);
            if ($m[1] !== []) {
                return array_values(array_unique($m[1]));
            }
        }

        return [];
    }

    /** The PHP version running THIS command, e.g. "8.4". */
    public function phpCliVersion(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    /**
     * Resolve the PHP-FPM upstream that matches the CLI PHP version running the
     * command, so a multi-PHP host binds the vhost to the RIGHT pool:
     *   1. the versioned socket for the CLI version (Debian/Ubuntu naming),
     *   2. any versioned socket present — the exact version, else the newest,
     *   3. a generic/unversioned socket (RHEL, custom),
     *   4. a TCP fallback (127.0.0.1:9000, common in containers).
     */
    public function phpFpmSocket(): string
    {
        $ver = $this->phpCliVersion();

        foreach (["/run/php/php{$ver}-fpm.sock", "/var/run/php/php{$ver}-fpm.sock"] as $sock) {
            if (@file_exists($sock)) {
                return "unix:{$sock}";
            }
        }

        $socks = array_merge(glob('/run/php/php*-fpm.sock') ?: [], glob('/var/run/php/php*-fpm.sock') ?: []);
        if ($socks !== []) {
            // exact CLI version wins; otherwise the newest available pool.
            usort($socks, fn (string $a, string $b): int => version_compare($this->sockVersion($b), $this->sockVersion($a)));
            foreach ($socks as $s) {
                if ($this->sockVersion($s) === $ver) {
                    return "unix:{$s}";
                }
            }
            return "unix:{$socks[0]}";
        }

        foreach (['/run/php-fpm/www.sock', '/var/run/php-fpm/www.sock', '/run/php/php-fpm.sock'] as $sock) {
            if (@file_exists($sock)) {
                return "unix:{$sock}";
            }
        }

        return '127.0.0.1:9000';
    }

    /** Which php*-fpm services systemd reports as active (best-effort, for status). */
    public function phpFpmActive(): array
    {
        [$code, $out] = $this->run("systemctl list-units --type=service --state=active --no-legend 'php*-fpm*.service'");
        if ($code !== 0 || trim($out) === '') {
            return [];
        }
        $names = [];
        foreach (explode("\n", trim($out)) as $line) {
            if (preg_match('/(php[0-9.]*-fpm[^\s]*)\.service/', $line, $m)) {
                $names[] = $m[1];
            }
        }

        return array_values(array_unique($names));
    }

    private function sockVersion(string $path): string
    {
        return preg_match('/php(\d+\.\d+)-fpm\.sock$/', $path, $m) ? $m[1] : '0';
    }

    /** Run an arbitrary command; returns [exitCode, combinedOutput]. */
    public function run(string $command): array
    {
        $output = [];
        $code   = 0;
        @exec($command . ' 2>&1', $output, $code);

        return [$code, implode("\n", $output)];
    }

    private function which(string $binary): bool
    {
        [$code] = $this->run('command -v ' . escapeshellarg($binary));

        return $code === 0;
    }

    /**
     * Is a service active? Prefer systemd; fall back to a process match so it
     * still works on non-systemd hosts / inside containers.
     */
    private function active(string $service): bool
    {
        [$code, $out] = $this->run('systemctl is-active ' . escapeshellarg($service));
        if ($code === 0 && trim($out) === 'active') {
            return true;
        }

        [$pcode] = $this->run('pgrep -x ' . escapeshellarg($service));

        return $pcode === 0;
    }

    /** Does the installed nginx support the stream (L4) module? */
    private function nginxHasStream(): bool
    {
        [, $banner] = $this->run('nginx -V');
        if (str_contains($banner, '--with-stream')) {
            return true;
        }

        // Dynamic module shipped separately (Debian/RHEL common paths).
        foreach ([
            '/usr/lib/nginx/modules/ngx_stream_module.so',
            '/usr/lib64/nginx/modules/ngx_stream_module.so',
            '/etc/nginx/modules/ngx_stream_module.so',
        ] as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }

    /** Was the installed nginx built with (or shipped) the ngx_brotli module? */
    private function nginxHasBrotli(): bool
    {
        [, $banner] = $this->run('nginx -V');
        if (str_contains($banner, 'brotli')) {
            return true;
        }

        foreach ([
            '/usr/lib/nginx/modules/ngx_http_brotli_filter_module.so',
            '/usr/lib64/nginx/modules/ngx_http_brotli_filter_module.so',
            '/etc/nginx/modules/ngx_http_brotli_filter_module.so',
        ] as $path) {
            if (is_file($path)) {
                return true;
            }
        }

        return false;
    }
}
