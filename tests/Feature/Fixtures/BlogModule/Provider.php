<?php

declare(strict_types=1);

namespace Tests\Feature\Fixtures\BlogModule;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Tests\Feature\Fixtures\BlogModule\API\Contracts\PostServiceContract;
use Tests\Feature\Fixtures\BlogModule\Application\Services\PostService;
use Tests\Feature\Fixtures\BlogModule\Infrastructure\Persistence\PostRepository;

final class Provider implements ModuleContract
{
    public function solves(): string { return 'blog.publishing'; }

    public function requires(): array { return []; }

    public function exposes(): array { return [PostServiceContract::class]; }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(PostRepository::class, static fn($c) => new PostRepository(
            $c->make(DatabasePort::class),
            $c->make(Identity::class),
        ));

        $container->bindInternal(TransactionManager::class, static fn($c) => new TransactionManager(
            $c->make(DatabasePort::class),
        ));

        $container->bind(PostServiceContract::class, static fn($c) => new PostService(
            repository:  $c->make(PostRepository::class),
            transaction: $c->make(TransactionManager::class),
            identity:    $c->make(Identity::class),
        ));
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void {}
}
