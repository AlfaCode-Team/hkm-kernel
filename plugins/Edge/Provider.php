<?php

declare(strict_types=1);

namespace Plugins\Edge;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\Edge\API\Contracts\EdgeServiceContract;
use Plugins\Edge\Application\EdgeService;
use Plugins\Edge\Infrastructure\Cli\EdgeApplyCommand;
use Plugins\Edge\Infrastructure\Cli\EdgeHostsCommand;
use Plugins\Edge\Infrastructure\Cli\EdgeStatusCommand;
use Plugins\Edge\Infrastructure\ConfigRenderer;
use Plugins\Edge\Infrastructure\HostsFileWriter;
use Plugins\Edge\Infrastructure\SiteCollector;
use Plugins\Edge\Infrastructure\SystemProbe;

/**
 * Edge plugin — generates the host's web-server front config from the platform's
 * registered domains. It probes nginx/Apache, chooses a strategy (SNI stream
 * split, nginx-only, or Apache-only), renders the config, and reloads.
 *
 * The service is DI-free (its collaborators read edge_config()), so both the
 * published contract and the CLI commands construct it directly — no ports, no
 * database. ON-DEMAND: a route that needs it declares "requires": ["edge.routing"].
 * Its real home is the CLI (edge:status / edge:apply).
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'edge.routing';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [EdgeServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bind(EdgeServiceContract::class, static fn (): EdgeService => self::service());
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // CLI-only: defer so only CLI processes construct the commands.
        $cli->defer(static function (CliPipeline $cli): void {
            $service = self::service();
            $cli->command(new EdgeStatusCommand($service));
            $cli->command(new EdgeApplyCommand($service));
            $cli->command(new EdgeHostsCommand($service));
        });
    }

    private static function service(): EdgeService
    {
        $probe = new SystemProbe();

        return new EdgeService(
            $probe,
            new SiteCollector($probe),
            new ConfigRenderer(),
            new HostsFileWriter(),
        );
    }
}
