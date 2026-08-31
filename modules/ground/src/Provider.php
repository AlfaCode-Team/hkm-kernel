<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\Ground\Commands\MakeGroundTestCommand;
use AlfacodeTeam\Ground\Commands\MakeUiTestCommand;
use AlfacodeTeam\Ground\Commands\PluginCheckCommand;
use AlfacodeTeam\Ground\Commands\PluginMigrateCommand;
use AlfacodeTeam\Ground\Commands\PluginProbeCommand;
use AlfacodeTeam\Ground\Commands\PluginDevCommand;
use AlfacodeTeam\Ground\Commands\PluginDropCommand;
use AlfacodeTeam\Ground\Commands\PluginServeCommand;

/**
 * Ground — a test bench for developing and testing plugins.
 *
 * Solves `dev.ground`. It owns no business domain, exposes no contract, declares
 * no route and requires nothing. That is deliberate: a plugin used to test other
 * plugins must be able to load beside ANY of them without contributing to the
 * dependency graph it is measuring.
 *
 * Everything it provides is reached WITHOUT loading the module:
 *
 *   - {@see Ground\PluginGround} and the Fakes are plain autoloaded classes,
 *     used from PHPUnit where no kernel is running yet.
 *   - The three CLI commands are registered here, in boot().
 *
 * ────────────────────────────────────────────────────────────────────────────
 * NEVER enable this in production. It binds nothing, so it cannot break a
 * running app — but it ships a hasher that is fast by design and an encrypter
 * that only base64-encodes. Both exist to be bound in a test and are unsafe
 * anywhere else. Keep it in require-dev.
 * ────────────────────────────────────────────────────────────────────────────
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'dev.ground';
    }

    /** @return list<string> */
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
        // Nothing. The harness is constructed by the test, not resolved from a
        // container — it BUILDS containers, so resolving it from one would be
        // backwards.
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        $cli->command(PluginCheckCommand::class);
        $cli->command(PluginProbeCommand::class);
        $cli->command(PluginMigrateCommand::class);
        $cli->command(PluginServeCommand::class);
        $cli->command(PluginDevCommand::class);
        $cli->command(PluginDropCommand::class);
        $cli->command(MakeGroundTestCommand::class);
        $cli->command(MakeUiTestCommand::class);
    }
}
