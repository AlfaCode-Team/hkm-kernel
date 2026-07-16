<?php

declare(strict_types=1);

namespace Plugins\SiteSEO;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\SiteSEO\API\Contracts\SeoServiceContract;
use Plugins\SiteSEO\API\IntegrationEvents\UrlPublishedIntegrationEvent;
use Plugins\SiteSEO\Application\Jobs\IndexNowJob;
use Plugins\SiteSEO\Application\Listeners\EnqueueIndexNowListener;
use Plugins\SiteSEO\Application\Services\SeoService;
use Plugins\SiteSEO\Infrastructure\Gateways\SearchEngineGateway;

/**
 * SEO module provider — domain `seo.management`.
 *
 * Wires the SEO toolkit (Open Graph / schema / sitemap / robots) plus
 * search-engine submission, which reaches the network ONLY through
 * HttpClientPort (the `http.client` plugin).
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'seo.management';
    }

    /** @return list<class-string> */
    public function requires(): array
    {
        return ['http.client'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [SeoServiceContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        // Internal — outbound search-engine transport. Not resolvable outside this module.
        $container->bindInternal(SearchEngineGateway::class, static fn(ModuleContainer $c) =>
            new SearchEngineGateway(
                $c->make(HttpClientPort::class),
            )
        );

        // Published — the only surface other modules / project routes may resolve.
        $container->bind(SeoServiceContract::class, static fn(ModuleContainer $c) =>
            new SeoService(
                engines: $c->make(SearchEngineGateway::class),
            )
        );

        // Background job — resolved by the WorkerLoop from this module's scope,
        // so it autowires the (internal) gateway.
        $container->bind(IndexNowJob::class, static fn(ModuleContainer $c) =>
            new IndexNowJob(
                $c->make(SearchEngineGateway::class),
            )
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Index-on-publish: when anything emits seo.url_published, enqueue an
        // IndexNow submission. The listener is resolved from the CoreContainer,
        // where the project binds it with a QueuePort.
        $events->subscribe(
            (new UrlPublishedIntegrationEvent(''))->name(),
            EnqueueIndexNowListener::class,
        );
    }
}
