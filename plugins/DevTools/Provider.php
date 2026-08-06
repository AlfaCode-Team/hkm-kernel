<?php

declare(strict_types=1);

namespace Plugins\DevTools;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\DevTools\Commands\ConfigClearCommand;
use Plugins\DevTools\Commands\ConfigShowCommand;
use Plugins\DevTools\Commands\MakePluginCommand;
use Plugins\DevTools\Commands\MakeServiceCommand;
use Plugins\DevTools\Commands\ModuleListCommand;
use Plugins\DevTools\Commands\ModuleInfoCommand;
use Plugins\DevTools\Commands\ProjectListCommand;
use Plugins\DevTools\Commands\RoutesListCommand;

/**
 * DevTools plugin — GDA scaffolding generators (make:plugin, make:service).
 *
 * Pure developer tooling: no domain, no routes. Registers CLI commands that
 * emit GDA-compliant skeletons so new plugins follow the architecture by
 * default.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'dev.tooling';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return [];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [];
    }

    public function register(ModuleContainer $container): void
    {
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        $cli->command(MakePluginCommand::class);
        $cli->command(MakeServiceCommand::class);
        $cli->command(ModuleListCommand::class);
        $cli->command(ModuleInfoCommand::class);
        $cli->command(RoutesListCommand::class);
        $cli->command(ProjectListCommand::class);
        $cli->command(ConfigShowCommand::class);
        $cli->command(ConfigClearCommand::class);
    }
}
