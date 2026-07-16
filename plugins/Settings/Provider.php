<?php

declare(strict_types=1);

namespace Plugins\Settings;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Database\TransactionManager;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\DatabasePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Settings\API\Contracts\SettingsServiceContract;
use Plugins\Settings\Application\Services\SettingsService;
use Plugins\Settings\Infrastructure\Persistence\SettingsRepository;

final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'tenant.settings';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return ['database.management', 'validation.rules'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [SettingsServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(SettingsRepository::class, static fn(ModuleContainer $c) =>
            new SettingsRepository(
                $c->make(DatabasePort::class),
            )
        );

        $container->bind(SettingsServiceContract::class, static fn(ModuleContainer $c) =>
            new SettingsService(
                repository:  $c->make(SettingsRepository::class),
                transaction: $c->make(TransactionManager::class),
                identity:    $c->make(Identity::class),
            )
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // No pipeline hooks or event subscriptions.
    }
}
