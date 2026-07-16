<?php

declare(strict_types=1);

namespace Plugins\Edge\Application;

use Plugins\Edge\API\Contracts\EdgeServiceContract;
use Plugins\Edge\Domain\EdgePlan;
use Plugins\Edge\Domain\ServerStack;
use Plugins\Edge\Domain\Strategy;
use Plugins\Edge\Infrastructure\ConfigRenderer;
use Plugins\Edge\Infrastructure\DomainCollector;
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
    ) {}

    public function detect(): ServerStack
    {
        return $this->probe->detect();
    }

    public function plan(): EdgePlan
    {
        $stack           = $this->probe->detect();
        $strategy        = $stack->strategy();
        $domains         = $this->domains->collect();
        [$path, $body]   = $this->renderer->render($strategy, $domains);

        return new EdgePlan($stack, $strategy, $domains, $path, $body);
    }

    public function apply(bool $reload = true, bool $dryRun = false): array
    {
        $plan = $this->plan();

        if ($plan->strategy === Strategy::None) {
            return [
                'ok'       => false,
                'strategy' => Strategy::None->value,
                'message'  => 'No active web server detected — nothing to apply.',
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
            ];
        }

        // Write the config atomically (temp file + rename) so a live include
        // never sees a half-written file.
        $dir = dirname($plan->targetPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return ['ok' => false, 'strategy' => $plan->strategy->value, 'message' => "Cannot create directory {$dir}"];
        }
        $tmp = $plan->targetPath . '.tmp';
        if (@file_put_contents($tmp, $plan->contents) === false || !@rename($tmp, $plan->targetPath)) {
            @unlink($tmp);
            return ['ok' => false, 'strategy' => $plan->strategy->value, 'message' => "Failed to write {$plan->targetPath}"];
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
                return ['ok' => false, 'strategy' => $plan->strategy->value, 'path' => $plan->targetPath, 'steps' => $steps, 'message' => trim($tout)];
            }

            [$rc, $rout] = $this->probe->run($reloadCmd);
            $steps[] = "reload: {$reloadCmd} → " . ($rc === 0 ? 'ok' : 'FAILED');
            if ($rc !== 0) {
                return ['ok' => false, 'strategy' => $plan->strategy->value, 'path' => $plan->targetPath, 'steps' => $steps, 'message' => trim($rout)];
            }
        }

        return [
            'ok'       => true,
            'strategy' => $plan->strategy->value,
            'path'     => $plan->targetPath,
            'domains'  => \count($plan->domains),
            'steps'    => $steps,
        ];
    }
}
