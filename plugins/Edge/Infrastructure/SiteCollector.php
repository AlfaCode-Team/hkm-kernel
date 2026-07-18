<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

use Plugins\Edge\Domain\ServeModel;
use Plugins\Edge\Domain\SwooleOptions;
use Plugins\Edge\Domain\Site;

/**
 * Builds the list of Sites the edge serves from the GLOBAL project registry
 * (projects.json → name/path/domains) plus each project's proj.json (serve
 * model + extra env), and resolves the run-env that must be injected into every
 * vhost so the served project actually boots.
 *
 * Public domains feed the server config; local (.local/.test) domains are
 * collected separately for /etc/hosts. Every hostname is strictly validated
 * before it can reach a rendered config.
 */
final class SiteCollector
{
    public function __construct(private readonly SystemProbe $probe = new SystemProbe()) {}

    /**
     * @param bool $all false (default) = ONLY the current project (read from
     *                  base_path()/proj.json); true = every registered project.
     * @return list<Site>
     */
    public function sites(bool $all = false, ?string $appEnv = null): array
    {
        if (!$all) {
            $site = $this->currentSite($appEnv);

            return $site !== null ? [$site] : [];
        }

        $sites = [];
        foreach ($this->projects() as $name => $project) {
            $site = $this->buildSite((string) $name, (string) ($project['path'] ?? ''), (array) ($project['domains'] ?? []), $appEnv);
            if ($site !== null) {
                $sites[] = $site;
            }
        }

        return $sites;
    }

    /** Local (dev-only) domains for the current project (or all projects). */
    public function localDomains(bool $all = false): array
    {
        $local = [];
        foreach ($this->sites($all) as $site) {
            foreach ($site->localDomains as $d) {
                $local[] = $d;
            }
        }
        if ($all) {
            foreach ($this->classify((array) edge_config('extra_domains', []))['local'] as $d) {
                $local[] = $d;
            }
        }
        $local = array_values(array_unique($local));
        sort($local);

        return $local;
    }

    /** The project the command is running in — its own proj.json is the truth. */
    private function currentSite(?string $appEnv = null): ?Site
    {
        $path = rtrim((string) base_path(), '/');
        $proj = $this->projJson($path);
        $name = (string) ($proj['name'] ?? basename($path));

        return $this->buildSite($name, $path, (array) ($proj['domains'] ?? []), $appEnv);
    }

    /** @param array<int, mixed> $domains */
    private function buildSite(string $name, string $path, array $domains, ?string $appEnv = null): ?Site
    {
        $path = rtrim($path, '/');
        $cls  = $this->classify($domains);
        if ($path === '' || ($cls['public'] === [] && $cls['local'] === [])) {
            return null;
        }

        $edge = (array) ($this->projJson($path)['edge'] ?? []);
        // `runtime` is the current spelling; `serve` is kept for older proj.json
        // files. Both accept php-fpm|openswoole as well as fpm|swoole.
        $model = ServeModel::from_((string) (
            $edge['runtime'] ?? $edge['serve'] ?? edge_config('serve.model', 'fpm')
        ));
        $swoole = $model === ServeModel::Swoole ? $this->swooleOptions($edge) : null;

        // In dev mode (`hkm … --dev` → HKM_DEV=1) — or when EDGE_LOCAL_IN_SERVER
        // is forced — the local (.local/.test) domains are ALSO served by the
        // vhost, not just written to /etc/hosts. A production run keeps them out
        // (public domains resolve through DNS). Local domains still feed /etc/hosts
        // in both cases, so the loopback mapping is present for the served vhost.
        $public = $this->serveLocalInServer()
            ? array_values(array_unique([...$cls['public'], ...$cls['local']]))
            : $cls['public'];

        return new Site(
            name:          $name,
            docroot:       $path . '/app/public',
            publicDomains: $public,
            localDomains:  $cls['local'],
            model:         $model,
            upstream:      $swoole?->upstream() ?? $this->upstream($model, $edge),
            env:           $this->env($edge, $appEnv),
            swoole:        $swoole,
            root:          $path,
        );
    }

