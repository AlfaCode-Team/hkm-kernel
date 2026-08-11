<?php
declare(strict_types=1);

namespace AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\CoreContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Error\ErrorPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Http\{Request, Response};
use AlfacodeTeam\PhpServicePlatform\Kernel\Loading\{DependencyGraphCalculator, OnDemandLoader};
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Contracts\HttpStageContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\Stages\{
    CorrelationIdStage, SecurityStage, ResolveStage,
    LoadStage, RouteFilterStage, ExecuteStage, ErrorStage
};
use AlfacodeTeam\PhpServicePlatform\Kernel\Routing\RouteIndex;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\SecurityGateway;
use AlfacodeTeam\PhpServicePlatform\Kernel\Support\Paths;

/**
 * HttpPipeline — assembles and runs the complete HTTP request lifecycle.
 *
 * Fixed stages (in order):
 *   1. CorrelationIdStage      — generate/propagate X-Correlation-ID
 *   2. SecurityStage           — run SecurityGateway (pre-bootstrap)
 *      ↳ after.security hooks  — module-registered stages
 *   3. ResolveStage            — match route → service via RouteMatcher
 *   4. LoadStage               — dep graph → OnDemandLoader
 *      ↳ after.load hooks      — module-registered stages
 *   5. ExecuteStage            — resolve controller → run → Response
 *      ↳ after.execute hooks   — module-registered stages
 *   6. ErrorStage (wraps all)  — catch all Throwables → ErrorPipeline
 *
 * Hooks are registered ONCE during module boot() (at kernel build), so the
 * stage list is stable and is compiled exactly once on the first request, then
 * reused. Stages hold no per-request state — safe under OpenSwoole workers.
 */
final class HttpPipeline
{
    /** @var array<string, list<array{priority: int, class: class-string}>> */
    private array $hooks = [
        'after.security' => [],
        'after.load'     => [],
        'after.execute'  => [],
    ];

    // Manifest-backed collaborators are built lazily on first request so a
    // kernel materialized for a non-HTTP surface (CLI/worker) pays no disk I/O
    // for the route/service manifests it will never read.
    private ?DependencyGraphCalculator $calculator = null;
    private ?OnDemandLoader            $loader     = null;
    private ?RouteMatcher              $matcher    = null;

    /** Alias → stage map for route-declared filters (module.json / proj.json). */
    private FilterRegistry $filters;

    /** @var list<HttpStageContract>|null compiled once, reused */
    private ?array $stages = null;

    /**
     * @param list<class-string> $essentialModules cross-cutting modules loaded
     *        into every request container (e.g. session, cookies). See OnDemandLoader.
     */
    public function __construct(
        private readonly SecurityGateway $gateway,
        private readonly CoreContainer   $core,
        private readonly ErrorPipeline   $errorPipeline,
        private readonly array           $essentialModules = [],
    ) {
        $this->filters = new FilterRegistry();
    }

    // ── Hook registration (called from Module::boot()) ────────────────────────

    /**
     * @param string       $slot       'after.security' | 'after.load' | 'after.execute'
     * @param class-string $stageClass Must implement HttpStageContract
     * @param int          $priority   Lower = runs first.
     */
    public function hook(string $slot, string $stageClass, int $priority = 50): void
    {
        if (!isset($this->hooks[$slot])) {
            throw new \InvalidArgumentException("Unknown hook slot: [{$slot}]");
        }
        $this->hooks[$slot][] = ['priority' => $priority, 'class' => $stageClass];
        usort($this->hooks[$slot], static fn($a, $b) => $a['priority'] <=> $b['priority']);
        $this->stages = null; // force recompilation if hooks change before first request
    }

    /**
     * Register a route-filter alias. Routes opt into it by name via their
     * `filters[]` in module.json / proj.json (e.g. "auth", "throttle:60").
     *
     * @param class-string<HttpStageContract> $stageClass
     */
    public function filter(string $alias, string $stageClass): void
    {
        $this->filters->register($alias, $stageClass);
    }

    // ── Request handling ─────────────────────────────────────────────────────

    public function handle(Request $request): Response
    {
        $this->stages ??= $this->buildStages();
        return $this->runPipeline($this->stages, $request);
    }

    /** @return list<HttpStageContract> */
    private function buildStages(): array
    {
        // First real request: load the manifests now (not at construction time).
        $manifest = $this->loadManifest('service-manifest.php', ['services' => []]);
        $this->calculator ??= new DependencyGraphCalculator($manifest);
        $this->loader  ??= new OnDemandLoader($this->core, $this->essentialModules);
        $index = $this->routeIndex();
        $this->matcher ??= RouteMatcher::fromCompiled(
            $index,
            self::flag('ROUTE_HEAD_FALLBACK', true),
            self::policy(),
        );

        if (self::flag('ROUTE_STRICT_FILTERS', true)) {
            $this->assertFiltersRegistered(RouteIndex::entries($index));
        }

        return [
            new ErrorStage($this->errorPipeline), // outermost wrapper
            new CorrelationIdStage(),
            new SecurityStage($this->gateway),
            ...$this->resolveHook('after.security'),
            new ResolveStage($this->matcher, self::flag('ROUTE_METHOD_NOT_ALLOWED', false)),
            new LoadStage($this->calculator, $this->loader, self::essentialDomains($manifest, $this->essentialModules)),
            ...$this->resolveHook('after.load'),
            new RouteFilterStage($this->filters, $this->core),
            new ExecuteStage(),
            ...$this->resolveHook('after.execute'),
        ]; 
    }

