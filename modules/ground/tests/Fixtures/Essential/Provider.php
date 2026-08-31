<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Essential;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;

/**
 * An ESSENTIAL-style module: nothing requires it, and its binding must be
 * present on every request anyway. The fixture for the divergence where the
 * ground's own container omitted essentials that HttpPipeline supplied.
 */
final class Provider implements ModuleContract
{
    public function solves(): string { return 'ground.essential'; }

    public function requires(): array { return []; }

    public function exposes(): array { return [AmbientContract::class]; }

    public function register(ModuleContainer $container): void
    {
        $container->bind(AmbientContract::class, static fn(): Ambient => new Ambient());
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
    }
}
