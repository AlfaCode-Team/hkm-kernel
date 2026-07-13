<?php

declare(strict_types=1);

namespace Plugins\SecurityFilters\Infrastructure\Http\Stages;

use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Request;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\Response;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\CachePort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;

/**
 * Sliding-window rate limiter (GDA rewrite of ApiRateLimit).
 *
 * Runs in TWO modes, chosen per request:
 *
 *  1. DECLARATIVE (route filter) — the route declared `throttle:MAX,MINUTES`
 *     (e.g. `throttle:5,10` = 5 requests / 10 minutes). The limit comes from the
 *     route's own args and the counter is scoped PER ROUTE, so a strict cap on
 *     one endpoint never eats another endpoint's budget. The route opting in IS
 *     the scope decision — no path-prefix gate.
 *  2. GLOBAL (if ever wired as a hook) — falls back to RATE_LIMIT_* env values
 *     and only enforces under RATE_LIMIT_PREFIX.
 *
 * Counters live in CachePort with TTL auto-expiry. Identity prefers the
 * authenticated user id (from the kernel Identity), falling back to client IP.
 *
 * Registered at the `after.load` slot: hooked stages are built with `new` from
 * the CoreContainer, so the CachePort is resolved from the request-scoped
 * ModuleContainer (available once LoadStage has run) instead of constructor DI.
 */
final class ApiRateLimitStage implements HttpStageContract
{
    public function handle(Request $request, callable $next): Response
    {
        $declared = in_array('throttle', (array) $request->attribute('active_filters'), true);

        if ($declared) {
            // "throttle:MAX,MINUTES" — args parsed by RouteFilterStage.
            $args   = (array) ($request->attribute('filter_args')['throttle'] ?? []);
            $max    = max(1, (int) ($args[0] ?? 60));
            $window = max(1, (int) ($args[1] ?? 1)) * 60;   // minutes → seconds
            // Per-route bucket: method + matched route pattern (not the concrete
            // path, so /users/{id} shares one bucket across ids).
            $scope  = $this->routeScope($request);
        } else {
            $prefix = (string) (env('RATE_LIMIT_PREFIX') ?: '/api');
            if (!str_starts_with($request->path(), $prefix)) {
                return $next($request);
            }
            $max    = max(1, (int) (env('RATE_LIMIT_MAX') ?: 300));
            $window = max(1, (int) (env('RATE_LIMIT_WINDOW') ?: 60));
            $scope  = 'global';
        }

        $cache = $this->resolveCache($request);
        if ($cache === null) {
            // No cache available — fail open rather than block traffic.
            return $next($request);
        }

        $key     = 'rl_' . hash('sha256', $scope . '|' . $this->resolveIdentity($request));
        $current = (int) ($cache->get($key) ?? 0);

        if ($current >= $max) {
            return Response::json([
                'error' => [
                    'code'        => 'rate_limit_exceeded',
                    'message'     => 'Too many requests. Please slow down and try again.',
                    'retry_after' => $window,
                ],
            ], 429, [
                'Retry-After'           => (string) $window,
                'X-RateLimit-Limit'     => (string) $max,
                'X-RateLimit-Remaining' => '0',
            ]);
        }

        if ($current === 0) {
            $cache->set($key, 1, $window);
        } else {
            $cache->increment($key);
        }

        return $next($request)
            ->withHeader('X-RateLimit-Limit', (string) $max)
            ->withHeader('X-RateLimit-Remaining', (string) max(0, $max - $current - 1));
    }

    private function resolveCache(Request $request): ?CachePort
    {
        $container = $request->container();
        if ($container === null || !$container->has(CachePort::class)) {
            return null;
        }
        $cache = $container->make(CachePort::class);
        return $cache instanceof CachePort ? $cache : null;
    }

    /** Per-route bucket key: METHOD + the matched route pattern (falls back to path). */
    private function routeScope(Request $request): string
    {
        $entry = $request->attribute('route_entry');
        $path  = is_array($entry) ? (string) ($entry['path'] ?? $request->path()) : $request->path();

        return 'route_' . $request->method() . ' ' . $path;
    }

    private function resolveIdentity(Request $request): string
    {
        $identity = $request->identity();
        if ($identity !== null && !$identity->isGuest()) {
            return 'user_' . $identity->userId;
        }
        $ip = $request->header('X-Forwarded-For') ?? $request->attribute('client_ip');
        return 'ip_' . ($ip ?: 'unknown');
    }
}
