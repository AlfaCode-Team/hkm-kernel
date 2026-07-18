<?php

declare(strict_types=1);

namespace Plugins\Edge\Application;

use Plugins\Edge\API\Contracts\EdgeServiceContract;
use Plugins\Edge\Domain\EdgePlan;
use Plugins\Edge\Domain\ServerStack;
use Plugins\Edge\Domain\Strategy;
use Plugins\Edge\Infrastructure\ConfigRenderer;
use Plugins\Edge\Infrastructure\HostsFileWriter;
use Plugins\Edge\Infrastructure\SiteCollector;
use Plugins\Edge\Infrastructure\SystemProbe;

/**
 * Orchestrates the edge workflow: probe the host → decide a strategy (in the
 * ServerStack domain) → render the config → write and (optionally) validate +
 * reload the web server. Holds no state; safe to resolve per request/command.
 */
final class EdgeService implements EdgeServiceContract
{
    public function __construct(
        private readonly SystemProbe $probe,
        private readonly SiteCollector $sites,
        private readonly ConfigRenderer $renderer,
        private readonly HostsFileWriter $hosts,
    ) {}

    public function detect(): ServerStack
    {
        return $this->probe->detect();
    }

    public function phpFpm(): array
    {
        return [
            'version' => $this->probe->phpCliVersion(),
            'socket'  => $this->probe->phpFpmSocket(),
            'active'  => $this->probe->phpFpmActive(),
        ];
    }

    public function plan(bool $all = false): EdgePlan
    {
        $stack    = $this->probe->detect();
        $strategy = $stack->strategy();

        // Default: ONLY the current project. --all renders every registered one.
        // Public domains → server config; local (.local/.test) → /etc/hosts.
        $sites = $this->sites->sites($all);

        [$path, $body] = $this->renderer->render($strategy, $sites);

        return new EdgePlan($stack, $strategy, $sites, $this->sites->localDomains($all), $path, $body);
    }

    /**
     * /etc/hosts is a DEVELOPER-machine concern (local .local/.test domains) —
     * a live server resolves its public domains through DNS. So this refuses to
     * run unless the launcher marked the invocation as dev (`hkm … --dev`,
     * which exports HKM_DEV=1), unless explicitly forced.
     */
    public function syncHosts(bool $remove = false, bool $dryRun = false, bool $all = false, bool $force = false): array
    {
        if (!$force && !$this->isDev()) {
            return [
                'ok'      => false,
                'path'    => (string) edge_config('hosts.path', '/etc/hosts'),
                'count'   => 0,
                'message' => 'refusing to touch the hosts file outside dev mode — run with `--dev` (or pass --force). '
                           . 'On a live server public domains resolve via DNS, not /etc/hosts.',
            ];
        }

        return $this->hosts->sync(
            domains: $this->sites->localDomains($all),
            ip:      (string) edge_config('hosts.ip', '127.0.0.1'),
            path:    (string) edge_config('hosts.path', '/etc/hosts'),
            remove:  $remove,
            dryRun:  $dryRun,
        );
    }

    /** Did the launcher run us in dev mode (`--dev` exports HKM_DEV=1)? */
    private function isDev(): bool
    {
        return filter_var(env('HKM_DEV', 'false'), FILTER_VALIDATE_BOOL);
    }

    public function apply(bool $reload = true, bool $dryRun = false, ?bool $manageHosts = null, bool $all = false): array
    {
        $plan = $this->plan($all);

        // 1. Local domains → /etc/hosts. DEV ONLY: a live server resolves its
        //    public domains via DNS, so outside dev we silently skip this step
        //    rather than touching the machine's hosts file.
        $hosts = null;
        if (($manageHosts ?? (bool) edge_config('manage_hosts', true)) && $this->isDev()) {
            $hosts = $this->syncHosts(dryRun: $dryRun, all: $all);
        }

        if ($plan->strategy === Strategy::None) {
            return [
                'ok'       => ($hosts['ok'] ?? true) === true,
                'strategy' => Strategy::None->value,
                'hosts'    => $hosts,
                'message'  => 'No active web server detected — only local hosts were synced.',
            ];
        }

        if ($dryRun) {
            return [
                'ok'       => true,
                'dry_run'  => true,
                'strategy' => $plan->strategy->value,
                'path'     => $plan->targetPath,
                'sites'    => \count($plan->sites),
                'contents' => $plan->contents,
                'hosts'    => $hosts,
            ];
        }

        // 2. Write the server config atomically (temp file + rename) so a live
        //    include never sees a half-written file.
        $dir = dirname($plan->targetPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'strategy' => $plan->strategy->value, 'hosts' => $hosts, 'message' => "Cannot create directory {$dir}"];
        }
        $tmp = $plan->targetPath . '.tmp';
        if (@file_put_contents($tmp, $plan->contents) === false || !@rename($tmp, $plan->targetPath)) {
            @unlink($tmp);
            return ['ok' => false, 'strategy' => $plan->strategy->value, 'hosts' => $hosts, 'message' => "Failed to write {$plan->targetPath}"];
        }

        $siteCount = \count($plan->sites);
        $steps = ["wrote {$plan->targetPath} ({$siteCount} project site(s))"];

        if ($reload) {
            $isApache = $plan->strategy === Strategy::ApacheOnly;
            $testCmd   = (string) edge_config($isApache ? 'commands.apache_test'   : 'commands.nginx_test');
            $reloadCmd = (string) edge_config($isApache ? 'commands.apache_reload' : 'commands.nginx_reload');

            [$tc, $tout] = $this->probe->run($testCmd);
            $steps[] = "test: {$testCmd} → " . ($tc === 0 ? 'ok' : 'FAILED');
            if ($tc !== 0) {
                return ['ok' => false, 'strategy' => $plan->strategy->value, 'path' => $plan->targetPath, 'steps' => $steps, 'hosts' => $hosts, 'message' => trim($tout)];
            }

            [$rc, $rout] = $this->probe->run($reloadCmd);
            $steps[] = "reload: {$reloadCmd} → " . ($rc === 0 ? 'ok' : 'FAILED');
            if ($rc !== 0) {
                return ['ok' => false, 'strategy' => $plan->strategy->value, 'path' => $plan->targetPath, 'steps' => $steps, 'hosts' => $hosts, 'message' => trim($rout)];
            }
        }

        return [
            'ok'       => true,
            'strategy' => $plan->strategy->value,
            'path'     => $plan->targetPath,
            'sites'    => \count($plan->sites),
            'steps'    => $steps,
            'hosts'    => $hosts,
        ];
    }
}
