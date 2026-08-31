<?php

declare(strict_types=1);

namespace AlfacodeTeam\Ground\Tests\Fixtures\Drifted;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;

/**
 * DELIBERATELY WRONG — the negative fixture for InspectorTest.
 *
 * Every disagreement here is one the kernel does NOT catch: it reads module.json
 * and never calls these methods, so this plugin boots and serves while telling
 * two different stories about itself. Do not "fix" it; the test asserts each one
 * is reported.
 */
final class Provider implements ModuleContract
{
    // module.json says ground.drifted.
    public function solves(): string { return 'ground.elsewhere'; }

    // module.json says a CLASS name (itself a finding).
    public function requires(): array { return ['some.other.domain']; }

    // module.json names a contract that does not exist.
    public function exposes(): array { return []; }

    public function register(ModuleContainer $container): void {}

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void {}
}
