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
        $this->matcher ??= new RouteMatcher($this->loadManifest('route-manifest.php', []));

        return [
            new ErrorStage($this->errorPipeline), // outermost wrapper
            new CorrelationIdStage(),
            new SecurityStage($this->gateway),
            ...$this->resolveHook('after.security'),
            new ResolveStage($this->matcher),
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
