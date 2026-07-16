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
    }

    protected function handle(): int
    {
        $plan  = $this->edge->plan();
        $stack = $plan->stack;

        $this->section('Edge — detected stack');
        $yn = static fn (bool $b): string => $b ? 'yes' : 'no';
        $this->info('nginx installed : ' . $yn($stack->nginxInstalled));
        $this->info('nginx active    : ' . $yn($stack->nginxActive));
        $this->info('nginx stream    : ' . $yn($stack->nginxHasStream));
        $this->info('apache installed: ' . $yn($stack->apacheInstalled));
        $this->info('apache active   : ' . $yn($stack->apacheActive));
        $this->newLine();
        $this->success('strategy: ' . $plan->strategy->label());
        $this->info('domains : ' . count($plan->domains) . ($plan->domains === [] ? '' : ' (' . implode(', ', $plan->domains) . ')'));
        $this->info('target  : ' . ($plan->targetPath === '' ? '(none)' : $plan->targetPath));

        return self::SUCCESS;
    }
}
