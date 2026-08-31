<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Sample;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\Ground\Tests\Fixtures\Sample\Persistence\SampleRepository;

/**
 * A minimal but REAL plugin, used to test the ground.
 *
 * It has one internal binding and one published contract on purpose: those two
 * together are what the scope-isolation rules are about, and a fixture without
 * them could not prove the harness enforces anything.
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'ground.sample';
    }

    public function requires(): array
    {
        return [];
    }

    public function exposes(): array
    {
        return [SampleServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(
            SampleRepository::class,
            static fn($c): SampleRepository => new SampleRepository($c->make(DatabasePort::class)),
        );

        $container->bind(
            SampleServiceContract::class,
            static fn($c): SampleService => new SampleService(
                $c->make(SampleRepository::class),
                $c->make(EventBus::class),
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        $cli->command(SampleCommand::class);
    }
}
