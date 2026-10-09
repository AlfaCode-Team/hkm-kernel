<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\HookModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;

/** Registers one stage at each hook slot, so a test can see which ones run. */
final class Provider implements ModuleContract
{
    public function solves(): string { return 'test.hooks'; }

    public function requires(): array { return []; }

    public function exposes(): array { return []; }

    public function register(ModuleContainer $container): void {}

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        $http->hook('after.security', AfterSecurityStage::class);
        $http->hook('after.load', AfterLoadStage::class);
        $http->hook('after.execute', AfterExecuteStage::class);
    }
}
