<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\CommandModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;

/**
 * Declares three commands in module.json but registers only one in boot():
 * DeclaredCommand must come from commands[] alone; BothCommand must not be
 * registered twice; "InventoryOnlyCommand" is a bare name and must be skipped.
 */
final class Provider implements ModuleContract
{
    public function solves(): string { return 'test.commands'; }

    public function requires(): array { return []; }

    public function exposes(): array { return []; }

    public function register(ModuleContainer $container): void {}

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        $cli->command(BothCommand::class);
    }
}