    /** @return list<HttpStageContract> */
    private function resolveHook(string $slot): array
    {
        return array_map(
            fn(array $h): HttpStageContract => $this->core->has($h['class'])
                ? $this->core->make($h['class'])
                : new $h['class'](),
            $this->hooks[$slot],
        );
    }

    /** @param list<HttpStageContract> $stages */
    private function runPipeline(array $stages, Request $request): Response
    {
        $index = 0;
        $next  = function (Request $req) use (&$stages, &$index, &$next): Response {
            if ($index >= count($stages)) {
                return Response::empty(200);
            }
            $stage = $stages[$index++];
            return $stage->handle($req, $next);
        };

        return $next($request);
    }

    /**
     * @param mixed $default
     * @return array<string, mixed>
     */
    private function loadManifest(string $file, array $default): array
    {
        $path = Paths::cache('manifests/' . $file);
        if (!is_file($path)) {
            return $default;
        }
        $data = require $path;
        return is_array($data) ? $data : $default;
    }

    /**
     * Prefer `route-index.php` — the matcher-ready index the boot compiler built,
     * which removes per-worker regex construction entirely. A deploy whose cache
     * predates that file falls back to deriving the index from the flat manifest,
     * so an un-recompiled application still serves.
     *
     * @return array<string, mixed>
     */
    private function routeIndex(): array
    {
        $index = $this->loadManifest('route-index.php', []);

        if (isset($index['static']) || isset($index['dynamic'])) {
            return $index;
        }

        return RouteIndex::build($this->loadManifest('route-manifest.php', []));
    }

    /**
     * Every filter alias a route names must have been registered by some
     * Provider::boot(). Checked once, here, because this runs AFTER module boot
     * (the compiler cannot know the aliases yet) and BEFORE the first request —
     * turning a per-request 500 on an unreachable page into a startup failure
     * that names the route.
     *
     * Set ROUTE_STRICT_FILTERS=false to fall back to the previous behaviour (the
     * unknown alias throws when that one route is requested). The escape hatch
     * exists because this check runs for the WHOLE table: an application that has
     * been quietly serving with one mis-declared filter on a page nobody visits
     * should be able to deploy the upgrade first and fix the route second.
     *
     * @param array<string, array<string, mixed>> $entries
     */
    private function assertFiltersRegistered(array $entries): void
    {
        foreach ($entries as $key => $entry) {
            $specs = $entry['filter_specs'] ?? null;
            $specs = is_array($specs)
                ? array_column($specs, 'alias')
                : array_map(
                    static fn($f): string => explode(':', trim((string) $f), 2)[0],
                    is_array($entry['filters'] ?? null) ? $entry['filters'] : [],
                );

            foreach ($specs as $alias) {
                if ($alias === '' || $this->filters->has($alias)) {
                    continue;
                }

                throw new \InvalidArgumentException(sprintf(
                    'Route [%s] declares filter [%s], which no Provider::boot() registered. '
                    . 'Registered aliases: %s. Register it with $http->filter() in the plugin that '
                    . 'provides it, or remove it from the route.',
                    $key,
                    $alias,
                    $this->filters->aliases() === [] ? '(none)' : implode(', ', $this->filters->aliases()),
                ));
            }
        }
    }

    /** Read a boolean env flag once, at pipeline build. */
    private static function flag(string $key, bool $default): bool
    {
        $value = \function_exists('env') ? env($key) : null;

        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /** ROUTE_TRAILING_SLASH: strict (default) | ignore | redirect. */
    private static function policy(): string
    {
        $value = \function_exists('env') ? strtolower(trim((string) (env('ROUTE_TRAILING_SLASH') ?? ''))) : '';

        return match ($value) {
            RouteMatcher::TRAILING_IGNORE   => RouteMatcher::TRAILING_IGNORE,
            RouteMatcher::TRAILING_REDIRECT => RouteMatcher::TRAILING_REDIRECT,
            default                         => RouteMatcher::TRAILING_STRICT,
        };
    }

    /**
     * Map the essential module classes to their solves domains via the compiled
     * service manifest, so LoadStage can seed them (and thus their transitive
     * requires) into every request's dependency graph. An essential not present
     * in the manifest (not in withModules) is skipped — OnDemandLoader still
     * registers it standalone, preserving the previous behaviour.
     *
     * @param array{services?: array<string, array<string, mixed>>} $manifest
     * @param list<class-string> $essentialModules
     * @return list<string>
     */
    private static function essentialDomains(array $manifest, array $essentialModules): array
    {
        if ($essentialModules === []) {
            return [];
        }

        $wanted = array_flip($essentialModules);
        $domains = [];
        foreach ($manifest['services'] ?? [] as $domain => $entry) {
            if (isset($wanted[$entry['module'] ?? ''])) {
                $domains[] = $domain;
            }
        }

        return $domains;
    }
}
