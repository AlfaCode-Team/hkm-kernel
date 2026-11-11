<?php

declare(strict_types=1);

namespace Tests\Fixtures\FilterBadConsumerModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;

/** Fixture: only its providesFilters/requiresFilters declarations matter. */
final class Provider implements ModuleContract
{
    public function solves(): string { return 'FilterBadConsumer'; }

    public function requires(): array { return []; }

    public function exposes(): array { return []; }

    public function register(ModuleContainer $container): void {}

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void {}
}
