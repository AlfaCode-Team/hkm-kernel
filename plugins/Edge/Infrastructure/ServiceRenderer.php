<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

use Plugins\Edge\Domain\ServeModel;
use Plugins\Edge\Domain\Site;

/**
 * Pure renderer — no I/O. Turns an OpenSwoole Site into a process-manager unit so
 * the app server is actually kept alive behind the nginx reverse proxy:
 *
 *   systemd     → hkm-<project>.service   (WantedBy=multi-user.target)
 *   supervisor  → [program:hkm-<project>] block
 *
 * PHP-FPM sites have no unit — php-fpm already manages those workers.
 */
final class ServiceRenderer
{
    /** Unit/program name for a project, e.g. `hkm-blog`. */
    public function unitName(Site $site): string
    {
        return 'hkm-' . preg_replace('/[^a-z0-9._-]+/i', '-', $site->name);
    }

    /** Does this site need a process-manager unit at all? */
    public function supports(Site $site): bool
    {
        return $site->model === ServeModel::Swoole && $site->swoole !== null;
    }

    /**
     * A systemd unit. $user/$group default to www-data; env is injected as
     * Environment= lines so the Swoole process boots with the same run-env the
     * FPM vhost would have received.
     */
    public function systemd(Site $site, string $user = 'www-data', string $group = 'www-data'): string
    {
        $sw   = $site->swoole;
        $root = $site->root !== '' ? $site->root : dirname($site->docroot, 2);
        $exec = $this->execStart($site);

        $env = '';
        foreach ($this->serviceEnv($site) as $k => $v) {
            $env .= sprintf("Environment=%s=%s\n", $k, $this->escapeSystemd($v));
        }

        $tpl = <<<'UNIT'
# Managed by the HKM Edge plugin (`hkm edge:service`). Do NOT edit by hand.
# OpenSwoole application server for "%NAME%" — nginx reverse-proxies to %UPSTREAM%.
[Unit]
Description=HKM OpenSwoole server for %NAME%
After=network.target

[Service]
Type=simple
User=%USER%
Group=%GROUP%
WorkingDirectory=%ROOT%
%ENV%ExecStart=%EXEC%
ExecReload=/bin/kill -USR1 $MAINPID
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=30
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
UNIT;

        return $this->fill($tpl, [
            '%NAME%'     => $site->name,
            '%UPSTREAM%' => $sw->upstream(),
            '%USER%'     => $user,
            '%GROUP%'    => $group,
            '%ROOT%'     => $root,
            '%ENV%'      => $env,
            '%EXEC%'     => $exec,
        ]);
    }

    /** A supervisor program block. */
    public function supervisor(Site $site, string $user = 'www-data'): string
    {
        $root = $site->root !== '' ? $site->root : dirname($site->docroot, 2);
        $name = $this->unitName($site);

        $env = [];
        foreach ($this->serviceEnv($site) as $k => $v) {
            $env[] = $k . '="' . str_replace('"', '\"', $v) . '"';
        }
        $envLine = $env === [] ? '' : 'environment=' . implode(',', $env) . "\n";

        $tpl = <<<'UNIT'
; Managed by the HKM Edge plugin (`hkm edge:service`). Do NOT edit by hand.
; OpenSwoole application server for "%NAME%" — nginx reverse-proxies to %UPSTREAM%.
[program:%UNIT%]
command=%EXEC%
directory=%ROOT%
user=%USER%
%ENV%autostart=true
autorestart=true
startsecs=5
stopsignal=TERM
stopwaitsecs=30
redirect_stderr=true
stdout_logfile=/var/log/supervisor/%UNIT%.log
UNIT;

        return $this->fill($tpl, [
            '%NAME%'     => $site->name,
            '%UNIT%'     => $name,
            '%UPSTREAM%' => $site->swoole->upstream(),
            '%EXEC%'     => $this->execStart($site),
            '%ROOT%'     => $root,
            '%USER%'     => $user,
            '%ENV%'      => $envLine,
        ]);
    }

    /** `<php> <command>` — absolute, so systemd never depends on $PATH. */
    private function execStart(Site $site): string
    {
        $sw   = $site->swoole;
        $root = $site->root !== '' ? $site->root : dirname($site->docroot, 2);
        $cmd  = str_starts_with($sw->command, '/') ? $sw->command : $root . '/' . ltrim($sw->command, '/');

        return $sw->php . ' ' . $cmd;
    }

    /**
     * Env for the Swoole process: the site's run-env (APP_ENV + kernel
     * resolution) plus the bind host/port and worker count, so the server can
     * read them instead of hard-coding.
     *
     * @return array<string, string>
     */
    private function serviceEnv(Site $site): array
    {
        $sw  = $site->swoole;
        $env = $site->env;

        $env['SWOOLE_HOST'] = $sw->host;
        $env['SWOOLE_PORT'] = (string) $sw->port;
        if (strtolower($sw->workers) !== 'auto' && $sw->workers !== '') {
            $env['SWOOLE_WORKERS'] = $sw->workers;
        }

        return $env;
    }

    /** systemd Environment= values: keep it single-line and quoted when needed. */
    private function escapeSystemd(string $v): string
    {
        $v = str_replace(["\n", "\r"], ' ', $v);

        return str_contains($v, ' ') ? '"' . str_replace('"', '\"', $v) . '"' : $v;
    }

    /** @param array<string, string> $vars */
    private function fill(string $template, array $vars): string
    {
        return rtrim(strtr($template, $vars), "\n") . "\n";
    }
}
