<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Edge\API\Contracts\EdgeServiceContract;

/**
 * edge:apply — detect the stack, render the matching config from the platform's
 * domains, write it, then validate + reload the web server.
 *
 *   hkm edge:apply              # write + `nginx -t`/`apachectl configtest` + reload
 *   hkm edge:apply --dry-run    # print the config that WOULD be written, touch nothing
 *   hkm edge:apply --no-reload  # write the file only (skip test + reload)
 */
final class EdgeApplyCommand extends AbstractCommand
{
    public function __construct(private readonly EdgeServiceContract $edge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'edge:apply';
        $this->description = 'Generate the nginx/Apache edge config from platform domains, then reload the server';

        $this->addOption('dry-run', '', 'Print the config that would be written; change nothing');
        $this->addOption('no-reload', '', 'Write the config file but do not validate or reload');
        $this->addOption('no-hosts', '', 'Skip writing local (.local/.test) domains to /etc/hosts');
    }

    protected function handle(): int
    {
        $dryRun = $this->hasOption('dry-run');
        $reload = !$this->hasOption('no-reload');
        $hosts  = $this->hasOption('no-hosts') ? false : null; // null = use config default

        $result = $this->edge->apply(reload: $reload, dryRun: $dryRun, manageHosts: $hosts);

        $this->reportHosts($result['hosts'] ?? null);

        if (($result['ok'] ?? false) !== true) {
            $this->error('Edge apply failed [' . ($result['strategy'] ?? '?') . ']: ' . ($result['message'] ?? 'unknown error'));
            foreach ((array) ($result['steps'] ?? []) as $step) {
                $this->muted('  - ' . $step);
            }

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('strategy: ' . $result['strategy'] . '  →  ' . $result['path'] . '  (' . $result['domains'] . ' domains)');
            $this->newLine();
            $this->muted($result['contents']);

            return self::SUCCESS;
        }

        $this->success('Edge applied [' . $result['strategy'] . ']');
        foreach ((array) ($result['steps'] ?? []) as $step) {
            $this->info('  - ' . $step);
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed>|null $hosts */
    private function reportHosts(?array $hosts): void
    {
        if ($hosts === null) {
            return;
        }
        $count = (int) ($hosts['count'] ?? 0);
        $path  = (string) ($hosts['path'] ?? '/etc/hosts');

        if (($hosts['ok'] ?? false) !== true) {
            $this->warning("hosts: {$count} local domain(s) NOT written — " . ($hosts['message'] ?? 'error'));
            return;
        }
        if (($hosts['dry_run'] ?? false) === true) {
            $this->info("hosts: would sync {$count} local domain(s) to {$path}");
            return;
        }
        $verb = ($hosts['changed'] ?? false) ? 'synced' : 'already current';
        $this->info("hosts: {$verb} {$count} local domain(s) in {$path}");
    }
}
