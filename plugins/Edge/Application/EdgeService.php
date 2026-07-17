<?php

declare(strict_types=1);

namespace Plugins\Edge\Application;

use Plugins\Edge\API\Contracts\EdgeServiceContract;
use Plugins\Edge\Domain\EdgePlan;
use Plugins\Edge\Domain\ServerStack;
use Plugins\Edge\Domain\Strategy;
use Plugins\Edge\Infrastructure\ConfigRenderer;
use Plugins\Edge\Infrastructure\DomainCollector;
use Plugins\Edge\Infrastructure\HostsFileWriter;
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
        private readonly DomainCollector $domains,
        private readonly ConfigRenderer $renderer,
        private readonly HostsFileWriter $hosts,
    ) {}

    public function detect(): ServerStack
    {
        return $this->probe->detect();
    }

    public function plan(): EdgePlan
    {
        $stack    = $this->probe->detect();
        $strategy = $stack->strategy();

        // Local domains (.local / .test / …) are dev-only — they go to /etc/hosts,
        // NOT the public server config, unless EDGE_LOCAL_IN_SERVER is set.
        $split         = $this->domains->split();
        $serverDomains = (bool) edge_config('include_local_in_server', false)
            ? array_values(array_unique([...$split['public'], ...$split['local']]))
            : $split['public'];
        sort($serverDomains);

        [$path, $body] = $this->renderer->render($strategy, $serverDomains);

        return new EdgePlan($stack, $strategy, $serverDomains, $split['local'], $path, $body);
    }

    public function syncHosts(bool $remove = false, bool $dryRun = false): array
    {
        return $this->hosts->sync(
            domains: $this->domains->split()['local'],
            ip:      (string) edge_config('hosts.ip', '127.0.0.1'),
            path:    (string) edge_config('hosts.path', '/etc/hosts'),
            remove:  $remove,
            dryRun:  $dryRun,
        );
    }

    public function apply(bool $reload = true, bool $dryRun = false, ?bool $manageHosts = null): array
    {
        $plan = $this->plan();

        // 1. Local domains → /etc/hosts (independent of any web server, so it
        //    still runs when the strategy is None).
        $hosts = null;
        if ($manageHosts ?? (bool) edge_config('manage_hosts', true)) {
            $hosts = $this->syncHosts(dryRun: $dryRun);
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
                'domains'  => \count($plan->domains),
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

        $domainCount = \count($plan->domains);
        $steps = ["wrote {$plan->targetPath} ({$domainCount} domains)"];

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
            'domains'  => \count($plan->domains),
            'steps'    => $steps,
            'hosts'    => $hosts,
        ];
    }
}
