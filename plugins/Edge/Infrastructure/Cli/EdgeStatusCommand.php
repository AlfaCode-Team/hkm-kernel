<?php

declare(strict_types=1);

namespace Plugins\Edge\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Edge\API\Contracts\EdgeServiceContract;

/**
 * edge:status — probe the host and report the detected web-server stack, the
 * strategy that WOULD be applied, and the domains + target path. Read-only.
 *
 *   hkm edge:status
 */
final class EdgeStatusCommand extends AbstractCommand
{
    public function __construct(private readonly EdgeServiceContract $edge)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name        = 'edge:status';
        $this->description = 'Detect nginx/Apache and show the edge routing strategy that would be applied';

        $this->addOption('all', '', 'Include every registered project (default: only the current one)');
        $this->addOption('nginx-only', '', 'Preview the nginx-only strategy (NO Apache fallback)');
        $this->addOption('apache-only', '', 'Preview the apache-only strategy (no fallback)');
    }

    protected function handle(): int
    {
        $force = match (true) {
            $this->hasOption('nginx-only') && $this->hasOption('apache-only') => 'both',
            $this->hasOption('nginx-only')  => 'nginx-only',
            $this->hasOption('apache-only') => 'apache-only',
            default                         => null,
        };
        if ($force === 'both') {
            $this->error('Pick at most ONE of --nginx-only or --apache-only.');

            return self::INVALID;
        }

        $plan  = $this->edge->plan($this->hasOption('all'), force: $force);
        $stack = $plan->stack;

        $this->section('Edge — detected stack');
        $yn = static fn (bool $b): string => $b ? 'yes' : 'no';
        $this->info('nginx installed : ' . $yn($stack->nginxInstalled));
        $this->info('nginx active    : ' . $yn($stack->nginxActive));
        $this->info('nginx stream    : ' . $yn($stack->nginxHasStream));
        $this->info('nginx stream cfg: ' . $yn($stack->nginxHasStreamConfig) . ($stack->nginxHasStreamConfig ? ' (existing splitter — will be reused)' : ''));
        $this->info('apache installed: ' . $yn($stack->apacheInstalled));
        $this->info('apache active   : ' . $yn($stack->apacheActive));

        $php = $this->edge->phpFpm();
        $this->info('php (cli)       : ' . $php['version']);
        $this->info('php-fpm socket  : ' . $php['socket']);
        if ($php['active'] !== []) {
            $this->info('php-fpm active  : ' . implode(', ', $php['active']));
        }
        $this->newLine();
        $this->success('strategy: ' . $plan->strategy->label() . ($plan->reuseStream ? ' — reusing the existing nginx stream splitter' : ''));
        $this->info('project sites  : ' . count($plan->sites));
        foreach ($plan->sites as $site) {
            $this->info(sprintf(
                '  • %s [%s → %s]  %s',
                $site->name,
                $site->model->value,
                $site->upstream,
                $site->publicDomains === [] ? '(no public domains)' : implode(', ', $site->publicDomains),
            ));
        }
        $this->info('local domains  : ' . count($plan->localDomains) . ($plan->localDomains === [] ? '' : ' → /etc/hosts (' . implode(', ', $plan->localDomains) . ')'));
        $this->info('target         : ' . ($plan->targetPath === '' ? '(none)' : $plan->targetPath));

        return self::SUCCESS;
    }
}