    // ── registries ────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function projects(): array
    {
        $file = (string) edge_config('projects_registry', '');
        if ($file === '' || !is_file($file)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($file), true);

        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    private function projJson(string $path): array
    {
        $file = $path . '/proj.json';
        if (!is_file($file)) {
            return [];
        }
        $json = json_decode((string) file_get_contents($file), true);

        return is_array($json) ? $json : [];
    }

    // ── serving + env ───────────────────────────────────────────────────────

    /**
     * Should local (.local/.test) domains be served by the vhost — not just
     * written to /etc/hosts? True in dev mode (the launcher exports HKM_DEV=1 for
     * `--dev`) or when EDGE_LOCAL_IN_SERVER is explicitly forced. False for a
     * production run, where public domains resolve through DNS.
     */
    private function serveLocalInServer(): bool
    {
        if (filter_var(\env('HKM_DEV', 'false'), FILTER_VALIDATE_BOOL)) {
            return true;
        }

        return (bool) edge_config('include_local_in_server', false);
    }

    /**
     * Per-project OpenSwoole settings: config defaults, overridden by the
     * project's proj.json `edge.openswoole` block (or the flat `edge.port`).
     *
     * @param array<string, mixed> $edge
     */
    private function swooleOptions(array $edge): SwooleOptions
    {
        $o = (array) ($edge['openswoole'] ?? $edge['swoole'] ?? []);

        // A null/empty path disables the block; omitted falls back to config.
        $ws = array_key_exists('websocket', $o)
            ? (is_string($o['websocket']) && trim($o['websocket']) !== '' ? trim($o['websocket']) : null)
            : (string) edge_config('serve.websocket_path', '/ws');
        $health = array_key_exists('health', $o)
            ? (is_string($o['health']) && trim($o['health']) !== '' ? trim($o['health']) : null)
            : ((bool) edge_config('serve.health.enabled', false)
                ? (string) edge_config('serve.health.path', '/health')
                : null);

        // `ports: [9501, 9502, 9503]` spins one upstream server per port; the
        // first is the primary, the rest become extra backends.
        $host  = (string) ($o['host'] ?? edge_config('serve.swoole_host', '127.0.0.1'));
        $ports = array_values(array_filter(array_map('intval', (array) ($o['ports'] ?? []))));
        $port  = $ports[0] ?? (int) ($o['port'] ?? $edge['port'] ?? edge_config('serve.swoole_port', 9501));
        $extra = array_map(static fn (int $p): string => "{$host}:{$p}", array_slice($ports, 1));

        return new SwooleOptions(
            host:          $host,
            port:          $port,
            websocketPath: $ws === '' ? null : $ws,
            healthPath:    $health === '' ? null : $health,
            php:           (string) ($o['php'] ?? edge_config('serve.swoole_php', '/usr/bin/php')),
            command:       (string) ($o['command'] ?? edge_config('serve.swoole_command', 'bin/server.php')),
            workers:       (string) ($o['workers'] ?? edge_config('serve.swoole_workers', 'auto')),
            extraServers:  array_values(array_unique([...$extra, ...array_filter(array_map(
                static fn ($v): string => trim((string) $v),
                (array) ($o['servers'] ?? [])
            ))])),
            balance:           (string) ($o['balance'] ?? edge_config('serve.swoole_balance', 'least_conn')),
            maxFails:          (int) ($o['max_fails'] ?? edge_config('serve.swoole_max_fails', 3)),
            failTimeout:       (string) ($o['fail_timeout'] ?? edge_config('serve.swoole_fail_timeout', '30s')),
            keepalive:         (int) ($o['keepalive'] ?? edge_config('serve.swoole_keepalive', 32)),
            keepaliveTimeout:  (string) ($o['keepalive_timeout'] ?? edge_config('serve.swoole_keepalive_timeout', '60s')),
            keepaliveRequests: (int) ($o['keepalive_requests'] ?? edge_config('serve.swoole_keepalive_requests', 1000)),
        );
    }

    /** @param array<string, mixed> $edge */
    private function upstream(ServeModel $model, array $edge): string
    {
        if ($model === ServeModel::Swoole) {
            // Normally supplied by SwooleOptions::upstream(); kept as a fallback.
            $host = (string) edge_config('serve.swoole_host', '127.0.0.1');
            $port = (int) ($edge['port'] ?? edge_config('serve.swoole_port', 9501));

            return "{$host}:{$port}";
        }

        // FPM: an explicit per-project socket, else an explicit EDGE_FPM_SOCKET,
        // else auto-resolve the socket matching the CLI PHP version (multi-PHP hosts).
        $explicit = (string) ($edge['socket'] ?? edge_config('serve.fpm_socket', ''));

        return $explicit !== '' ? $explicit : $this->probe->phpFpmSocket();
    }

    /**
     * The run-env injected into a site's vhost. Base env (APP_ENV, userdata,
     * kernel resolution) merged with per-project proj.json `edge.env` extras.
     *
     * @param array<string, mixed> $edge
     * @return array<string, string>
     */
    private function env(array $edge, ?string $appEnv = null): array
    {
        $env = [];

        $appEnv = (string) ($appEnv ?? edge_config('app_env', 'production'));
        if ($appEnv !== '') {
            $env['APP_ENV'] = $appEnv;
        }

        // Pass through the kernel-resolution env the launcher already exported for
        // the active context (dev vs live). We read it straight from the process
        // environment — no deriving, no defaulting. FPM workers don't inherit it,
        // so the vhost must carry whatever `hkm` set.
        if ((bool) edge_config('inject_kernel_env', true)) {
            foreach ((array) edge_config('kernel_env_keys', []) as $key) {
                $value = (string) \env((string) $key, '');
                if ($value !== '') {
                    $env[(string) $key] = $value;
                }
            }
        }

        // Per-project extras win.
        foreach ((array) ($edge['env'] ?? []) as $k => $v) {
            if (is_string($k)) {
                $env[$k] = (string) $v;
            }
        }

        return $env;
    }

    // ── domain classification (validated) ─────────────────────────────────────

    /**
     * @param  array<int, mixed> $domains
     * @return array{public: list<string>, local: list<string>}
     */
    private function classify(array $domains): array
    {
        $exclude = array_map('strtolower', (array) edge_config('exclude_domains', []));
        $public  = [];
        $local   = [];
        foreach ($domains as $domain) {
            $host = strtolower(trim((string) $domain));
            if ($host === '' || !$this->isValid($host) || in_array($host, $exclude, true)) {
                continue;
            }
            if ($this->isLocal($host)) {
                $local[] = $host;
            } else {
                $public[] = $host;
            }
        }

        return ['public' => array_values(array_unique($public)), 'local' => array_values(array_unique($local))];
    }

    private function isValid(string $host): bool
    {
        if (preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return true;
        }

        return (bool) preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $host);
    }

    private function isLocal(string $host): bool
    {
        if (!str_contains($host, '.')) {
            return true;
        }
        $tld  = strtolower(substr((string) strrchr($host, '.'), 1));
        $tlds = array_map('strtolower', (array) edge_config('local_tlds', ['local', 'test', 'localhost', 'example', 'invalid']));

        return in_array($tld, $tlds, true);
    }
}
