<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure;

use Plugins\Edge\Domain\ServeModel;
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
    public function sites(bool $all = false): array
    {
        if (!$all) {
            $site = $this->currentSite();

            return $site !== null ? [$site] : [];
        }

        $sites = [];
        foreach ($this->projects() as $name => $project) {
            $site = $this->buildSite((string) $name, (string) ($project['path'] ?? ''), (array) ($project['domains'] ?? []));
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
    private function currentSite(): ?Site
    {
        $path = rtrim((string) base_path(), '/');
        $proj = $this->projJson($path);
        $name = (string) ($proj['name'] ?? basename($path));

        return $this->buildSite($name, $path, (array) ($proj['domains'] ?? []));
    }

    /** @param array<int, mixed> $domains */
    private function buildSite(string $name, string $path, array $domains): ?Site
    {
        $path = rtrim($path, '/');
        $cls  = $this->classify($domains);
        if ($path === '' || ($cls['public'] === [] && $cls['local'] === [])) {
            return null;
        }

        $edge  = (array) ($this->projJson($path)['edge'] ?? []);
        $model = ServeModel::from_((string) ($edge['serve'] ?? edge_config('serve.model', 'fpm')));

        return new Site(
            name:          $name,
            docroot:       $path . '/app/public',
            publicDomains: $cls['public'],
            localDomains:  $cls['local'],
            model:         $model,
            upstream:      $this->upstream($model, $edge),
            env:           $this->env($edge),
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

    /** @param array<string, mixed> $edge */
    private function upstream(ServeModel $model, array $edge): string
    {
        if ($model === ServeModel::Swoole) {
            $host = (string) edge_config('serve.swoole_host', '127.0.0.1');
            $port = (int) ($edge['port'] ?? edge_config('serve.swoole_base_port', 9500));

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
    private function env(array $edge): array
    {
        $env = [];

        $appEnv = (string) edge_config('app_env', 'production');
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
