<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Contracts;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;

/**
 * ModuleContract — every module's Provider.php implements this.
 *
 * The kernel reads ONLY the manifest (module.json) to boot.
 * It calls register() then boot() on each module during OnDemandLoader.
 */
interface ModuleContract
{
    /**
     * The single domain this module owns.
     * Must match module.json "solves" field exactly.
     *
     * @example 'invoice.generation'
     */
    public function solves(): string;

    /**
     * Module DOMAINS this module requires — the solves values of the plugins it
     * depends on. ONE CONVENTION: return exactly module.json "requires" (the
     * single source of truth the kernel actually reads; this method is
     * documentation and must stay in sync). Ports (DatabasePort, …) resolve via
     * CoreContainer and are NOT listed. Every entry must be a registered
     * module's solves domain — the boot fails on anything else.
     *
     * @return list<string>
     * @example ['database.management', 'view.rendering']
     */
    public function requires(): array;

    /**
     * Contracts this module exposes to other modules.
     * Must match module.json "exposes" field.
     *
     * @return class-string[]
     * @example [InvoiceServiceContract::class]
     */
    public function exposes(): array;

    /**
     * Register DI bindings into the module's scoped container.
     * Called once per request when this module is loaded by OnDemandLoader.
     *
     * - Use $container->bindInternal() for internal-only bindings
     * - Use $container->bind() for published contract bindings
     */
    public function register(ModuleContainer $container): void;

    /**
     * Register pipeline hooks and event subscriptions.
     * Called after ALL required modules have been registered.
     *
     * Hook slots:  'after.security', 'after.load', 'after.execute'
     * Hook priority: lower number = runs first (1–9 system, 10–99 modules)
     *
     * @example $http->hook('after.security', RateLimiterStage::class, priority: 10);
     * @example $events->subscribe('payment.succeeded', PaymentSucceededListener::class);
     */
    public function boot(
        HttpPipeline   $http,
        CliPipeline    $cli,
        WorkerPipeline $worker,
        EventBus       $events,
    ): void;
}
