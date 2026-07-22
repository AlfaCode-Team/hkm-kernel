<?php

declare(strict_types=1);

namespace Plugins\SecurityFilters;

use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\ApiRateLimitStage;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\HmacSignedStage;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\RequireAuthStage;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\SecurityHeadersStage;
use Plugins\SecurityFilters\Infrastructure\Http\Stages\ShieldStage;

/**
 * SecurityFilters plugin — the 0.3 HTTP filters rebuilt as GDA pipeline stages.
 *
 * Routing decides when each stage enforces: every stage inspects the resolved
 * request path/method and only acts on its configured scope, otherwise it calls
 * $next untouched. Priorities follow the kernel convention (10-19 security).
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'http.security_filters';
    }

    /** @return list<class-string> */
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
        // Stages are constructed by the pipeline (no-arg) and resolve any
        // services from the request-scoped container at handle time.
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // ── GLOBAL hooks — always-on, every request/response ─────────────────
        // These genuinely apply to ALL traffic, so they stay global (not route
        // filters). One stage: it answers CORS preflight (OPTIONS) before auth so
        // it is never rejected, then decorates every outgoing response with the
        // CORS + OWASP security headers on the way back out.
        $http->hook('after.security', SecurityHeadersStage::class, priority: 10);

        // ── DECLARATIVE route filters — opt-in per route ─────────────────────
        // Routes name these in module.json / proj.json:
        //   "filters": ["auth", "throttle:60,1"]
        // They are NOT also registered as global hooks — a stage runs through
        // exactly ONE mechanism, so e.g. the rate limiter never double-counts.
        // (Stages keep their internal env gate — RATE_LIMIT_PREFIX etc. — which
        // now scopes WITHIN a route that opted in.)
        $http->filter('auth',     RequireAuthStage::class);
        $http->filter('throttle', ApiRateLimitStage::class);
        $http->filter('hmac',     HmacSignedStage::class);
        $http->filter('shield',   ShieldStage::class);
    }
}
