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
        );
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
}
